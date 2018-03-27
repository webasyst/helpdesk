<?php
/**
 * Basic workflow action functionality.
 * Jack of all trades.
 */
class helpdeskWorkflowBasicAction extends helpdeskWorkflowAction
{
    const DEFAULT_ASSIGNEE_USER_PERFORMING_ACTION = 'user_performing_action';

    /**
     * Generates HTML form for action settings page in workflow setup.
     * Uses the following template: lib/workflow/templates/Basic_settings.html
     * Accepts submit from this form and saves settings from POST into config.
     */
    public function settingsController()
    {
        $submit_errors = array();
        if (waRequest::post()) {

            $submit_errors = $this->settingsValidationErrors();
            if (!$submit_errors) {
                $this->settingsSave();
                return ''; // empty return hides the dialog and reloads workflow graph page
            }

            // Data to show in the form
            $options = waRequest::post('options', array(), 'array');
            $transition = waRequest::post('transition');
        } else {
            // No post: load data from config
            $options = $this->options;
            $cfg = helpdeskWorkflow::getWorkflowsConfig();
            $transition = ifset($cfg['workflows'][$this->getWorkflow()->getId()]['actions'][$this->getId()]['transition']);
            if (!$this->id) {
                $options['name'] = '';
            }
        }

        // No POST came or validation failed.
        // In both cases, show the form.
        $this->getView();
        $this->settingsPrepareView($submit_errors, $options, $transition);

        return $this->display('settings');
    }

    /**
     * Helper for settingsController()
     * Return validation errors for data in POST.
     */
    protected function settingsValidationErrors()
    {
        /** @var waEmailValidator */
        static $ev = null;

        $post = waRequest::post();
        $errors = array();

        // Notification messages
        if (!empty($post['options']['messages']) && is_array($post['options']['messages'])) {
            foreach($post['options']['messages'] as $i => $m) {
                if (!is_array($m)) {
                    continue;
                }
                if (empty($m['tmpl'])) {
                    $errors['options[messages]['.$i.'][tmpl]'] = _ws('This field is required.');
                }
                if (empty($m['to']) || !is_array($m['to'])) {
                    $errors['options[messages]['.$i.'][to]'] = _ws('This field is required.');
                } else {
                    if ($ev === null) {
                        $ev = new waEmailValidator();
                    }
                    foreach($m['to'] as $addr => $true) {
                        if (!wa_is_int($addr) && !$ev->isValid($addr) && !in_array($addr, array('client', 'assignee', 'assigned_group'))) {
                            $errors['options[messages]['.$i.'][to]'] = _ws('Invalid email').': '.$addr;
                            break;
                        }
                    }
                }
            }
        }

        // ID for new action
        if (!strlen($this->getId())) {
            $new_id = ifset($post['action_new_id']);
            if (!$new_id) {
                $errors['action_new_id'] = _ws('This field is required.');
            } else if (preg_match('~[^a-z0-9_]~', $new_id)) {
                $errors['action_new_id'] = _w('This field only allows Latin letters, numbers and underscores.');
            } else {
                try {
                    $this->getWorkflow()->getActionById($new_id);
                    $errors['action_new_id'] = _w('Action with this ID already exists');
                } catch (Exception $e) {
                    // No such action: perfectly fine
                }
            }
        }

        // Action name
        $options = ifset($post['options']);
        if (empty($options['name'])) {
            $errors['options[name]'] = _ws('This field is required.');
        } else {
            $id = ifempty($new_id, $this->getId());
            $wf_id = $this->getWorkflow()->getId();
            $cfg = helpdeskWorkflow::getWorkflowsConfig();
            foreach(ifset($cfg['workflows'][$wf_id]['actions'], array()) as $a_id => $a_options) {
                if ($a_id != $id) {
                    $a = $this->getWorkflow()->getActionById($a_id);
                    if ($a->getName() == $options['name']) {
                        $errors['options[name]'] = _w('Action with this name already exists');
                        break;
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Use careful, this method save workflows config in file immediately
     * @param $opt
     * @param $value
     * @return helpdeskWorkflowBasicAction $this
     * @throws waException
     */
    public function setOption($opt, $value)
    {
        if (array_key_exists($opt, $this->options) && $this->options[$opt] === $value) {
            // option isn't changed
            return $this;
        }

        $this->options[$opt] = $value;
        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        $wf = $this->getWorkflow();
        $wf_id = $wf->getId();
        $cfg['workflows'][$wf_id]['actions'][$this->getId()]['options'][$opt] = $value;
        helpdeskWorkflow::saveWorkflowsConfig($cfg);
        return $this;
    }

    /**
     * Use careful, this method save workflows config in file immediately
     * @param $options string key => value
     * @return $this
     * @throws waException
     */
    public function setOptions($options)
    {
        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        $wf = $this->getWorkflow();
        $wf_id = $wf->getId();

        $changed = false;
        foreach ($options as $key => $value) {
            if (array_key_exists($this->options, $key) && $this->options[$key] === $value) {
                continue;
            }
            $changed = true;
            $this->options[$key] = $value;
            $cfg['workflows'][$wf_id]['actions'][$this->getId()]['options'][$key] = $value;
        }

        if ($changed) {
            helpdeskWorkflow::saveWorkflowsConfig($cfg);
        }
        return $this;
    }

    /**
     * Helper for settingsController()
     * Save data from POST to workflow config when validation is already passed.
     */
    protected function settingsSave()
    {
        $cfg = $this->settingsSavePrepareConfig();
        helpdeskWorkflow::saveWorkflowsConfig($cfg);
    }

    /**
     * Prepare data from POST before actually save workflow config when validation is already passed.
     * @return array
     */
    protected function settingsSavePrepareConfig()
    {
        $cfg = helpdeskWorkflow::getWorkflowsConfig();

        $wf = $this->getWorkflow();
        $wf_id = $wf->getId();

        // Save action data to config
        $just_created = false;
        if (!strlen($this->getId())) {
            $just_created = true;
            $this->id = waRequest::post('action_new_id', uniqid('a'), 'string');
            $cfg['workflows'][$wf_id]['actions'][$this->getId()] = array();
        }

        $options = $this->getOptionsSettingsFromPost();

        $cfg['workflows'][$wf_id]['actions'][$this->getId()]['options'] = $options;
        $cfg['workflows'][$wf_id]['actions'][$this->getId()]['transition'] = $this->getTransitionSettingsFromPost();
        $cfg['workflows'][$wf_id]['actions'][$this->getId()]['classname'] = get_class($this);

        // Add this action to state, if action has just been created
        if ($just_created) {
            $state_id = waRequest::request('state_id', '', 'string');
            $cfg['workflows'][$wf_id]['states'][$state_id]['available_actions'][] = $this->getId();
            $cfg['workflows'][$wf_id]['states'][$state_id]['available_actions'] =
                array_values(array_unique($cfg['workflows'][$wf_id]['states'][$state_id]['available_actions']));
        }

        return $cfg;
    }

    /**
     * Helper for settingsSave()
     */
    protected function getTransitionSettingsFromPost()
    {
        $transition = waRequest::post('transition');
        if(!is_array($transition)) {
            if (!strlen($transition)) {
                $transition = null;
            }
        }
        return $transition;
    }

    /**
     * Helper for settingsSave()
     * @return array
     */
    protected function getOptionsSettingsFromPost()
    {
        $options = waRequest::post('options', array(), 'array');
        $options['user_button_value'] = ifempty($options['user_button_value'], $options['name']);
        $options['user_form_button_value'] = ifempty($options['user_form_button_value'], $options['name']);
        $options['ban_user'] = !empty($options['ban_user']) ? 1 : 0;

        // Customer center visibility
        switch(waRequest::post('customer_portal')) {
            case 'triggerable_visible_all':
                $options['client_triggerable'] = 1;
                $options['client_visible'] = 'all';
                break;
            case 'triggerable_visible_own':
                $options['client_triggerable'] = 1;
                $options['client_visible'] = 'own';
                break;
            case 'visible_all':
                $options['client_visible'] = 'all';
                break;
        }

        // Attachments are only allowed with textarea
        if (empty($options['show_textarea'])) {
            unset($options['allow_attachments']);
        }

        $options['messages'] = $this->getMessagesSettingsFromPost();

        return $options;
    }

    /**
     * Helper for settingsSave()
     */
    protected function getMessagesSettingsFromPost()
    {
        $options = waRequest::post('options', array(), 'array');

        if (empty($options['messages']) || !is_array($options['messages'])) {
            return array();
        }
        $messages = array();
        $ev = new waEmailValidator();
        foreach($options['messages'] as $m) {
            if (!is_array($m) || empty($m['tmpl']) || empty($m['to']) || !is_array($m['to'])) {
                continue;
            }
            foreach($m['to'] as $addr => $true) {
                if (!wa_is_int($addr) && !$ev->isValid($addr) && !in_array($addr, array('client', 'assignee', 'assigned_group'))) {
                    continue 2;
                }
            }
            $messages[] = array(
                'sourcefrom' => !empty($m['sourcefrom']) ? $m['sourcefrom'] : null,
                'tmpl' => $m['tmpl'],
                'to' => $m['to'],
                'add_attachments' => ifset($m['add_attachments']),
            );
        }
        return $messages;
    }

    protected function getSpecialFields()
    {
        return array('show_textarea', 'allow_attachments', 'assignment');
    }

    protected function getFieldOrders()
    {
        $special_fields = $this->getSpecialFields();
        $request_fields = helpdeskRequestFields::getFields();

        $fields_order = $special_fields;
        foreach (array_keys($request_fields) as $fld_id) {
            $fields_order[] = helpdeskWorkflowAction::PREFIX_REQUEST_FIELD . $fld_id;
        }



        $allowed_fields_order = array();
        foreach ($this->options as $opt_name => $opt_val) {
            if (in_array($opt_name, $fields_order)) {
                $allowed_fields_order[] = $opt_name;
            }
        }

        foreach ($allowed_fields_order as $k => $field_name) {
            if (empty($this->options[$field_name])) {
                unset($allowed_fields_order[$k]);
            }
        }

        $unallowed_field_order = array_diff($fields_order, $allowed_fields_order);
        $prefix = helpdeskWorkflowAction::PREFIX_REQUEST_FIELD;
        $prefix_len = strlen($prefix);
        foreach ($unallowed_field_order as $k => $field_name) {
            $found = false;
            if (in_array($field_name, $special_fields)) {
                $found = true;
            } else if (substr($field_name, 0, $prefix_len) ===  $prefix) {
                $field_name = substr($field_name, $prefix_len);
                if (isset($request_fields[$field_name])) {
                    $found = true;
                }
            }
            if (!$found) {
                unset($unallowed_field_order);
            }
        }

        return array(
            $allowed_fields_order, $unallowed_field_order
        );

    }

    protected function getAllowedFieldsOrder()
    {
        $orders = $this->getFieldOrders();
        return $orders[0];
    }


    /**
     * Helper for settingsController()
     * Assign vars into $this->view for settings form template.
     */
    protected function settingsPrepareView($submit_errors, $options, $transition)
    {
        // Contact names for message recipients
        $options['messages'] = ifset($options['messages'], array());
        foreach($options['messages'] as &$m) {
            if (isset($m['to'])) {
                if (!is_array($m['to'])) {
                    $m['to'] = array($m['to'] => 1);
                }
                foreach($m['to'] as $val => &$name) {
                    if (wa_is_int($val)) {
                        try {
                            $name = wao(new waContact($val))->getName();
                        } catch (Exception $e) {
                            unset($m['to'][$val]);
                            continue;
                        }
                    } else {
                        $name = $val;
                    }
                }
            }
        }
        unset($m, $name);

        $order_fields = $this->getFieldOrders();
        $workflow_id = $this->getWorkflow()->getId();

        $this->getView()->assign(array(
            'action_new_id' => waRequest::post('action_new_id', '', 'string'),
            'assignees' => helpdeskHelper::getAssignOptions($this->getWorkflow()->getId()),
            'uniqid' => uniqid('s'),
            'states' => $this->getWorkflow()->getAllStates(),
            'workflow'  => $this->getWorkflow(),
            'workflow_id' => $workflow_id,
            'workflows' => helpdeskWorkflow::getWorkflows(),
            'action' => $this,
            'options' => $options,
            'transition' => $transition,
            'submit_errors' => $submit_errors,
            'message_vars' => $this->getMessageVars(),
            'request_fields' => helpdeskRequestFields::getFields(),
            'allowed_fields_order' => $order_fields[0],
            'unallowed_fields_order' => $order_fields[1],
            'oneclick_feedback_fields' => helpdeskWorkflow::getOneClickFeedbackFields(true)
        ));
    }

    /**
     * Message vars of this action
     *
     * @param bool $plain
     * @return array
     *      If plain is true
     *          [
     *              string var_id => string var_description,
     *          ]
     *      Otherwise
     *          [
     *              string category_id =>
     *                  [
     *                      'name' => string name_of_category
     *                      'vars' =>
     *                          [
     *                              string var_id => string var_description
     *                          ]
     *                  ]
     *          ]
     */
    public function getMessageVars($plain = false)
    {
        $vars = helpdeskSendMessages::getMessageVarsDescriptions();
        if (!$plain) {
            $vars = helpdeskHelper::categorizeVars($vars);
        }
        return $vars;
    }

    /*
     * End of settingsController() section.
     * -------------------------------------------------------------------------------------------
     * formController() section
     */

    /**
     * Return HTML to show on request info page when user clicks action button.
     * Uses the following template: lib/workflow/templates/Basic.html
     *
     * When returns null, no form is shown and action is performed immidiately instead.
     */
    public function formController($params)
    {
        $params = self::prepareParams($params, $this->getId());

        $errors = array();
        if (waRequest::post()) {
            $errors = $this->formValidationErrors($params);
            if (!$errors) {

                // Make sure request is still in state where this action is applicable
                $request = $params['request'];
                $allowed = false;
                try {
                    foreach ($request->getWorkflow()->getActions($request->state_id) as $action) {
                        if ($action->getId() == $this->getId()) {
                            $allowed = true;
                            break;
                        }
                    }
                } catch (Exception $e) { }

                if (!$allowed) {
                    $errors['state_changed'] = _w('Sorry, the request status has been changed. To continue working with this request you have to refresh the page.');
                }
            }

            if (!$errors) {
                return ''; // allow $this->run() to do its job
            }
        }

        $with_form = false; // Execute immidately with no form?


        if (wa()->getEnv() == 'backend') {
            // Action allowed?
            if (!$this->getOption('user_triggerable')) {
                $with_form = false;
            }
            if ($this->getOption('show_textarea') || $this->getOption('allow_attachments') || $this->getOption('allow_choose_assign')) {
                $with_form = true;
            }
        }

        if (wa()->getEnv() == 'frontend') {
            // Action allowed?
            if (!$this->getOption('client_triggerable')) {
                $with_form = false;
            }
            if ($this->getOption('show_textarea') || $this->getOption('allow_attachments')) {
                $with_form = true;
            }
        }

        if (!$with_form) {
            $allow_any_fields = false;
            foreach (helpdeskRequestFields::getFields() as $field_id => $field) {
                if ($this->getOption(helpdeskWorkflowAction::PREFIX_REQUEST_FIELD . $field_id)) {
                    $allow_any_fields = true;
                    break;
                }
            }
            if ($allow_any_fields) {
                $with_form = true;
            }
        }

        if (!$with_form) {
            return null;
        }

        $request = $params['request'];
        $assignees = $this->getAssignees();

        if (waRequest::post()) {
            $textarea_default_text = waRequest::post('comment', '', 'string');
        } else {
            $textarea_default_text = $this->getTextareaDefaultText($request);
        }

        $this->getView()->assign(array(
            'action' => $this,
            'request' => $request,
            'assignees' => $assignees,
            'action_id' => $this->getId(),
            'request_id' => $request->getId(),
            'form_submit_url' => $this->getFormSubmitUrl($request),
            'will_be_sent_to' => $this->getMessageRecipientsPreview($request, $assignees),
            'textarea_default_text' => $textarea_default_text,
            'errors' => $errors,
            'allowed_request_fields' => $this->getFields($request),
            'allowed_fields_order' => $this->getAllowedFieldsOrder()
        ));

        return $this->display();
    }

    /**
     * Helper for formController()
     * $assignees is the list of possible options for assignee selector
     */
    protected function getMessageRecipientsPreview($request, $assignees)
    {
        $messages = $this->getOption('messages');
        if (empty($messages) || !is_array($messages)) {
            return array();
        }

        $will_be_sent_to = array();
        $ev = new waEmailValidator();
        foreach($messages as $message_id => $m) {
            if (empty($m['tmpl']) || empty($m['to'])) {
                continue;
            }

            $to = array();

            if (!is_array($m['to'])) {
                $m['to'] = array($m['to'] => 1);
            }

            // Messages to assigned user and/or group
            if (!empty($m['to']['assignee']) || !empty($m['to']['assigned_group'])) {
                if ($assignees) {
                    if (!empty($m['to']['assignee']) && !empty($m['to']['assigned_group'])) {
                        $will_be_sent_to[] = array(
                            'name' => _w('Assigned user or all members of assigned group'),
                            'email' => '',
                            'message_id' => $message_id,
                        );
                    } else if (!empty($m['to']['assigned_group'])) {
                        $will_be_sent_to[] = array(
                            'name' => _w('All members of assigned group'),
                            'email' => '',
                            'message_id' => $message_id,
                        );
                    } else {
                        $will_be_sent_to[] = array(
                            'name' => _w('Assigned user'),
                            'email' => '',
                            'message_id' => $message_id,
                        );
                    }
                } else if ($this->getOption('default_assignee')) {
                    if ($this->getOption('default_assignee') === self::DEFAULT_ASSIGNEE_USER_PERFORMING_ACTION) {
                        $email = wa()->getUser()->get('email', 'default');
                        if ($email) {
                            $will_be_sent_to[] = array(
                                'name' => wa()->getUser()->getName(),
                                'email' => $email,
                                'message_id' => $message_id,
                            );
                        }
                    } else  if ($this->getOption('default_assignee') > 0 && !empty($m['to']['assignee'])) {
                        try {
                            $c = new waContact($this->getOption('default_assignee'));
                            $email = $c->get('email', 'default');
                            if ($email) {
                                $will_be_sent_to[] = array(
                                    'name' => $c->getName(),
                                    'email' => $email,
                                    'message_id' => $message_id,
                                );
                            }
                        } catch (Exception $e) {
                            // No such contact: ignore
                        }
                    } else if ($this->getOption('default_assignee') < 0 && !empty($m['to']['assigned_group'])) {
                        $group_id = -$this->getOption('default_assignee');
                        $name = wao(new waGroupModel())->getName($group_id);
                        if (strlen($name)) {
                            $will_be_sent_to[] = array(
                                'name' => $name.' ('._w('group').')',
                                'email' => '',
                                'message_id' => $message_id,
                            );
                        }
                    }
                }
            }

            // Prepare list if recipient emails
            foreach($m['to'] as $val => $true) {
                switch($val) {
                    case 'assignee':
                    case 'assigned_group':
                        break; // see above

                    case 'client':
                        try {
                            $client = $request->getClient();
                        } catch (Exception $e) {
                            $client = null;
                        }

                        $email = $request->getContactEmailFromData();
                        $name = $request->getContactNameFromData();
                        if (empty($email) && $client) {
                            $email = $client->get('email', 'default');
                        }
                        if (!$email) {
                            continue 2;
                        }
                        if (empty($name) && $client) {
                            $name = $client->getName();
                        }
                        $will_be_sent_to[] = array(
                            'name' => $name,
                            'email' => $email,
                            'message_id' => $message_id,
                        );
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
                        if (!$ev->isValid($val)) {
                            break;
                        }
                        $will_be_sent_to[] = array(
                            'name' => $name,
                            'email' => $val,
                            'message_id' => $message_id,
                        );
                        break;
                }
            }
        }
        asort($will_be_sent_to);

        $result = array();
        foreach($will_be_sent_to as $m) {
            empty($result[$m['message_id']]) && $result[$m['message_id']] = array();
            $result[$m['message_id']][] = $m;
        }
        return $result;
    }

    protected function getFields(helpdeskRequest $request)
    {
        $request_data = array();
        if ($request) {
            $request_data = $request->getData();
        }

        $request_fields = helpdeskRequestFields::getFields();
        $order = $this->getAllowedFieldsOrder();

        $allowed_fields = array();
        $prefix = helpdeskWorkflowAction::PREFIX_REQUEST_FIELD;
        $prefix_len = strlen($prefix);
        foreach ($order as $opt_name) {
            if (substr($opt_name, 0, $prefix_len) === $prefix) {
                $field_id = substr($opt_name, $prefix_len);   // cut off prefix
                $field = $request_fields[$field_id];
                $value = ifset($request_data[$field_id]['value'], '');
                $allowed_fields[$field_id] = array(
                    'id' => $field_id,
                    'name' => $field->getName(),
                    'html' => $field->getHtml(array(
                        'value' => $value,
                        'namespace' => 'field'
                    ))
                );
            }
        }

        return $allowed_fields;

    }

    protected function formValidationErrors($params)
    {
        return array(); // no validation, no errors
    }

    /**
     * Helper for formController()
     * List of possible options for assignee selector.
     */
    protected function getAssignees()
    {
        if (!$this->getOption('allow_choose_assign')) {
            return array();
        }
        $assignees = helpdeskHelper::getAssignOptions($this->getWorkflow()->getId());
        return $assignees;
    }

    /**
     * Helper for formController()
     * Returns URL to use in <form action="...">
     */
    protected function getFormSubmitUrl($request)
    {
        if (wa()->getEnv() == 'frontend') {
            return wa()->getRouteUrl('helpdesk/frontend/actionForm', array(
                'workflow_id' => $this->getWorkflow()->getId(),
                'action_id' => $this->getId(),
                'params' => $request->id,
            ));
        } else {
            return '?module=backend&action=actionForm';
        }
    }

    /** Helper for formController() */
    protected function getTextareaDefaultText($request)
    {
        if (!$this->getOption('show_textarea')) {
            return '';
        }

        try {
            $client_name = $request->getClient()->getName();
        } catch (Exception $e) {
            $client_name = $request->getContactNameFromData();
        }

        try {
            $client_email = $request->getClient()->get('email', 'default');
        } catch (Exception $e) {
            $client_email = $request->getContactEmailFromData();
        }
        $sender = new helpdeskSendMessages();

        $actor = wa()->getUser();

        $r_info = $request->getInfo();
        $vars = array_map('htmlspecialchars', array(
            '{REQUEST_ID}' => $request->id,
            '{REQUEST_SUBJECT}' => $request->summary,
//            '{REQUEST_SUBJECT_WITH_ID}' => trim($request->summary." [ID:{".$request->id."}]"),
            '{REQUEST_SUBJECT_WITH_ID}' => trim($request->summary." [ID:".$request->id."-" . abs(crc32($request->created)) . "]"),
            '{REQUEST_BACKEND_URL}' => wa('helpdesk')->getConfig()->getHelpdeskBackendUrl() . '/#/request/' . $request->id . '/',
            '{REQUEST_CUSTOMER_PORTAL_URL}' => wa()->getRouteUrl('helpdesk/frontend/myRequest', array('id' => $request->id), true),
            '{REQUEST_CUSTOMER_CONTACT_ID}' => $request->client_contact_id,
            '{REQUEST_CUSTOMER_EMAIL}' => $client_email,
            '{CUSTOMER_NAME}' => $client_name,
            '{ACTOR_NAME}' => $actor->getName(),
            '{COMPANY_NAME}' => wa()->accountName(),
        ) + $sender->getActorVars($actor)) + $sender->getCustomerVars($request);
        $vars += array(
            '{REQUEST_TEXT}' => $r_info['text'],
        );


        $textarea_default_text = helpdeskHelper::substituteVars($vars, $this->getOption('textarea_default_text'), false);
        if (wa()->getEnv() != 'frontend') {
            if (!preg_match('/<p>|<div>|<br/', $textarea_default_text)) {
                $textarea_default_text = '<p>' . nl2br($textarea_default_text) . '</p>';
            }
        } else {
            $textarea_default_text = trim($textarea_default_text);
        }

        return $textarea_default_text;
    }

    protected function prepareFromPost($params = null)
    {
        $log = $params['request_log'];
        $request = $params['request'];

        // Attachments
        $attachments = $this->getAttachmentsFromPost();
        $attachments && $log['attachments'] = $attachments;

        // Fields
        foreach ($this->getFieldsFromPost($request) as $field_id => $value) {
            $log->params[helpdeskRequestLogParamsModel::PREFIX_REQUEST . $field_id] = $value;
            $request->setField($field_id, $value);
        }

        // Assignment
        $assigned_contact_id = $this->getAssignedFromPost();
        if ($assigned_contact_id !== '') {

            if (wa_is_int($request->assigned_contact_id) && wa_is_int($assigned_contact_id) &&
                    $request->assigned_contact_id != $assigned_contact_id)
            {
                $log['assigned_contact_id'] = $assigned_contact_id;
            }
        }

        // Text
        $text = $this->getTextFromPost();
        $vars = array(
            '{ASSIGNED_NAME}' => helpdeskHelper::getAssignedString(
                !empty($log['assigned_contact_id']) ? $log['assigned_contact_id'] :
                        $request->assigned_contact_id
            )
        ) + helpdeskRequestFields::getSubstituteVars($request);
        $text = helpdeskHelper::substituteVars($vars, $text, false);

        if ($text) {
            empty($log['text']) || $log['text'] .= "<br>\n";
            $log['text'] .= $text;
        }

        // Transition to another state
        $transition = $this->getTransition($request, $log);
        $params['request_log']->after_state_id = $transition->getStateId();

        return $params;
    }

    /*
     * End of formController() section
     * -------------------------------------------------------------------------------------------
     * execute() section
     */

    /**
     * Performs the action when user submits the form generated by formController()
     * (or immidiately when no form were needed).
     */
    public function execute($params = null)
    {
        // Action enabled?
        if(!$this->getOption('always_triggerable')) {
            if (!$this->getOption('user_triggerable') && wa()->getEnv() == 'backend') {
                return null;
            }
            if (!$this->getOption('client_triggerable') && wa()->getEnv() == 'frontend') {
                return null;
            }
        }

        $params = $this->prepareFromPost($params);

        $log = $params['request_log'];
        $request = $params['request'];

        // assignment
        if ($log->assigned_contact_id !== null && $request->assigned_contact_id != $log->assigned_contact_id) {
            $request->assigned_contact_id = $log->assigned_contact_id;
        }

        // Notification emails
        $this->sendMessages($request, $log);

        // Additional actions with the customer
        if ($this->getOption('ban_user')) {
            $c = new waContact($request->getClient()->getId());
            $c['is_user'] = -1;
            $c->save();
            helpdeskHelper::logAction('access_disable', null, $request->getClient()->getId(), wa()->getUser()->getId());
        }

        // Perform other actions specified in transition
        $transition = $this->getTransition($request, $log);
        $params['do_not_save'] = true;
        foreach($transition->getActions() as $action) {
            $action->setOption('always_triggerable', true);
            $action->run($params);
        }
    }

    public function getMessagePreview($request = null,  $message_preview_id = null)
    {
        $params = self::prepareParams($request, $this->getId());
        $params = $this->prepareFromPost($params);
        $log = $params['request_log'];
        $request = $params['request'];
        return $this->messagePreview($request, $log, $message_preview_id);
    }

    /**
     * Outputs message preview page.
     */
    protected function messagePreview($request, $log, $message_id)
    {
        $messages = $this->getOption('messages');
        if (empty($messages[$message_id])) {
            throw new waException('Message settings not found', 404);
        }

        $m = $messages[$message_id];
        if (empty($m['tmpl'])) {
            throw new waException('Message settings not found', 404);
        }
        $sender = new helpdeskSendMessages();
        $to = $sender->getMessageRecipients($request, $log, $m);

        // Switch to appropriate locale
        $locale = null;
        try {
            $locale = $request->getSource()->params->ifset('locale');
        } catch(Exception $e) { }
        if ($m['to'] == 'client' && !$locale) {
            try {
                $locale = $request->getClient()->getLocale();
            } catch (Exception $e) { }
        }
        $locale && wa()->setLocale($locale);

        // Load template and replace $vars
        $template_string = $sender->getEmailTemplate($request, $log, $m);
        $vars = $sender->getMessageVars($request, $log, $m);

        $vars['{REQUEST_HISTORY_CUSTOMER}'] = $request->getEmailRequestHistory($request->getLogsClient());
        $vars['{REQUEST_HISTORY}'] = $request->getEmailRequestHistory($request->getLogs(), 'all');

        $message = helpdeskHelper::substituteVars($vars, $template_string);
        $message = explode('{SEPARATOR}', $message, 3);

        // Message parts
        $from = trim(ifset($message[0], ''));
        $subject = trim(ifset($message[1], ''));
        $body = ifempty($message[2], ' ');
        if (stripos($body, '<br') === false) {
            $body = nl2br($body);
        }

        $this->view = null;
        $this->getView()->assign(array(
            'to' => $to,
            'from' => $from,
            'subject' => $subject,
            'body' => $body,
            'attachments' => $log->attachments->toArray(),
        ));
        return $this->display('message_preview');
    }

    /**
     * Helper for execute().
     * Get list of attachments acting user sent via form.
     */
    protected function getAttachmentsFromPost()
    {
        if (!$this->getOption('allow_attachments')) {
            return array();
        }

        $files = waRequest::file('attachment');
        if (!$files->uploaded()) {
            return array();
        }

        $attachments = array();
        foreach($files as $file) {
            if (!$file->uploaded()) {
                continue;
            }
            $attachments[] = array(
                'file' => $file->tmp_name,
                'name' => $file->name,
            );
        }

        return $attachments;
    }

    protected function getFieldsFromPost(helpdeskRequest $request)
    {
        $data = array();

        $request_data = array();
        if ($request) {
            $request_data = $request->getData();
        }

        $fields = helpdeskRequestFields::getFields();

        $action_id = $this->getId();

        foreach (waRequest::post('field', array()) as $field_id => $value) {
            $old_value = ifset($request_data[$field_id]['value'], '');
            $value = trim($value);
            if (isset($fields[$field_id]) &&
                    ($value || $value === '0' || $value === 0 || $value === '') &&
                    ($value !== $old_value || $action_id === helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID))
            {
                $data[$field_id] = $value;
            }
        }

        return $data;
    }

    /** Helper for execute() */
    protected function getTextFromPost()
    {
        if (!$this->getOption('show_textarea')) {
            return '';
        }
        $text = trim(waRequest::post('comment', '', 'string'));
        if ($text && wa()->getEnv() == 'frontend') {
            $text = nl2br(htmlspecialchars($text));
        }
        return $text;
    }

    /**
     * Helper for execute()
     * Returns one of the following:
     * - positive int: assign specified contact_id
     * - negative int: assign specified -group_id
     * - zero: reset assignment
     * - empty string: do not change assignment
     * - string 'user_performing_action': assign current user
     */
    protected function getAssignedFromPost()
    {
        $assigned_contact_id = '';
        $assignment = $this->getOption('assignment');
        if ($assignment) {
            if ($this->getOption('allow_choose_assign')) {
                $assigned_contact_id = waRequest::post('assigned_contact_id', '');
            }
            if ($assigned_contact_id === '') {
                if ($this->getOption('default_assignee') === self::DEFAULT_ASSIGNEE_USER_PERFORMING_ACTION) {
                    $assigned_contact_id = wa()->getUser()->getId();
                } else if (strlen($this->getOption('default_assignee'))) {
                    $assigned_contact_id = $this->getOption('default_assignee');
                }
            }
            if ($assigned_contact_id !== '') {
                $assigned_contact_id = (int) $assigned_contact_id;
            }
        }
        return $assigned_contact_id;
    }

    /**
     * Helper for execute()
     * Send noification email messages.
     */
    protected function sendMessages($request, $log)
    {
        $messages = $this->getOption('messages');
        if (empty($messages) || !is_array($messages)) {
            $messages = array();
        }

        // Add BCC recipients from POST
        $this->getBccFromPost($messages);

        // ADD following contacts recipients
        $this->getFollowingContactsMessage($messages, $request);

        /**
         * @event workflow_action_presend_messages
         * @param int $params['messages'] array of messages
         * @return void
         */
        $params = array(
            'messages' => $messages,
        );
        wa('helpdesk')->event('workflow_action_presend_messages', $params);
        $messages = ifset($params['messages'], array());

        if (empty($messages) || !is_array($messages)) {
            return;
        }

        $sender = new helpdeskSendMessages();
        $all_recipients = $sender->sendMessages($request, $log, $messages);

        $log['to'] = self::concatEmails($all_recipients);
    }

    /**
     * Helper for sendMessages()
     */
    protected function getBccFromPost(&$messages)
    {
        $bcc_to = waRequest::post('bcc', array(), 'array');
        if ($bcc_to) {
            $sender = new helpdeskSendMessages();
            $messages[] = array(
                'tmpl' => $sender->getBccTemplate(),
                'to' => $bcc_to,
            );
        }
    }

    protected function getFollowingContactsMessage(&$messages, helpdeskRequest $request)
    {
        $to = array();
        $fm = new helpdeskFollowModel();
        foreach ($fm->getFollowingContacts($request->id) as $c_id) {
            $to[$c_id] = 1;
        }
        if ($to) {
            $sender = new helpdeskSendMessages();
            $messages[] = array(
                'tmpl' => $sender->getBccTemplate(),
                'to' => $to,
                'exclude' => 1,
            );
        }
    }

    /*
     * End of execute() section
     * -------------------------------------------------------------------------------------------
     * Various helpers
     */

    /**
     * Helper to make human-readable list of recipients
     * @param $to array email => name
     * @return string
     */
    public static function concatEmails($to)
    {
        $result = array();
        foreach($to as $email => $name) {
            if (strlen($name) && strlen($email)) {
                $result[] = "$name <$email>";
            } else {
                $result[] = $name.$email;
            }
        }
        return implode(', ', $result);
    }

    /**
     * Default options to use when not specified in workflow config.
     */
    public function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), array(
            'name' => _w('New action'),
            'messages' => array(),
            'use_state_border_color' => false,
        ));
    }

    private $state_color = null;

    /**
     * Override waWorkflowEntity->getOption() to implement custom logic
     * for 'user_button_border_color' property: there is an option to take it from state color.
     */
    public function getOption($opt, $default=null)
    {
        if ($opt == 'user_button_border_color' && $this->getOption('use_state_border_color')) {
            if ($this->state_color === null) {
                $this->state_color = false;
                if ( ( $transition = $this->getWorkflow()->getTransition($this, ''))) {
                    if ( ( $state_id = $transition->getStateId())) {
                        try {
                            if ( ( $state = $this->getWorkflow()->getStateById($state_id))) {
                                $css = $state->getOption('list_row_css');
                                if (preg_match('~(?:^|[^\-])color\s*:([^;]+);~i', $css, $m)) {
                                    $this->state_color = trim($m[1]);
                                }
                            }
                        } catch (Exception $e) { }
                    }
                }
            }
            if ($this->state_color) {
                return $this->state_color;
            }
        }
        return parent::getOption($opt, $default);
    }

    public function getButton()
    {
        if ($this->getOption('type') === 'auto') {
            return null;
        }
        return parent::getButton();
    }

}
