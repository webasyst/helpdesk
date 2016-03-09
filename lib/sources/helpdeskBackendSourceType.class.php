<?php
/**
 * Form for users to create requests from backend.
 */
class helpdeskBackendSourceType extends helpdeskCommonST implements helpdeskFormSTInterface
{
    public function init()
    {
        $this->name = _w('Backend');
    }

    // Frontend form HTML using templates/backend/backend_front.html
    public function getFormHtml(helpdeskSource $source)
    {
        // When this form lives in plugin, this sets proper place to get localization from.
        // Done here in case plugin wants to inherit this code.
        waSystem::pushActivePlugin($this->getPlugin(), 'helpdesk');

        // Change locale to form's locale if specified
        $old_locale = null;
        if ($source->params->ifset('locale')) {
            $old_locale = wa()->getLocale();
            wa()->setLocale($source->params->locale);
        }

        // Reset the view. It is created again by $this->getView()
        $this->view = null;

        // Contact form
        $form = self::getContactForm();

        $this->getView()->assign('form', $form);
        $this->getView()->assign('source', $source);
        $this->getView()->assign('uniqid', uniqid('f'));
        $this->getView()->assign('root_url', wa()->getRootUrl(true));
        $this->getView()->assign('action_url', '?module=requests&action=save');
        $this->getView()->assign('assignees', helpdeskHelper::getAssignOptions());
        $this->getView()->assign('all_states', helpdeskHelper::getAllWorkflowsWithStates(false));

        // Prepare and generate form HTML
        $html = $this->display();

        // Change locale back if needed
        if ($old_locale) {
            wa()->setLocale($old_locale);
        }

        // Cleanup when returning from plugin code to application code.
        waSystem::popActivePlugin();
        return $html;
    }

    // Helper for getFormHtml() and frontendSubmit();
    protected static function getContactForm()
    {
        $form_fields = array();
        foreach(array('firstname', 'middlename', 'lastname', 'email', 'phone') as $fld_id) {
            $f = waContactFields::get($fld_id);
            if ($f) {
                $form_fields[$fld_id] = $f;
            }
        }
        $form = waContactForm::loadConfig($form_fields, array(
            'namespace' => 'client'
        ));
        return $form;
    }

    public function settingsController(helpdeskWorkflow $wf = null, helpdeskSource &$source = null) {
        if (wa()->getUser()->getRights('helpdesk', 'backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
        return parent::settingsController($wf, $source);
    }

    /**
     * Helper for settingsController()
     * Assign vars into $this->view for settings form template.
     */
    protected function settingsPrepareView($submit_errors, $source, $wf)
    {
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
                            $messages[$i]['to'][$id] = $c->getName();
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
            'submit_errors' => $submit_errors,
            'assignees' => helpdeskHelper::getAssignOptions($wf ? $wf->getId() : null),
            'antispam_mail_template_vars' => helpdeskHelper::categorizeVars($this->getAntispamMailTemplateVars()),
            'receipt_template_vars' => helpdeskHelper::categorizeVars($this->getReceiptTemplateVars()),
            'oneclick_feedback_fields' => helpdeskWorkflow::getOneClickFeedbackFields(true),
            'messages' => $messages,
            'all_states' => helpdeskHelper::getAllWorkflowsWithStates(false),
            'state_not_exists' => true
        ));
    }

    //
    // Frontend submit logic
    //

    /**
     * Process frontend form submission
     * @param helpdeskSource $source
     * @return string HTML form
     */
    public function frontendSubmit(helpdeskSource $source)
    {
        if (wa()->getEnv() != 'backend') {
            throw new waException('Access denied', 403);
        }

        $subject = waRequest::post('subject');
        $text = waRequest::post('text');

        $source->params->new_request_assign_contact_id = waRequest::post('assigned_contact_id');


        // Change locale to form's locale if specified (for correct error texts)
        if ($source->params->ifset('locale')) {
            wa()->setLocale($source->params->locale);
        }

        if (empty($source->params->fld_subject)) {
            $subject = substr(strip_tags($text), 0, 100);
        }

        $assigned_contact_id = waRequest::post('assigned_contact_id', 0, 'int');
        $subject = html_entity_decode($subject, ENT_QUOTES, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = htmlspecialchars($text, ENT_NOQUOTES);

        // process uploaded files, if any
        $attachments = array();
        $files = waRequest::file('attachment');
        if ($files->uploaded()) {
            foreach($files as $file) {
                if (!$file->uploaded()) {
                    continue;
                }
                $attachments[] = array(
                    'file' => $file->tmp_name,
                    'name' => $file->name,
                );
            }
        }

        $message = array(
            'source' => $source,
            'message_id' => $source->id.'/'.uniqid($this->getType().'_', true),
            'assigned_contact_id' => $assigned_contact_id,
            'summary' => $subject,
            'text' => $text,
            'attachments' => $attachments,
            'params' => array(),
            'creator_contact' => wa()->getUser(),
            'workflow_id' => null,
            'state_id' => ''
            // client_contact: see below
        );

        // Validation
        $errors = array();
        if (!trim($text)) {
            $errors['text'] = _ws('This field is required.');
        }
        if (!trim($subject)) {
            $errors['subject'] = _ws('This field is required.');
        }

        // Contact
        $contact_id = waRequest::post('contact_id');
        if ($contact_id) {
            $message['client_contact'] = new waContact($contact_id);
        } else {
            $message['client_contact'] = new waContact();
            $form = self::getContactForm();
            $form_post = $form->post();
            if (is_array($form_post) || $form_post instanceof Traversable) {
                foreach($form_post as $k => $v) {
                    $message['client_contact'][$k] = $v;
                }
            }

            $contact_errors = $message['client_contact']->validate();
            $contact_errors || $contact_errors = array();
            foreach($contact_errors as $fld_id => $es) {
                if ($fld_id == 'name') {
                    $errors["client[firstname]"] = $es;
                    $errors["client[middlename]"] = $es;
                    $errors["client[lastname]"] = $es;
                } else {
                    $errors["client[{$fld_id}]"] = $es;
                }
            }
        }

        $new_request_state_id = waRequest::post('new_request_state_id');
        if ($new_request_state_id) {
            $workflow_and_states = explode('@', $new_request_state_id, 2);
            if (count($workflow_and_states) < 2) {
                $message['state_id'] = $workflow_and_states[0];
            } else {
                $message['workflow_id'] = $workflow_and_states[0];
                $message['state_id'] = $workflow_and_states[1];
            }
        }

        // Form JS expects JSON with errors as a result
        if ($errors) {
            echo json_encode(array(
                'status' => 'error',
                'errors' => $errors,
            ));
            exit;
        }

        try {
            // pass message to createNewRequest
            $request_id = $this->handleMessage($message);
        } catch (Exception $e) {
            echo json_encode(array(
                'status' => 'error',
                'errors' => array(
                    '' => $e->getMessage(),
                ),
            ));
            exit;
        }

        echo json_encode(array(
            'status' => 'ok',
            'data' => $request_id,
        ));
        exit;
    }

    protected function handleMessage($message)
    {
        $message = helpdeskSourceHelper::cleanMessage($message);
        $message['request'] = helpdeskSourceHelper::createRequest($message);
        return $this->saveNewRequest($message);
    }

    /** handleMessage() helper. Called on messages with no request_id in their subject to create new requests. */
    protected function saveNewRequest($message)
    {
        $r = $message['request'];

        $source = $message['source'];
        if (empty($r->workflow_id)) {
            $r->workflow_id = ifset($message['workflow_id'],  null);
        }

        if (empty($r->state_id)) {
            $r->state_id = ifset($message['state_id'], $source->params->ifset('new_request_state_id', ''));
        }

        if (!empty($r->state_id) && strpos('@', $r->state_id) > 0) {
            $workflow_and_state = explode('@', $r->state_id);
            $r->workflow_id = $workflow_and_state[0];
            $r->state_id = ifset($workflow_and_state[1], '');
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
            $r['creator_contact_id'] = $message['creator_contact']->getId();
        } else {
            $r['creator_contact_id'] = $r['client_contact_id'];
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

        helpdeskHelper::logAction('request_created');

        return $r->id;
    }

    //
    // End of frontend submit logic
    //

    //
    // Settings editor logic
    //

    protected function postToSource(helpdeskSource $source)
    {
        $source->params->fld_subject = 1;
        parent::postToSource($source);
    }

    protected function settingsValidationErrors($source)
    {
        return array();
    }

    //
    // End of settings editor logic
    //

    public function getRequestParams()
    {
        return array(
            'subject' => _w('Original subject'),
        );
    }

    // @deprecated
    public function isFormEnabled(helpdeskSource $source)
    {
        return true;
    }

    public function savesEmptyContacts()
    {
        return true;
    }

}

