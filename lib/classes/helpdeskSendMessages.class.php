<?php

class helpdeskSendMessages
{
    public function sendMessages(helpdeskRequest $request, $log, $messages)
    {
        // Remember locale to switch back later
        $original_locale = wa()->getLocale();

        // Data used in the loop
        $this_action_text = helpdeskRequest::formatHTML($log);

        // Loop to send all messages
        $all_recipients = array();

        foreach($messages as $m) {

            if (empty($m['tmpl'])) {
                continue;
            }

            // Prepare list if recipient emails
            $to = $this->getMessageRecipients($request, $log, $m);
            if (empty($to)) {
                continue;
            }

            $client_emails_map = array();

            try {
                $client = $request->getClient();
                if ($client->exists()) {
                    $client_emails_map = array_fill_keys($client->get('email', 'value'), true);
                }
            } catch (waException $e) {
                // noting to do
            }

            // Email vars (locale dependent)
            $email_vars = array();
            foreach($to as $email => $name)
            {
                $vars = array();

                $is_client_email = isset($client_emails_map[$email]);
                if ($is_client_email) {
                    $this->setLocaleByContact($client, $original_locale);
                    $client_logs = $request->getLogsClient();
                    if (!empty($client_logs)) {
                        array_shift($client_logs);
                        $vars['{REQUEST_HISTORY_CUSTOMER}'] = $request->getEmailRequestHistory($client_logs);
                    } else {
                        $vars['{REQUEST_HISTORY_CUSTOMER}'] = '';
                    }
                    $all_logs = $request->getLogs();
                    if (!empty($all_logs)) {
                        array_shift($all_logs);
                        $vars['{REQUEST_HISTORY}'] = $request->getEmailRequestHistory($all_logs, 'all');
                    } else {
                        $vars['{REQUEST_HISTORY}'] = '';
                    }
                } else {
                    $contact = $this->getContactByEmail($email);
                    $this->setLocaleByContact($contact, $original_locale);
                }

                $email_vars[$email] = helpdeskHelper::arrayMerge($this->getMessageVars($request, $log, $m, $this_action_text), $vars);
            }

            if (!empty($m['add_attachments'])) {
                $attachments = $log->attachments->toArray();
                $log_id = $log->id;
            } else {
                $attachments = array();
                $log_id = null;
            }

            $one_click_feedback = new helpdeskOneClickFeedback();

            foreach($to as $email => $name) {
                $send_to = array($email => $name);
                if (!$name) {
                    $send_to = $email;
                }

                $email_template = $this->getEmailTemplate($request, $log, $m);

                // One click feedback vars
                $extra_vars = !$is_client_email ? $one_click_feedback->getVarsForNotClient($request) :
                        $one_click_feedback->getVarsForClient($request);

                // push into queue
                helpdeskHelper::pushToSendEmailHtmlTemplate(
                    $send_to,
                    $email_template,
                    $email_vars[$email] + $extra_vars,
                    $log_id
                );

                // insert hashes for one click feedback
                if ($is_client_email) {
                    $rdm = new helpdeskRequestDataModel();

                    $hashes = array();
                    $match_fields_map = array_fill_keys($one_click_feedback->getMatchedFields($email_template), true);
                    foreach ($one_click_feedback->getFields($request, array('hash', 'field_id')) as $field_id => $info) {
                        if (!empty($match_fields_map[$field_id])) {
                            $hashes[$info['field_id']] = $info['hash'];
                        }
                    }

                    $rdm->addOneClickFeedbackHashes($request->id, $hashes);
                }

            }
            // List of recipients will be saved in helpdesk_request_log.to
            if (empty($m['exclude'])) {
                $all_recipients += $to;
            }
        }

        $this->setLocale($original_locale);

        return $all_recipients;
    }

    private function setLocaleByContact(waContact $contact, $default_locale)
    {
        $locale = $contact->exists() ? $contact->getLocale() : null;
        if (!$locale) {
            $locale = $default_locale;
        }
        $this->setLocale($locale);
    }

    private function setLocale($locale)
    {
        if ($locale && $locale != wa()->getLocale()) {
            wa()->setLocale($locale);
        }
    }

    private function getContactByEmail($email)
    {
        $col = new waContactsCollection('search/email=' . $email);
        $contacts = $col->getContacts('*', 0, 1);
        $contact_id = key($contacts);
        $contact = new waContact($contact_id);
        return $contact;
    }


    /**
     * helper for sendMessages()
     * Also used for message previews for backend users.
     * Returns message recipients, email => name
     */
    public function getMessageRecipients($request, $log, $m)
    {
        if (empty($m['to'])) {
            return array();
        }
        if (!is_array($m['to'])) {
            $m['to'] = array($m['to'] => 1);
        }
        $action = null;
        if ($log) {
            try {
                $action = $request->getWorkflow()->getActionById($log->action_id);
            } catch (Exception $e) { }
        }

        $result = array();

        foreach($m['to'] as $val => $true) {
            switch($val) {
                case 'assignee':
                case 'assigned_group':
                    if ($action && $action->getOption('allow_choose_assign')) {
                        $recipient_ids = waRequest::post('copy', array(), 'array_int');
                    } else {
                        $recipient_ids = array();
                    }
                    if ($log && $log->assigned_contact_id) {
                        $recipient_ids[] = $log->assigned_contact_id;
                    } elseif ($request->assigned_contact_id) {
                        $recipient_ids[] = $request->assigned_contact_id;
                    }
                    foreach($recipient_ids as $i => $id) {
                        if ($val == 'assigned_group') {
                            if ($id >= 0) {
                                unset($recipient_ids[$i]);
                            }
                        } else {
                            if ($id <= 0) {
                                unset($recipient_ids[$i]);
                            }
                        }
                    }

                    if (!$recipient_ids) {
                        break;
                    }
                    $result += self::getEmails(self::getContactIds(array_values($recipient_ids)));
                    break;

                case 'client':
                    try {
                        $client = $request->getClient();
                        $client->getName();

                    } catch (Exception $e) {
                        $client = null;
                    }

                    $name = $request->getContactNameFromData();
                    $email = $request->getContactEmailFromData();
                    if (empty($email) && $client) {
                        $email = $client->get('email', 'default');
                    }

                    if (!$email) {
                        continue 2;
                    }
                    if (empty($name) && $client) {
                        $name = $client->getName();
                    }
                    $result += array($email => $name);
                    break;

                default:
                    $name = '';
                    if (wa_is_int($val)) {
                        try {
                            $c = new waContact($val);
                            $val = $c->get('email', 'default');
                            $name = $c->getName();
                        } catch (Exception $e) {
                            break;
                        }
                    }

                    $ev = new waEmailValidator();
                    if (!$ev->isValid($val)) {
                        break;
                    }
                    $result += array($val => $name);
            }
        }

        return $result;
    }

    /**
     * helper for getMessageRecipients()
     * Makes a list of emails from list of contact_ids
     */
    public static function getEmails($recipient_contact_ids)
    {
        $to = array();
        foreach ($recipient_contact_ids as $id) {
            try {
                $c = new waContact($id);
                $name = $c->getName();
                $email = $c->get('email', 'default');
                if ($email) {
                    if ($name) {
                        $to[$email] = $name;
                    } else {
                        $to[$email] = '';
                    }
                }
            } catch (waException $e) {
                // No such contact exists. Ignore.
            }
        }
        return $to;
    }

    /**
     * Helper for getMessageRecipients() to get unique contact_ids from
     * array containing contact (positive) and/or group (negative) ids.
     */
    public static function getContactIds($recipient_ids)
    {
        $gm = null;
        $recipient_contact_ids = array();
        foreach ($recipient_ids as $id) {
            if ($id > 0) {
                $recipient_contact_ids[$id] = true;
            } else {
                // All contacts from given group
                if (!$gm) {
                    $gm = new waUserGroupsModel();
                }
                $recipient_contact_ids += array_flip($gm->getContactIds(-$id));
            }
        }
        return array_keys($recipient_contact_ids);
    }

    /**
     * Helper for sendMessages()
     * Also used for message previews for backend users.
     * @return array var name (including {} brackets) => replacing text
     */
    public function getMessageVars($request, $log, $m, $this_action_text=null)
    {
        $r_info = $request->getInfo();
        $request_id = $request->getId();

        // Name of client who created the request
        try {
            $creator_name = $request->getClient()->getName();
        } catch (Exception $e) {
            $creator_name = $request->getContactNameFromData();
        }

        try {
            $client_email = $request->getClient()->get('email', 'default');
        } catch (Exception $e) {
            $client_email = $request->getContactEmailFromData();
        }

        $this_action_text !== null || $this_action_text = helpdeskRequest::formatHTML($log);

        // State name we're going to transit to
        try {
            $state = $request->getWorkflow()->getStateById($log->after_state_id);
            $request_status_customer = $state->getOption('customer_portal_name', '');
            $request_status = $state->getName();
        } catch (Exception $e) {
            $request_status_customer = '';
            $request_status = '';
        }

        $assigned_contact_id = null;
        if ($log) {
            $assigned_contact_id = $log->assigned_contact_id;
        }
        if (!$assigned_contact_id) {
            $assigned_contact_id = $request->assigned_contact_id;
        }

        if (!$request_status) {
            try {
                $request_state = $request->getWorkflow()->getStateById($request->state_id);
                $request_status_customer = $request_state->getName();
                $request_status = $request_state->getName();
            } catch (Exception $e) {
                $request_status_customer = '';
                $request_status = '';
            }
        }

        $log_fields = array();
        $fields = helpdeskRequestFields::getFields();
        foreach ($log->params as $param_name => $param_value) {
            if (substr($param_name, 0, 4) === helpdeskRequestLogParamsModel::PREFIX_REQUEST) {
                $fld_id = substr($param_name, 4);
                $name = $param_name;
                $val = $param_value;
                if (isset($fields[$fld_id])) {
                    $fld = $fields[$fld_id];
                    if ($fld) {
                        $val = $fld->format($param_value, 'html');
                        $name = htmlspecialchars($fld->getName());
                    }
                }
                $log_fields[$fld->getId()] = $name . ': ' . $val;
            }
        }

        $actor_name = '';
        /**
         * @var waContact|null $actor_contact
         */
        $actor_contact = null;
        if ($log->actor_contact_id > 0) {
            $actor_contact = new waContact($log->actor_contact_id);
            if (!$actor_contact->exists()) {
                $actor_contact = null;
                $actor_name = _w('Unknown contact');
            } else {
                $actor_name = $actor_contact->getName();
            }
        } else {
            $actor_contact = wa()->getUser();
            $actor_name = $actor_contact->getName();
        }

        $action_name = !empty($log->action_name) ? _w($log->action_name) : _w('creates request');
        if ($log) {
            try {
                $wf = $request->getWorkflow();
                $action = $wf->getActionById($log['action_id']);
                $action_name = htmlspecialchars($action->getName());
                if ($action instanceof helpdeskWorkflowActionAutoInterface) {
                    $action_name = $action->getActorName();
                }
            } catch (Exception $e) {
                $action_name = helpdeskRequest::getSpecialActionName($log['action_id']);
            }
        }

        // Template variables
        $vars = array(
            '{REQUEST_ID}' => $request_id,
            '{REQUEST_SUBJECT}' => $request->summary,
//            '{REQUEST_SUBJECT_WITH_ID}' => trim($request->summary." [ID:{$request_id}]"),
            '{REQUEST_SUBJECT_WITH_ID}' => trim($request->summary." [ID:{$request_id}-" . abs(crc32($request->created)) . "]"),
            '{REQUEST_BACKEND_URL}' => wa('helpdesk')->getConfig()->getHelpdeskBackendUrl() . '/#/request/' . $request_id . '/',
            '{REQUEST_CUSTOMER_PORTAL_URL}' => wa()->getRouteUrl('helpdesk/frontend/myRequest', array('id' => $request_id), true),
            '{REQUEST_CUSTOMER_CONTACT_ID}' => $request->client_contact_id,
            '{REQUEST_CUSTOMER_EMAIL}' => $client_email,
            '{CUSTOMER_NAME}' => $creator_name,
            '{ACTOR_NAME}' => $actor_name,
            '{REQUEST_STATUS}' => $request_status,
            '{REQUEST_STATUS_CUSTOMER}' => $request_status_customer,
            '{COMPANY_NAME}' => wa()->accountName(),
            '{ACTION_NAME}' => $action_name
        );
        $vars += self::getActorVars($actor_contact);
        $vars += self::getCustomerVars($request);
        $vars += self::getAssigneeVars($assigned_contact_id);
        $vars += self::getLocaleStringVars();

        $vars = array_map('htmlspecialchars', $vars);
        $vars += array(
            '{ACTION_TEXT}' => $this_action_text,
            '{REQUEST_TEXT}' => $r_info['text'],
            '{REQUEST_LOG_FIELDS}' => implode("<br />", $log_fields),
        );

        $vars += self::getRequestFieldsVars($request, $m['tmpl']);
        
        unset($vars['{SEPARATOR}']);

        return $vars;
    }

    private static function getContactVars($prefix, waContact $contact)
    {
        $vars = array();
        foreach ($contact->load() as $fld_name => $value) {
            $var_name = strtoupper('{'.$prefix.$fld_name.'}');
            if (is_array($value)) {
                $vars[$var_name] = $contact->get($fld_name, 'default');
                if (is_array($vars[$var_name]) || is_object($vars[$var_name])) {
                    unset($vars[$var_name]);
                }
            } else {
                $vars[$var_name] = $value;
            }
        }
        return $vars;
    }

    /** Helper for getMessageVars(). Returns {ACTOR_*} vars from waContact. */
    public static function getActorVars(waContact $contact = null)
    {
        if ($contact instanceof waContact) {
            return self::getContactVars('ACTOR_', wa()->getUser());
        } else {
            return array();
        }
    }

    /** Helper for getMessageVars(). Returns {CUSTOMER_*} vars from waContact. */
    public static function getCustomerVars($request)
    {
        $vars = array();
        try {
            $vars = self::getContactVars('CUSTOMER_', $request->getClient());
        } catch (Exception $e) { }
        return $vars;
    }

    public static function getRequestFieldsVars($request, $tmpl = '')
    {
        $vars = helpdeskRequestFields::getSubstituteVars($request);
        preg_match_all('~\{[A-Z0-9_]+\}~', ifset($tmpl, ''), $matches);
        foreach($matches[0] as $v) {
            if (!isset($vars[$v])) {
                $vars[$v] = '';
            }
        }
        return $vars;
    }

    public static function getLocaleStringVars()
    {
        return array(
            '{LOCALE_STRING_PERFORMS_ACTION}' => _w('performs action'),
            '{LOCALE_STRING_ASSIGNED}' => _w('Assigned:'),
            '{LOCALE_STRING_STATUS}' => _w('Status:')
        );
    }

    /** Helper for getMessageVars(). Returns {ASSIGNED_*} vars from waContact. */
    public static function getAssigneeVars($assigned_contact_id)
    {
        $vars = array(
            '{ASSIGNED_NAME}' => helpdeskHelper::getAssignedString($assigned_contact_id)
        );
        if ($assigned_contact_id > 0) {
            try {
                $c = new waContact($assigned_contact_id);
                $vars = self::getContactVars('ASSIGNED_', $c);
            } catch (Exception $e) {}
        }
        return $vars;
    }

        /**
     * helper for sendMessages()
     * Also used for message previews for backend users.
     */
    public function getEmailTemplate($request, $log, $m)
    {
        $template = explode('{SEPARATOR}', ifset($m['tmpl']), 3);
        while(count($template) < 3) {
            array_unshift($template, '');
        }

        // If template has no subject or from, use defaults
        if (empty($template[0])) {
            $template[0] = helpdeskHelper::getDefaultFrom(
                (!empty($m['sourcefrom']) && $m['sourcefrom'] == 'sourcefrom') ? $request : null
            ); // 'sourcefrom' or 'default'
        } else if ($request) {
            try {
                if (!empty($m['sourcefrom'])
                    && $request
                    && $request->getSource()->getSourceType() instanceof helpdeskEmailSourceType) {
                    $template[0] = helpdeskHelper::getDefaultFrom(empty($m['sourcefrom']) ? null : $request);
                }
            } catch (Exception $e) {
                // Something's wrong with the source, ignore
            }
        }
        if (empty($template[1])) {
            $template[1] = 'Re: {REQUEST_SUBJECT_WITH_ID}';
        }

        return implode('{SEPARATOR}', array_map('trim', $template));
    }

    public function getBccTemplate()
    {
        $template = 'Fwd: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}';
        $template .= '<p>{ACTOR_NAME} ';
        $template .= '{LOCALE_STRING_PERFORMS_ACTION} ';
        $template .= '"{ACTION_NAME}"</p>';
        $template .= '<div>{ACTION_TEXT}</div><p>{REQUEST_LOG_FIELDS}</p>';
        $template .= '{LOCALE_STRING_ASSIGNED} {ASSIGNED_NAME}<br />';
        $template .= '{LOCALE_STRING_STATUS} {REQUEST_STATUS}<br />';
        $template .= '--<br />{COMPANY_NAME}<br />';
        $template .= '<a href="{REQUEST_BACKEND_URL}">{REQUEST_BACKEND_URL}</a>';
        return $template;
    }

    public function sendMessagesFromQueue()
    {
        $messages_queue = new helpdeskMessagesQueueModel();
        $messages_queue->sendAll();
    }

    public static function isQueueIsEmpty()
    {
        $messages_queue = new helpdeskMessagesQueueModel();
        return $messages_queue->countAll() <= 0;
    }

    public static function getMessageVarsDescriptions()
    {
        return helpdeskHelper::getVars(array(
            '{REQUEST_ID}',
            '{REQUEST_SUBJECT}',
            '{REQUEST_SUBJECT_WITH_ID}',
            '{REQUEST_TEXT}',
            '{ACTION_TEXT}',
            '{REQUEST_STATUS}',
            '{REQUEST_STATUS_CUSTOMER}',
            '{REQUEST_HISTORY}',
            '{REQUEST_HISTORY_CUSTOMER}',
            '{REQUEST_BACKEND_URL}',
            '{REQUEST_CUSTOMER_PORTAL_URL}',
            '{REQUEST_CUSTOMER_CONTACT_ID}',
            '{REQUEST_CUSTOMER_EMAIL}',
            '{CUSTOMER_NAME}',
            '{ACTOR_NAME}',
            '{ASSIGNED_NAME}',
            '{COMPANY_NAME}',
        )) + helpdeskRequestFields::getFieldsVars()
                + helpdeskHelper::getContactFieldsVars();
    }

}
