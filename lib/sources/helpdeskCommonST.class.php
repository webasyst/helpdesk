<?php
/**
 * Helper base class for source types.
 * Implements common logic like antispam.
 */
abstract class helpdeskCommonST extends helpdeskSourceType
{
    //
    // Message processing logic (request creation, assignment, etc)
    //

    /**
     * Message processing entrance point.
     * Common logic to be used in subclasses.
     */
    protected function handleMessage($message)
    {
        $message = helpdeskSourceHelper::cleanMessage($message);
        $message['request'] = helpdeskSourceHelper::createRequest($message);

        if (!empty($message['source']->params->antispam) && !wa()->getUser()->isAuth()) {
            // && (empty($message['client_contact']) || !$message['client_contact']->getId())) {
            // Antispam feature for unknown contacts: verify email address
            $this->triggerAntispam($message);
        } else {
            // Create new request from message
            $this->saveNewRequest($message);
        }
    }

    /**
     * Helper to serialize message into string to save to helpdesk_temp table.
     */
    protected function serializeMessage($message)
    {
        // Replace source, request and contact objects with ids or data
        $message['source'] = $message['source']->getId();
        $message['request'] = $message['request']->ifempty('id');
        foreach(array('client_contact', 'creator_contact') as $p) {
            if (!empty($message[$p])) {
                if ($message[$p]->getId()) {
                    $message[$p] = $message[$p]->getId();
                } else if ($p == 'client_contact') {
                    $message[$p] = $message[$p]->load();
                } else {
                    unset($message[$p]);
                }
            }
        }

        // Serialize attachments and assets. File contents are stored inside LONGBLOB in DB.
        foreach(array('attachments', 'assets') as $p) {
            if (empty($message[$p]) || !is_array($message[$p])) {
                $message[$p] = array();
                continue;
            }

            foreach($message[$p] as $i => &$f) {
                if (!is_array($f)) {
                    $f = array(
                        'file' => $f,
                        'name' => basename($f),
                    );
                }
                if (is_readable($f['file'])) {
                    $f['contents'] = file_get_contents($f['file']);
                } else {
                    unset($message[$p][$i]);
                }
            }
            unset($f);
        }

        // Being paranoid: make sure everything is serializable in $message
        array_walk_recursive($message, wa_lambda('$a', 'if (is_object($a) && !$a instanceof Serializable) { throw new waException("Unable to serialize message as it contains ".get_class($a)); }'));

        return serialize($message);
    }

    /**
     * Reverse of $this->serializeMessage()
     */
    protected function unserializeMessage($msg_str)
    {
        $message = @unserialize($msg_str);
        if (!$message || !is_array($message)) {
            return null;
        }

        // Attachments and assets
        foreach(array('attachments', 'assets') as $p) {
            if (empty($message[$p]) || !is_array($message[$p])) {
                $message[$p] = array();
                continue;
            }
            foreach($message[$p] as $i => &$f) {
                $filepath = wa()->getTempPath('temps');
                waFiles::create($filepath);
                $filepath = tempnam($filepath, 'a');
                if (!is_array($f) || !isset($f['contents']) || !is_writable($filepath)) {
                    unset($message[$p][$i]);
                    continue;
                }

                if (!file_put_contents($filepath, $f['contents'])) {
                    unset($message[$p][$i]);
                    continue;
                }

                unset($f['contents']);

                if ($p == 'assets') {
                    $f = $filepath;
                } else {
                    $f['file'] = $filepath;
                }
            }
            unset($f);
        }

        // Contacts
        foreach(array('client_contact', 'creator_contact') as $p) {
            if (!empty($message[$p]) && is_array($message[$p])) {
                $c = new waContact();
                foreach($message[$p] as $k => $v) {
                    $c[$k] = $v;
                }
                $message[$p] = $c;
            }
        }

        // Source and request
        $message = helpdeskSourceHelper::cleanMessage($message);
        if (empty($message['request'])) {
            $message['request'] = helpdeskSourceHelper::createRequest($message);
        }

        return $message;
    }

    /** handleMessage() helper. Called on messages from unknown contacts when antispam email confirmation feature is enabled. */
    protected function triggerAntispam($message)
    {
        $source = $message['source'];
        $r = $message['request'];

        // Recipient name and email
        $address = '';
        if (!empty($message['client_contact'])) {
            $address = $message['client_contact']->get('email', 'default');
        } else {
            $address = $r->getContactEmailFromData();
        }

        $name = '';
        if (!empty($message['client_contact'])) {
            $name = $message['client_contact']->getName();
        } else {
            $name = $r->getContactNameFromData();
        }
        if (!$address) {
            // No email address. Too bad.
            return;
        }

        // Make sure we don't have an email source with email we're going to send confirmation to.
        // Otherwise this may create an infinite loop.
        $spm = new helpdeskSourceParamsModel();
        if ($spm->sourceIdByEmail($address)) {
            waLog::log('Unable to send antispam-confirmation to email source address: '.$address.' (message is ignored, most likely spam; add this email to a contact database if you want to receive such requests)', 'helpdesk.log');
            return;
        }

        $message['confirmation_hash'] = md5('Qu,h`j&`&u#GLlZ$/.`+'.mt_rand().mt_rand().mt_rand());

        // Default antispam implementation is unable to handle very large messages
        $data = $this->serializeMessage($message);
        if (strlen($data) > 2000000) {
            unset($message['confirmation_hash']);
            $this->saveNewRequest($message);
            return;
        }

        // Save message into temporary table
        $tm = new helpdeskTempModel();
        $temp_id = $tm->insert(array(
            'created' => date('Y-m-d H:i:s'),
            'data' => $data,
        ));

        // Send confirmation email
        $hash = substr($message['confirmation_hash'], 0, 16).$temp_id.substr($message['confirmation_hash'], -16);
        $confirm_url = wa()->getRouteUrl('helpdesk/frontend/confirm', true).'?source_id='.$source->id.'&confirmation='.$hash;
        $sent = helpdeskHelper::sendEmailHtmlTemplate(array($address => $name), $this->getTemplateFromParam($source, 'antispam_mail_template'), array(
            '{REQUEST_SUBJECT}' => htmlspecialchars($message['summary']),
            '{REQUEST_TEXT}' => helpdeskRequest::formatHTML($message),
            '{CUSTOMER_NAME}' => htmlspecialchars($name),
            '{REQUEST_CONFIRM_URL}' => htmlspecialchars($confirm_url),
            '{COMPANY_NAME}' => htmlspecialchars(wa()->accountName()),
        ));
        if (!$sent) {
            $tm->deleteById($temp_id);
            throw new waException('Unable to send confirmation email.');
        }
    }

    public function savesEmptyContacts()
    {
        return false;
    }

    /** Helper for saveNewRequest() */
    protected function saveClientContact(&$message)
    {
        $new_client = false;

        if (!empty($message['client_contact'])) {
            if (!$message['client_contact']->getId()) {
                if (!$this->savesEmptyContacts()) {
                    // Do we have any meaningful information about this contact?
                    $contact_empty = true;
                    $data = $message['client_contact']->load();
                    foreach(ifempty($data, array()) as $fld_id => $data) {
                        if (!in_array($fld_id, array('firstname', 'middlename', 'lastname', 'name', 'locale'))) {
                            $contact_empty = false;
                            break;
                        }
                    }
                    if ($contact_empty) {
                        $message['client_contact'] = null;
                        return true;
                    }
                }

                $message['client_contact']['create_contact_id'] = 0;
                $message['client_contact']['create_app_id'] = 'helpdesk';
                $message['client_contact']['create_method'] = 'first_request';
                if (empty($message['client_contact']['locale'])) {
                    $message['client_contact']['locale'] = $message['source']->params->ifset('locale', wa()->getLocale());
                }
                $errors = $message['client_contact']->save();
                if ($errors) {
                    $message['request']->params->error_saving_client_contact = $errors;
                    $message['client_contact'] = null;
                    return true;
                }
                $new_client = true;
            }

            // add contact to helpdesk category
            $ccm = new waContactCategoryModel();
            $category = $ccm->getBySystemId('helpdesk');
            if ($category) {
                $ccsm = new waContactCategoriesModel();
                $ccsm->add($message['client_contact']->getId(), $category['id']);
            }
        }

        return $new_client;
    }

    /** handleMessage() helper. Called on messages with no request_id in their subject to create new requests. */
    protected function saveNewRequest($message)
    {
        $r = $message['request'];

        $source = $message['source'];
        if (empty($r->workflow_id)) {
            $r->workflow_id = $source->params->ifset('workflow', helpdeskWorkflow::get()->getId());
        }
        if (empty($r->state_id)) {
            $r->state_id = $source->params->ifset('new_request_state_id');
        }

        // Assign contact if specified in parameters
        if (!empty($source->params->new_request_assign_contact_id) && wa_is_int($source->params->new_request_assign_contact_id)) {
            $r->assigned_contact_id = $source->params->new_request_assign_contact_id;
        }

        // save client contact.
        $this->saveClientContact($message);
        if ($message['client_contact']) {
            $r->client_contact_id = $message['client_contact']->getId();
        } else {
            $r->client_contact_id = 0;
        }

        // save creator contact when specified
        if (!empty($message['creator_contact']) && $message['creator_contact']->getId()) {
            $r->creator_contact_id = $message['creator_contact']->getId();
        } else {
            $r->creator_contact_id = $r->client_contact_id;
        }

        $r->creator_type = $message['creator_type'];

        // save the request
        $r->id = $r->save();
        if (!$r->id) {
            return null;
        }

        if (isset($message['data'])) {
            foreach ($message['data'] as $field_id => $data_val) {
                $r->setField($field_id, $data_val, 0);
            }
        }

        // Send messages
        $this->sendMessages($message);

        // Perform automatic action with the request, if set up
        $this->autoAction($message);

        // Mark request as unread for users
        wao(new helpdeskUnreadModel())->markUnread($r);

        /**
         * @event request_created
         * Notift plugins about new request just created.
         * @param array see helpdeskSourceHelper::cleanMessage()
         */
        wa('helpdesk')->event('request_created', $message);

        helpdeskHelper::logAction('request_created', null, null, $r->creator_contact_id);

        return $r->id;
    }

    /**
     * Helper for saveNewRequest()
     */
    protected function autoAction($message)
    {
        $r = $message['request'];
        $source = $message['source'];
        if (!empty($source->params->new_request_action_id)) {

            // Prepare request_log as a part of action parameters
            $log = wao(new helpdeskRequestLog())->setAll(array(
                'action_id' => $source->params->new_request_action_id,
                'request_id' => $r['id'],
                'after_state_id' => $r['state_id'],
                'before_state_id' => $r['state_id'],
                'actor_contact_id' => $r->creator_contact_id,
            ));

            // Run the action
            try {
                $action = helpdeskWorkflow::get($r->workflow_id)->getActionById($source->params->new_request_action_id);
                $action->setOption('always_triggerable', true);
                $action->run(array(
                    'request' => $r,
                    'request_log' => $log,
                    'do_not_save' => true,
                ));
            } catch (Exception $e) {
                $log->params->error_message = $e->getMessage();
            }

            // Save the request history record
            $log->actor_contact_id = $r->creator_contact_id;
            $log->params->via_source_id = $source->getId();
            helpdeskHelper::saveRequestLog($r, $log);
        }
    }

    protected function sendMessages($message)
    {
        $r = $message['request'];
        $source = $message['source'];

        // Remember locale to switch back later
        $original_locale = wa()->getLocale();

        $messages = array();
        if ($source->params->messages) {
            $messages = $source->params->messages->toArray();
        }
        $sender = new helpdeskSendMessages();

        if ($messages) {
            foreach ($messages as $m) {

                $to = $sender->getMessageRecipients($r, null, $m);

                $tpl = explode('{SEPARATOR}', $m['tmpl']);
                $tpl[0] = !empty($tpl[0]) ? $tpl[0] : helpdeskHelper::getDefaultFrom(
                    (!empty($m['sourcefrom']) && $m['sourcefrom'] == 'sourcefrom') ? $r : null
                );
                $tpl = join('{SEPARATOR}', array_map('trim', $tpl));

                $attachments = $r->attachments->toArray();
                $attachments = !empty($m['add_attachments']) ? $attachments : array();

                $one_click_feedback = new helpdeskOneClickFeedback();

                $client_emails_map = array();
                try {
                    $client = $r->getClient();
                    if ($client->exists()) {
                        $client_emails_map = array_fill_keys($client->get('email', 'value'), true);
                    }
                } catch (Exception $e) {}

                // Email vars (locale dependent)
                $email_vars = array();
                foreach($to as $email => $name)
                {
                    $vars = array(
                        '{REQUEST_HISTORY_CUSTOMER}' => '',
                        '{REQUEST_HISTORY}' => ''
                    );
                    $is_client_email = isset($client_emails_map[$email]);
                    if ($is_client_email) {
                        $this->setLocaleByContact($client, $original_locale);
                    } else {
                        $contact = $this->getContactByEmail($email);
                        $this->setLocaleByContact($contact, $original_locale);
                    }
                    $email_vars[$email] = $this->getReceiptVars($message, $m) + $vars;
                }

                foreach ($to as $email => $name) {

                    $vars = ifset($email_vars[$email], array());

                    $is_client_email = isset($client_emails_map[$email]);

                    // extra vars to mixin
                    $extra_vars = !$is_client_email ? $one_click_feedback->getVarsForNotClient($r) :
                            $one_click_feedback->getVarsForClient($r);

                    if ($is_client_email) {
                        $rdm = new helpdeskRequestDataModel();

                        $hashes = array();
                        $match_fields_map = array_fill_keys($one_click_feedback->getMatchedFields($tpl), true);
                        foreach ($one_click_feedback->getFields($r, array('hash', 'field_id')) as $field_id => $info) {
                            if (!empty($match_fields_map[$field_id])) {
                                $hashes[$info['field_id']] = $info['hash'];
                            }
                        }

                        $rdm->addOneClickFeedbackHashes($r->id, $hashes);
                    }

                    helpdeskHelper::sendEmailHtmlTemplate(array($email => $name), $tpl, $vars + $extra_vars, $attachments);
                }
            }
        }

        $this->setLocale($original_locale);

    }

    private function setLocaleByContact(waContact $contact, $default_locale)
    {
        $locale = $contact->exists() ? $contact->getLocale() : null;
        if (!$locale) {
            $locale = $default_locale;
        }
        $this->setLocale($locale);
    }

    private function getContactByEmail($email)
    {
        $col = new waContactsCollection('search/email=' . $email);
        $contacts = $col->getContacts('*', 0, 1);
        $contact_id = key($contacts);
        $contact = new waContact($contact_id);
        return $contact;
    }

    private function setLocale($locale)
    {
        if ($locale && $locale != wa()->getLocale()) {
            wa()->setLocale($locale);
        }
    }

    /**
     * Helper for sendReceipt()
     */
    private function getReceiptVars($message, $m)
    {
        $r = $message['request'];
        $request_id = $r->getId();

        $customer_name = !empty($message['client_contact']) ? $message['client_contact']->getName() : '';
        if (!$customer_name) {
            $customer_name = $r->getContactNameFromData();
        }

        $customer_email = !empty($message['client_contact']) ? $message['client_contact']->get('email', 'default') : '';
        if (!$customer_email) {
            $customer_email = $r->getContactEmailFromData();
        }

        try {
            $state = $r->getState();
            $request_status = $state->getName();
            $request_status_customer = $state->getOption('customer_portal_name', '');
        } catch (Exception $e) {
            $request_status = $request_status_customer = '';
        }

        $vars = array_map('htmlspecialchars', array(
            '{REQUEST_ID}' => $request_id,
            '{REQUEST_SUBJECT}' => $r->summary,
            '{REQUEST_SUBJECT_WITH_ID}' => trim($r->summary." [ID:{$request_id}-" . abs(crc32($r->created)) . "]"),
            '{REQUEST_BACKEND_URL}' => wa('helpdesk')->getConfig()->getHelpdeskBackendUrl() . '/#/request/' . $request_id . '/',
            '{REQUEST_CUSTOMER_PORTAL_URL}' => wa()->getRouteUrl('helpdesk/frontend/myRequest', array('id' => $request_id), true),
            '{REQUEST_CUSTOMER_CONTACT_ID}' => $r->client_contact_id,
            '{REQUEST_CUSTOMER_EMAIL}' => $customer_email,
            '{CUSTOMER_NAME}' => $customer_name,
            '{CUSTOMER_EMAIL}' => $customer_email,
            '{REQUEST_STATUS}' => $request_status,
            '{REQUEST_STATUS_CUSTOMER}' => $request_status_customer,
            '{COMPANY_NAME}' => wa()->accountName(),
        ));
        $vars['{REQUEST_TEXT}'] = helpdeskRequest::formatHTML($r->toArray());
        $vars += helpdeskSendMessages::getCustomerVars($r);
        $vars += helpdeskSendMessages::getAssigneeVars($r->assigned_contact_id);
        $vars += helpdeskSendMessages::getLocaleStringVars();
        $vars += helpdeskSendMessages::getRequestFieldsVars($r, $m['tmpl']);

        unset($vars['{SEPARATOR}']);

        return $vars;
    }

    public function getTemplateFromParam($source, $p)
    {
        $template = trim($source->params->ifset($p, ''));
        if (!$template) {
            $template = self::getDefaultParam($p);
        }
        if (!$template) {
            $template = '';
        }

        $template = explode('{SEPARATOR}', $template, 3);
        while(count($template) < 3) {
            array_unshift($template, '');
        }

        // If template has no subject or from, use defaults
        if (empty($template[0])) {
            $template[0] = '';
            $from = waMail::getDefaultFrom();
            $name = reset($from);
            $email = key($from);
            if ($email) {
                if ($name) {
                    $template[0] = $name.' <'.$email.'>';
                } else {
                    $template[0] = $email;
                }
            }
        }
        if (empty($template[1])) {
            $template[1] = 'Fwd: {REQUEST_SUBJECT_WITH_ID}';
        }
        foreach($template as &$part) {
            $part = trim($part);
        }
        unset($part);
        return nl2br(implode('{SEPARATOR}', $template));
    }

    // Process antispam email confirmation link
    public function frontendSubmit(helpdeskSource $source)
    {
        // Parameters from GET
        $code = waRequest::get('confirmation');
        if (!$code) {
            throw new waException('Bad code', 404);
        }
        $temp_id = substr($code, 16, -16);
        $hash = substr($code, 0, 16).substr($code, -16);
        if (!$temp_id || !wa_is_int($temp_id)) {
            throw new waException('Bad code', 404);
        }

        // Load temp record
        $tm = new helpdeskTempModel();
        $temp = $tm->getById($temp_id);
        if (!$temp) {
            // User probably followed the link from email the second time.
            throw new waException('Bad code', 404);
        }

        // restore $message and check the code
        $message = $this->unserializeMessage($temp['data']);
        if (!$message) {
            throw new waException('Error unserializing the message', 500);
        }
        if (ifempty($message['confirmation_hash']) !== $hash) {
            throw new waException('Bad code', 404);
        }

        // Make sure initial message is from this source
        if ($source->id != $message['source']->id) {
            throw new waException('Bad code', 404);
        }

        // The code is accepted. Continue processing the message
        // as if no antispam were applied.
        unset($message['confirmation_hash']);

        // Antispam makes client is authorized
        $message['creator_type'] = 'auth';

        if (empty($message['client_contact']) && !empty($message['creator_contact']) && $message['creator_contact']->getId()) {
            $message['client_contact'] = $message['creator_contact'];
        }

        $request_id = $this->saveNewRequest($message);
        $tm->deleteById($temp_id);

        // Redirect to customer portal
        if ($message['client_contact'] && $message['client_contact']->getId()) {

            // Auth user
            wa()->getAuth()->auth(array('id' => $message['client_contact']->getId()));

            // Remember to show notice above request page
            wa()->getStorage()->set('helpdesk/antispam_confirmed/'.$request_id, true);

            // save client contact.
            $this->saveClientContact($message);

            // Redirect to customer portal
            $url = wa()->getRouteUrl('helpdesk/frontend/myRequest', array(
                'id' => $request_id,
            ), true);
            wa()->getResponse()->redirect($url);
            exit; // контрольный в голову

        } else {
            // Anonymous request with antispam confirmation by email?
            // Well, that's fancy.
            echo _w('Your request is confirmed.');
            exit;
        }
    }

    //
    // End of message processing logic
    //

    //
    // Settings editor logic
    //

    /**
     * Interface function for workflow editor.
     * Returns HTML form for given source's settings.
     *
     * If data came via POST, saves them to database.
     * In this case may return '' to close the editor and return to workflow page.
     *
     * @param helpdeskWorkflow $wf existing workflow this editor is caled in context of (may be null in exotic cases when source uses several workflows)
     * @param helpdeskSource $source existing source to edit; omit to show empty (creation) form
     * @return string HTML
     */
    public function settingsController(helpdeskWorkflow $wf = null, helpdeskSource &$source = null)
    {
        $source || $source = $this->getNewSource();

        $submit_errors = array();
        if (waRequest::post()) {
            $this->postToSource($source);
            $submit_errors = $this->settingsValidationErrors($source);
            if (!$submit_errors) {
                $source->save();
            }
        }

        // No POST came or validation failed.
        // In both cases, show the form.
        $this->view = null;
        $this->getView();
        $this->settingsPrepareView($submit_errors, $source, $wf);
        return $this->display('settings');
    }

    /**
     * Helper for settingsController()
     * Assign vars into $this->view for settings form template.
     */
    protected function settingsPrepareView($submit_errors, $source, $wf)
    {
        // Make sure we know the workflow
        if (!$wf && !empty($source->params->workflow)) {
            $wf = helpdeskWorkflow::getWorkflow($source->params->workflow);
        }
        if (!$wf) {
            $wf = helpdeskWorkflow::get();
        }

        $wf_states = $wf->getAllStates();
        $wf_actions = array();
        foreach ($wf_states as $state) {
            foreach ($state->getActions() as $aid => $action) {
                $wf_actions[$state->getId()][$aid] = array(
                    'name' => $action->getName(),
                );
            }
        }

        // Default templates
        foreach(array('antispam_mail_template') as $p) {
            $value = $source->params->ifset($p);
            if (empty($value)) {
                $value = self::getDefaultParam($p);
            }
            $this->view->assign($p, $value);
        }
        $messages = null;
        if ($source->params->messages) {
            $messages = $source->params->messages->toArray();
            foreach ($messages as $i=>$m) {
                if (!empty($m['to']) && is_array($m['to'])) {
                    foreach ($m['to'] as $id => $val) {
                        if (is_numeric($id)) {
                            $c = new waContact($id);
                            if ($c->exists()) {
                                $messages[$i]['to'][$id] = $c->getName();
                            } else {
                                $messages[$i]['to'][$id] = _w('Unknown contact') . ':' . $id;
                            }
                        } else if (strpos($id, '@') > 0) {
                            $messages[$i]['to'][$id] = $id;
                        }
                    }
                }
            }
        }

        $this->view->assign(array(
            'wf' => $wf,
            'source' => $source,
            'wf_states' => $wf_states,
            'wf_actions' => $wf_actions,
            'submit_errors' => $submit_errors,
            'assignees' => helpdeskHelper::getAssignOptions($wf->getId()),
            'antispam_mail_template_vars' => helpdeskHelper::categorizeVars($this->getAntispamMailTemplateVars()),
            'receipt_template_vars' => helpdeskHelper::categorizeVars($this->getReceiptTemplateVars($wf->getId())),
            'oneclick_feedback_fields' => helpdeskWorkflow::getOneClickFeedbackFields(true),
            'messages' => $messages,
        ));
    }

    protected function getAntispamMailTemplateVars()
    {
        return helpdeskHelper::getVars(array(
            '{REQUEST_SUBJECT}',
            '{REQUEST_TEXT}',
            '{REQUEST_CONFIRM_URL}',
            '{CUSTOMER_NAME}',
            '{COMPANY_NAME}',
        )) + helpdeskRequestFields::getFieldsVars() + helpdeskHelper::getContactFieldsVars();
    }

    protected function getReceiptTemplateVars($workflow_id = null)
    {
        return helpdeskHelper::getVars(array(
            '{REQUEST_ID}',
            '{REQUEST_SUBJECT}',
            '{REQUEST_SUBJECT_WITH_ID}',
            '{REQUEST_TEXT}',
            '{REQUEST_STATUS}',
            '{REQUEST_STATUS_CUSTOMER}',
            '{REQUEST_HISTORY}',
            '{REQUEST_HISTORY_CUSTOMER}',
            '{REQUEST_BACKEND_URL}',
            '{REQUEST_CUSTOMER_PORTAL_URL}',
            '{REQUEST_CUSTOMER_CONTACT_ID}',
            '{REQUEST_CUSTOMER_EMAIL}',
            '{CUSTOMER_NAME}',
            '{ASSIGNED_NAME}',
            '{COMPANY_NAME}',
        ))  + helpdeskRequestFields::getFieldsVars()
                + helpdeskHelper::getContactFieldsVars(array('CUSTOMER', 'ASSIGNED'));
    }

    // Declared public to use in install.php
    public static function getDefaultParam($param)
    {
        switch($param) {
            case 'antispam_mail_template':
                if (wa()->getLocale() == 'ru_RU') {
                    return
                        'Пожалуйста, подтвердите отправку запроса'.
                        "{SEPARATOR}".
                        "<p>Пожалуйста, подтвердите ваш запрос. Для этого просто перейдите по ссылке:<br>".
                        '<a href="{REQUEST_CONFIRM_URL}">{REQUEST_CONFIRM_URL}</a></p>'.
                        "<p>ВНИМАНИЕ: Ваш запрос будет принят к обработке только после подтверждения.<br>".
                        'Подтверждение необходимо в связи с большим количеством спама, приходящим на наш адрес. После того, как вы подтвердите запрос, ваш электронный адрес будет добавлен в нашу базу данных и все последующие запросы с этого адреса будут автоматически приниматься к обработке.</p>'.
                        "<p>Спасибо!</p>";
                } else {
                    return
                        'Please confirm your request'.
                        "{SEPARATOR}".
                        '<p>We have just received a request from your email address.</p>'.
                        "<p>To confirm your request, please follow this link:<br>".
                        '<a href="{REQUEST_CONFIRM_URL}">{REQUEST_CONFIRM_URL}</a></p>'.
                        "<p>NOTE: Your request will be accepted only after confirmation.<br>".
                        'Confirmation is required due to high volume of SPAM we receive. This is one-time action. After you confirm, we will add your email address to our contact database, and all future requests from you will be automatically accepted and queued into our customer support tracking system.</p>'.
                        "<p>Thank you!</p>";
                }
        }
        return null;
    }

    /**
     * Helper for settingsController()
     * Return validation errors for data in given $source
     */
    protected function settingsValidationErrors($source)
    {
        return array(); // no validation, no errors
    }

    /**
     * Helper for settingsController(). Assigns data from POST to helpdeskSource object.
     */
    protected function postToSource(helpdeskSource $source)
    {
        $source_data = waRequest::post('source', null, 'array');
        if ($source_data) {
            foreach($source_data as $k => $v) {
                if ($source->keyExists($k)) {
                    $source[$k] = $v;
                }
            }
        }

        $source_params = waRequest::post('params');
        if ($source_params && is_array($source_params)) {
            foreach($source_params as $k => $v) {
                if ($k == 'password' && !$v) {
                    continue;
                }
                $source->params[$k] = $v;
            }
        }
        if (empty($source_params['messages'])) {
            $source->params['messages'] = null;
        }
    }

    //
    // End of settings editor logic
    //

    // Provides information for workflow editor
    public function describeBehaviour($source)
    {
        if (empty($source->params->workflow)) {
            return array();
        }
        return array(
            $source->params->workflow => array(
                'new_states' => isset($source->params->new_request_state_id) ? array($source->params->new_request_state_id) : array(),
            ),
        );
    }

    protected function buildNewSource()
    {
        $source = parent::buildNewSource();
        $source->params->setAll(array(
            'antispam' => '',
            'workflow' => '',
        ));
        return $source;
    }

    public function isFormEnabled(helpdeskSource $source)
    {
        return !!$this->getFormHtml($source);
    }
}

