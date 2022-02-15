<?php
/**
 * Checks email via POP and creates requests from mail fetched.
 */
class helpdeskEmailSourceType extends helpdeskCommonST implements helpdeskCronSTInterface, helpdeskFrontendSTInterface
{
    public function init()
    {
        $this->name = _w('Email');
    }

    //
    // Mail checking logic
    //

    public function cronJob(helpdeskSource $source)
    {
        $interval = $source->params->ifset('check_interval', 57);
        if ($interval <= 0) {
            // turned off
            return false;
        }

        $t = $source->params->ifset('last_timestamp', 0);
        if (time() - $t <= $interval) {
            // checked too recently
            return false;
        }

        // update time of the last mail check immidiately because it may be too late
        // e.g. if waMailPOP3 constructor fails with an exception
        // (saving it through the model out of paranoia: less things to get broken)
        $spm = new helpdeskSourceParamsModel();
        $spm->updateLastDatetime($source->id);
        $source->params->last_timestamp = time(); // keep in sync with the DB

        $workflow_id = $source->params->ifset('workflow', 0);
        $wf = helpdeskWorkflow::getWorkflow($workflow_id); // make sure it exists before we start

        $mail_reader = new waMailPOP3($source['params']->toArray());
        $n = $mail_reader->count();
        if (!$n || !$n[0]) {
            // no new messages
            $mail_reader->close();
            return true;
        }

        $temp_path = wa('helpdesk')->getTempPath('mail', 'helpdesk');

        for ($i = 1; $i <= $n[0]; $i++) {

            $unique_id = uniqid(true);
            $cron_job_log_filename = $unique_id . '.log';

            $this->logCronJob("Start cycle. Iteration step = {$i}", $cron_job_log_filename);

            // This ensures that two checking loops won't run simultaneously
            if (time() > $source->params->last_timestamp + $interval/5) {
                $spm->updateLastDatetime($source->id);
                $source->params->last_timestamp = time(); // keep in sync with the DB
            }

            $message = null;
            $message_id = null;
            $mail_path = $temp_path.'/'.$unique_id;
            waFiles::create($mail_path);

            $this->logCronJob("Create path: {$mail_path}", $cron_job_log_filename);

            try {

                $this->logCronJob("Try mail reader get mail: {$mail_path}", $cron_job_log_filename);

                // read mail to temporary file
                $mail_reader->get($i, $mail_path.'/mail.eml');

                $this->logCronJob("Try process eml", $cron_job_log_filename);

                // Process the file
                $this->processEml($source, $mail_path.'/mail.eml', $message, $cron_job_log_filename);

                $this->logCronJob("Try delete mail path: {$mail_path}", $cron_job_log_filename);

                // Clean up
                waFiles::delete($mail_path);
            } catch (Exception $e) {

                if ($message) {
                    $message_id = $message['message_id'];
                }
                $msg = $e->getMessage();

                $this->logCronJob("Catch exception: {$msg}", $cron_job_log_filename);

                if (strpos($msg, 'Duplicate entry') !== false) {
                    // Message with this message_id already exists.
                    // Delete from mailbox, write to log and forget it (blindly hoping it wasn't a collision).
                    $msg = 'Unable to save message '.$message_id.' from source '.$source->name.': '.strstr($msg, 'Duplicate entry');
                } else {
                    if (waSystemConfig::isDebug()) {
                        echo $e;
                    }

                    // Save the mail file for later inspectation
                    $trouble_path = null;
                    if (is_readable($mail_path.'/mail.eml')) {
                        $trouble_filename = md5(ifset($message_id, uniqid('77*77asdf'))).'.eml';
                        $trouble_path = wa()->getDataPath('requests/trouble/'.$trouble_filename, false, 'helpdesk');
                        waFiles::move($mail_path.'/mail.eml', $trouble_path);
                    }

                    $msg = "\n==================================================================================\n".
                        'Unable to save message '.$message_id.' from source '.$source->name.': '.$msg;
                    if ($trouble_path) {
                        $msg .= "\nFile: ".$trouble_path;
                    }
                    if ($message) {
                        $message['source'] = $message['source']->id;
                        $msg .= "\nMessage: ".print_r($message, true);
                    }
                    $msg .= "\n==================================================================================";
                }
                waLog::log($msg, 'helpdesk.log');
            }

            // remove processed mail message from the mailbox
            try {
                $mail_reader->delete($i);
            } catch (Exception $e) {
                waLog::log('Unable to delete message from mailbox '.$source->name.': '.$e->getMessage(), 'helpdesk.log');
            }
        }
        $mail_reader->close();
        return true;
    }

    protected function logCronJob($message, $filename)
    {
        static $first_run = true;

        if (!wa()->getConfig('helpdesk')->getOption('email_cron_job_logs')) {
            return;
        }

        $week = date('W');
        waLog::log($message, 'helpdesk/email_cron_job_logs/' . $week . '/' . $filename);
        if ($first_run) {
            $path = waConfig::get('wa_path_log');
            if (!$path) {
                $path = dirname(dirname(dirname(__FILE__)));
            }
            $path .= '/helpdesk/email_cron_job_logs/';
            foreach (waFiles::listdir($path, false) as $dir) {
                if (is_numeric($dir)) {
                    $w = (int) $dir;
                    if ($w != (int)$week && $w != (int)$week - 1) {
                        waFiles::delete($path . $dir, true);
                    }
                }
            }
            $first_run = false;
        }



    }


    /**
     * cronJob() helper.
     * Decodes one mail.eml file, creates message and calls handleMessage().
     * Throws exceptions when something's wrong.
     * Declared public for easier testing.
     */
    public function processEml(helpdeskSource $source, $eml_file_path, &$message, $cron_job_log_filename)
    {
        static $mail_decode = null;
        if (empty($mail_decode)) {
            $mail_decode = new waMailDecode();
        }

        $this->logCronJob("Try decode email: {$eml_file_path}", $cron_job_log_filename);

        // decode email
        $mail = $mail_decode->decode($eml_file_path);

        // !!! TODO: check for special header added by helpdesk to avoid infinite loops.
        // Add such header to all automatic emails, such as antispam confirmations.

        $this->logCronJob("Try collect attachments", $cron_job_log_filename);

        // update attachment file path to contain full path
        $attachments = array();
        if (isset($mail['attachments']) && $mail['attachments']) {
            $mail_path = dirname($eml_file_path);
            foreach ($mail['attachments'] as $a) {
                $attachments[] = array(
                    'file' => $mail_path.'/files/'.$a['file'],
                    'name' => ifempty($a['name'], $a['file']),
                    'cid' => ifempty($a['content-id']),
                );
            }
        }

        $this->logCronJob("Try get message id. Mail headers are: " . var_export($mail['headers'], true), $cron_job_log_filename);

        // Fetch message_id
        $message_id = $mail_decode->getMessageId($mail['headers']);
        if (empty($message_id)) {
            $message_id = $source['id'].'/'.md5(http_build_query($mail['headers']).ifset($mail['text/html']).ifset($mail['text/plain']));
        } else {
            $message_id = $source['id'].'/'.$message_id;
        }

        $this->logCronJob("Message id is " . var_export($message_id, true), $cron_job_log_filename);

        $this->logCronJob("Try prepare text", $cron_job_log_filename);

        // Message body: try HTML, then use plain if no HTML found
        $text = ifset($mail['text/html'], '');
        if(empty($text)) {
            $text = nl2br(htmlspecialchars(ifset($mail['text/plain'], '')));
        }

        $message = array(
            'source' => $source,
            'message_id' => $message_id,
            'created' => $mail['headers']['date'],
            'params' => array(),
            'attachments' => $attachments,
            'assets' => array(
                'mail.eml' => $eml_file_path,
            ),
            'client_contact' => null, // see below
            'creator_contact' => null, // see below
            'summary' => $mail['headers']['subject'],
            'creator_type' => 'public',
            'text' => $text,
            'data' => array(),
        );

        // look up contact by email
        $email = !empty($mail['headers']['reply-to']['email']) ?
            $mail['headers']['reply-to']['email'] : ifset($mail, 'headers', 'from', 'email', '');
        if ($email) {
            $name = !empty($mail['headers']['reply-to']['name']) ?
                $mail['headers']['reply-to']['name'] : ifset($mail, 'headers', 'from', 'name', '');

            $this->logCronJob("Try find contact by email", $cron_job_log_filename);

            $message['data']['c_email'] = $email;
            $message['data']['c_name'] = $name;
        }

        $this->logCronJob("Try to handle message", $cron_job_log_filename);

        // pass message to source action
        $this->handleMessage($message);
    }

    /**
     * Find contact by email
     * @param string $email
     * @param numeric|null $main_candidate - if by email founded many contacts, prefer main_candidate
     * @return null|waContact
     */
    private function findContactByEmail($email, $main_candidate = null)
    {
        $col = new waContactsCollection('search/email=' . $email);
        $contacts = $col->getContacts('*');
        if (!$contacts) {
            return null;
        }
        if ($main_candidate && isset($contacts[$main_candidate])) {
            $contact = new waContact($main_candidate);
            if ($contact->exists()) {
                return $contact;
            } else {
                return null;
            }
            return $contact;
        } else {
            reset($contacts);
            $contact_id = key($contacts);
            return new waContact($contact_id);
        }
    }

    protected function handleMessage($message)
    {
        $message = helpdeskSourceHelper::cleanMessage($message);

        $email = ifset($message['data']['c_email'], '');
        $name = ifset($message['data']['c_name'], '');
        if (!$email) {
            return false;
        }

        $default_contact = new waContact();
        $default_contact['email'] = $email;
        $default_contact['name'] = $name;

        $contact = $this->findContactByEmail($email);
        if (empty($contact)) {
            waLog::log("Fail when find by email = {$email}", 'helpdesk/find_contact_by_email.log');
            $contact = $default_contact;
        }

        $message['client_contact'] = $contact;
        if ($message['client_contact'] && $message['client_contact']->get('is_user') == -1) { // this contact is blocked
            return false;
        }

        // Request id in message subject?
        if (!empty($message['summary'])) { // $message['params']['subject']
            $subject = str_replace(array(" ", "\n", "\t", "\r"), '', $message['summary']);
            if (preg_match('~\[ID:([0-9]+)\-([0-9]+)\]~ui', $subject, $match)) {
                try {
                    $message['request'] = new helpdeskRequest($match[1]);

                    // check if request exists
                    $message['request']->summary;

                    if (abs(crc32($message['request']->created)) != $match[2]) {
                        // Not valid CRC32: process email as if there were no ID in subject
                        $message['summary'] = preg_replace('~\s*\[ID:[0-9]+\-[0-9]+\]~ui', '', $message['summary']);
                        unset($message['request'], $message['request_log']);
                    } else {
                        // Process request with ID in subject
                        if ($this->saveExistingRequest($message)) {
                            return true; // else continue
                        }
                    }
                } catch (Exception $e) {
                    // Request does not exist: process email as if there were no ID in subject
                    $message['summary'] = preg_replace('~\[ID:([0-9]+)\-([0-9]+)\]~ui', '', $message['summary']);
                    unset($message['request'], $message['request_log']);
                }
            }
        }

        if (isset($message['request'])) {
            $email = $message['request']->getContactEmailFromData();
            $contact = $this->findContactByEmail($email, $message['request']->client_contact_id);
            $message['client_contact'] = $contact;
        }

        if (isset($message['request'])) {
            $message['client_contact']['name'] = $message['request']->getContactNameFromData();
        }

        // this contact is blocked
        if ($message['client_contact'] && $message['client_contact']->get('is_user') == -1) {
            return false;
        }

        // Save client contact
        if (empty($message['source']->params->antispam)) {
            $this->saveClientContact($message);
        }

        // Process email with no ID in subject
        $message['request'] = helpdeskSourceHelper::createRequest($message);
        if (!empty($message['source']->params->antispam) && !$message['client_contact']->getId()) {
            // Antispam feature for unknown contacts: verify email address
            $this->triggerAntispam($message);
        } else {
            // Create new request from message
            $this->saveNewRequest($message);
        }

        return true;

    }

    /** handleMessage() helper. Called on messages with existing request_ids in their subject. */
    protected function saveExistingRequest($message)
    {
        $source = $message['source'];
        $request = $message['request'];

        // Determine who sent the message: client, user, assigned user, or another contact.
        $message_sender = 'other';
        if (!empty($message['client_contact']) && $message['client_contact']->getId()) {
            if ($request['client_contact_id'] == $message['client_contact']->getId()) {
                $message_sender = 'client';
            } else if ($request['assigned_contact_id'] == $message['client_contact']->getId()) {
                $message_sender = 'assigned';
            } else if ($message['client_contact']['is_user'] > 0) {
                $message_sender = 'user';
            }

            // Is sender a member of assigned group?
            if ($message_sender == 'user' && $request['assigned_contact_id'] < 0) {
                $ugm = new waUserGroupsModel();
                if (in_array(-$request['assigned_contact_id'], $ugm->getGroupIds($message['client_contact']->getId()))) {
                    $message_sender = 'assigned';
                }
            }
        }

        // Specific workflow action configured for current state_id and sender combination.
        $action_id = self::getActionWithExistingRequest($source, $request, $message_sender);


        // Make sure there's a workflow action configured
        if ((!empty($source->params->reply_to_reply) && $source->params->reply_to_reply == 'default') || !$action_id) {
            // No specific action set up for this state_id and sender combination.
            // If configured, process this email as if there were no ID in subject.

            $this->saveClientContact($message);

            $r = $message['request'];
            $source = $message['source'];

            // Prepare request_log as a part of action parameters
            $log = wao(new helpdeskRequestLog())->setAll(array(
                'action_id' => '!email',
                'request_id' => $r['id'],
                'after_state_id' => $r['state_id'],
                'before_state_id' => $r['state_id'],
                'actor_contact_id' => $message['client_contact']->getId(),
                'text' => $message['text'],
            ));
            // Save the request history record
            $log->params->via_source_id = $source->getId();

            if (!empty($message['attachments'])) {
                $log->attachments = $message['attachments'];
            }

            $log->request_id = $r->getId();
            $log->save();

            // Send messages to following contacts recipients
            $to = $messages = array();
            $fm = new helpdeskFollowModel();
            $unread = array();
            foreach ($fm->getFollowingContacts($request->id) as $c_id) {
                $to[$c_id] = 1;
                $unread[] = array(
                    'contact_id' => $c_id,
                    'request_id' => $request->id,
                );
            }
            if ($to) {
                $sender = new helpdeskSendMessages();
                $messages[] = array(
                    'tmpl' => $sender->getBccTemplate(),
                    'to' => $to
                );
            }
            if ($messages) {
                $sender = new helpdeskSendMessages();
                $sender->sendMessages($request, $log, $messages);
            }
            $rm = new helpdeskRequestModel();
            $rm->updateById($log->request_id, array('updated' => date('Y-m-d H:i:s')));

            $um = new helpdeskUnreadModel();
            $um->multipleInsert($unread);

            return true;
        }


        // First of all, check if message with specified message_id is already processed.
        // This could happen if same email message is fetched from the mailbox multiple times.
        if ($message['message_id']) {
            $m = new waModel();
            if ($m->query("SELECT id FROM helpdesk_request_log WHERE message_id=?", (string) $message['message_id'])->fetchField()) {
                return true;
            }
        }

        // Save client contact
        $this->saveClientContact($message);

        // Prepare request_log as a part of action parameters
        $log = wao(new helpdeskRequestLog())->setAll(array(
            'action_id' => $action_id,
            'request_id' => $request['id'],
            'after_state_id' => $request['state_id'],
            'before_state_id' => $request['state_id'],
            'actor_contact_id' => $message['client_contact']->getId(),
            'text' => $message['text'],
        ));
        foreach(array('message_id', 'attachments', 'assets', 'params') as $key) {
            if (isset($message[$key]) && $message[$key]) {
                $log[$key] = $message[$key];
            }
        }

        // Assign contact if configured
        if (!empty($source->params->old_request_assign_contact_id) && wa_is_int($source->params->old_request_assign_contact_id)) {
            $log->assigned_contact_id = $source->params->old_request_assign_contact_id;
        }

        // Run the action
        try {
            $request->getWorkflow()->getActionById($action_id)->run(array(
                'request' => $request,
                'request_log' => $log,
                'do_not_save' => true,
            ));
        } catch (Exception $e) {
            $log->params->error_message = $e->getMessage();
        }

        // Save the request history record
        $log->params->via_source_id = $source->getId();
        helpdeskHelper::saveRequestLog($request, $log);

        return true;
    }

    protected static function getActionWithExistingRequest($source, $request, $message_sender)
    {
        $action = null;
        $state_id = $request->state_id;
        $workflow_id = $request->workflow_id;
        if ($workflow_id) {
            $state_id = $workflow_id . '@' . $state_id;
        }
        if (!empty($source->params->actions_with_existing_request[$state_id][$message_sender])) {
            $action = $source->params->actions_with_existing_request[$state_id][$message_sender];
        } elseif (!empty($source->params->actions_with_existing_request[$state_id]['any'])) {
            $action = $source->params->actions_with_existing_request[$state_id]['any'];
        } else if (!empty($source->params->actions_with_existing_request[$request->state_id][$message_sender])) {
            $action = $source->params->actions_with_existing_request[$request->state_id][$message_sender];
        } elseif (!empty($source->params->actions_with_existing_request[$request->state_id]['any'])) {
            $action = $source->params->actions_with_existing_request[$request->state_id]['any'];
        }
        return $action;
    }

    //
    // End of mail checking logic
    //

    //
    // Settings editor logic
    //

    protected function settingsPrepareView($submit_errors, $source, $wf)
    {
        parent::settingsPrepareView($submit_errors, $source, $wf);
        $this->assignActionsWithExistingRequestSettings($wf, $source);
    }

    protected function assignActionsWithExistingRequestSettings(helpdeskWorkflow $wf = null, helpdeskSource $source = null)
    {
        $actions_with_existing_request = array();

        $wfs = helpdeskWorkflow::getWorkflows();
        foreach ($wfs as $_id => $_wf) {
            $workflows[$_id] = $_wf->getName();
        }
        asort($workflows);
        $workflows = array($wf->getId() => $wf->getName()) + $workflows;

        foreach ($workflows as $_id=>$wf_name) {
            $_wf = $wfs[$_id];

            foreach ($_wf->getAllStates() as $sid => $state) {
                $source_params_actions = (isset($source->params->actions_with_existing_request["{$_id}@{$sid}"])) ?
                    $source->params->actions_with_existing_request["{$_id}@{$sid}"] : $source->params->actions_with_existing_request[$sid];

                $actions_with_existing_request[$_id][$sid] = array(
                    'state_id' => $sid,
                    'workflow_id' => $_id,
                    'state_name' => $state->getName(),
                    'available_actions' => array(),
                    'actions' => $source_params_actions,
                    'list_row_css' => $state->getOption('list_row_css'),
                    's' => $state,
                );
                foreach ($state->getActions() as $aid => $action) {
                    $actions_with_existing_request[$_id][$sid]['available_actions'][$aid] = $action;
                }
            }
        }
        $this->view->assign('actions_with_existing_request', $actions_with_existing_request);
        $this->view->assign('workflows', $workflows);
    }

    protected function postToSource(helpdeskSource $source)
    {
        parent::postToSource($source);

        $params = waRequest::post('params', array(), 'array');

        if (empty($source->params->error_datetime)) {
            if (empty($params['check_interval'])) {
                $source->params->error_datetime = date('Y-m-d H:i:s');
            }
        }

        if (!empty($params['email'])) {
            $source->name = $params['email'];
        }
    }

    /** Validation helper for editorSubmit() and prepareEditorView(). */
    protected function settingsValidationErrors($source)
    {
        $errors = array();

        $source_params = waRequest::post('params');
        $params = array();
        if ($source_params && is_array($source_params)) {
            $params = $source_params;
        }

        if(!empty($params['check_interval'])) {
            foreach(array('server','email','port','login') as $p) {
                if(empty($params[$p])) {
                    $errors['params['.$p.']'] = _ws('This field is required.');
                }
            }

            // Try to connect using given settings
            if (!$errors) {
                try {
                    if (empty($params['password'])) {
                        $params['password'] = $source->params->ifset('password', '');
                    }

                    // Check if SSL is supported
                    if (!defined('OPENSSL_VERSION_NUMBER') && !empty($params['ssl'])) {
                        $errors['params[ssl]'] = _w('Encryption requires OpenSSL PHP module to be installed.');
                    } else {
                        $mail_reader = new waMailPOP3($params);
                        $mail_reader->count();
                        $mail_reader->close();

                        // This source seems fine now, remove the error flag if it is set
                        if ($source && $source->id && !empty($source->params->error_datetime)) {
                            unset($source->params->error_datetime);
                        }
                    }
                } catch (Exception $e) {
                    $err = $e->getMessage();
                    if (!$err || $err == ' ()') {
                        $err = _w('Unknown error.');
                    } else if (FALSE !== strpos($err, 'IMAP')) {
                        $err = _w('IMAP is not supported. Please use POP3.');
                    }
                    $errors[''] = _w('An error occurred while attempting to connect with specified settings.').' '.$err;
                }
            }
        }

        return $errors;
    }

    //
    // End of settings editor logic
    //

    protected function buildNewSource()
    {
        $source = parent::buildNewSource();
        $source->params->setAll(array(
            'email' => '',
            'server' => '',
            'port' => '110',
            'login' => '',
            'password' => '',
            'ssl' => '',
            'check_interval' => '57',
        ));
        return $source;
    }

    public function getRequestParams()
    {
        return array();
    }
}
