<?php
/**
 * !!!
 */
class helpdeskFormSourceType extends helpdeskCommonST implements helpdeskFormSTInterface
{
    protected $form_constructor;
    public static $special_fields = array('subject', 'text', 'captcha', 'attachments');

    public function init()
    {
        $this->name = _w('Form');
    }

    protected function handleMessage($message)
    {
        $message = helpdeskSourceHelper::cleanMessage($message);
        $message['request'] = helpdeskSourceHelper::createRequest($message);

        $env = wa()->getEnv();

        $request_id = null;

        if ($env !== 'backend') {
            if (!empty($message['source']->params->antispam) && !wa()->getUser()->isAuth()) {
                // && (empty($message['client_contact']) || !$message['client_contact']->getId())) {
                // Antispam feature for unknown contacts: verify email address
                $this->triggerAntispam($message);
            } else {
                // Create new request from message
                $request_id = $this->saveNewRequest($message);
            }
        } else {
            $request_id = $this->saveNewRequest($message);
        }

        return $request_id;

    }

    /**
     * Frontend form HTML using templates/publicfrontend_front.html
     * @param helpdeskSource $source
     * @param array[string] string $extra_html extra html fragments. Possible keys 'bottom', 'top'
     * @return type
     * @throws SmartyCompilerException
     */
    public function getFormHtml(helpdeskSource $source, $extra_html = array())
    {
        $form_constructor = $this->getFormConstructor();

        if (waRequest::post('source')) {
            $this->postToSource($source);
        }

        $form_constructor_html = $form_constructor->getHtml($source);

        // When this form lives in plugin, this sets proper place to get localization from.
        waSystem::pushActivePlugin($this->getPlugin(), 'helpdesk');

        // Change locale to form's locale if specified
        $old_locale = null;
        if ($source->params->ifset('locale')) {
            $old_locale = wa()->getLocale();
            wa()->setLocale($source->params->locale);
        }


        // Reset the view. It is created again by $this->getView()
        $this->view = null;

        $env = wa()->getEnv();
        if (!empty($source->params->env)) {
            $env = $source->params->env;
        }

        // need for frontend
        $this->getView()->assign('fields', $form_constructor->getFields($source, $env));
        $this->getView()->assign('contact_fields', $form_constructor->getContactFields($source, $env));

        $action_url = $env === 'frontend' ?
                wa()->getRouteUrl('helpdesk/frontend/form', array()) :
                '?module=requests&action=save';

        $background_action_url = $env === 'frontend' ?
                wa()->getRouteUrl('helpdesk/frontend/formBackground', array()) :
                '';

        $this->getView()->assign('source', $source);
        $this->getView()->assign('uniqid', uniqid('f'));
        $this->getView()->assign('root_url', wa()->getRootUrl(true));
        $this->getView()->assign('action_url', $action_url);
        $this->getView()->assign('background_action_url', $background_action_url);
        $this->view->assign('captcha_url', wa()->getRootUrl(true).'captcha.php');
        $this->getView()->assign('form_constructor_html', $form_constructor_html);
        $this->getView()->assign('upload_image_url', wa()->getRouteUrl('helpdesk/frontend/uploadImage'));
        $this->getView()->assign('env', $env);


        if ($env === 'frontend') {

            $this->getView()->assign('formwidth',
                    !empty($source->params['formwidth'])
                        ? max(0, min($source->params['formwidth'], 600))
                        : 450);

            $this->getView()->assign('source', $source);

            $css = '';
            if (!empty($source->params['custom_css'])) {
                try {
                    $css = $this->getView()->fetch('string:'.$source->params['custom_css']);
                } catch (SmartyCompilerException $e) {
                    $message = preg_replace('/"[a-z0-9]{32,}"/'," of custom html content of form with id = {$source['id']}",$e->getMessage());
                    throw new SmartyCompilerException($message, $e->getCode());
                }
            }
            if (!$css) {
                $file = dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/form/include_form_frontend_default_css.html';
                $css = $this->getView()->fetch('string:'.  file_get_contents($file));
            }

            $this->getView()->assign('css', $css);

        } else {
            $default_css = file_get_contents(dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/form/include_form_frontend_default_css.html');
            $this->getView()->assign('default_css', $default_css);
        }

        $extra_html['bottom'] = ifset($extra_html['bottom'], '');
        $extra_html['top'] = ifset($extra_html['top'], '');
        $this->getView()->assign('extra_html', $extra_html);

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
        // Is it an antispam confirmation attempt?
        if (waRequest::get('confirmation')) {
            return parent::frontendSubmit($source);
        }

        $errors = array();
        $message = $this->workupSubmit($source, $errors, wa()->getEnv());
        if (!$message) {
            echo json_encode(array(
                'status' => 'error',
                'errors' => $errors,
            ));
            exit;
        }

        $request_id = null;

        try {
            // pass message to createNewRequest
            $request_id = $this->handleMessage($message);
        } catch (Exception $e) {
            echo json_encode(array(
                'status' => 'error',
                'errors' => array(
                    '' => $e->getMessage()
                )
            ));
            exit;
        }

        echo json_encode(array(
            'status' => 'ok',
            'data' => array(
                'request_id' => $request_id
            )
        ));
        exit;

    }

    protected function workupSubmit(helpdeskSource $source, &$errors, $form_env = null)
    {
        $fldc_data = waRequest::post("fldc_data");
        $fld_data = waRequest::post('fld_data');

        $name = ifset($fldc_data['name']);
        $email = ifset($fldc_data['email']);
        $phone =  ifset($fldc_data['phone']);
        $subject = ifset($fld_data['subject'], '');
        $text = ifset($fld_data['text'], '');

        if (is_array($email)) {
            $email = trim(ifset($fldc_data['email']['value']));
        }

        $charset = strtolower(waRequest::post('charset', ''));
        if ($charset && $charset != 'utf-8') {
            $t = @iconv($charset, 'utf-8//IGNORE', $name);
            if ($t) {
                $name = $t;
            }
            $t = @iconv($charset, 'utf-8//IGNORE', $text);
            if ($t) {
                $text = $t;
            }
            $t = @iconv($charset, 'utf-8//IGNORE', $subject);
            if ($t) {
                $subject = $t;
            }
        }

        // Change locale to form's locale if specified (for correct error texts)
        if ($source->params->ifset('locale')) {
            wa()->setLocale($source->params->locale);
        }

        $escape_allowed_tags = false;
        if (!empty($source->params->fld_text['redactor'])) {
            $escape_allowed_tags = true;
        }

        $name = html_entity_decode($name, ENT_QUOTES, 'UTF-8');
        $subject = html_entity_decode($subject, ENT_QUOTES, 'UTF-8');
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        if ($escape_allowed_tags) {
            $form_constructor = new helpdeskFormConstructor();
            $text = $form_constructor->escapeAllowedTags($text);
        }
        $text = htmlspecialchars($text, ENT_NOQUOTES);
        if ($escape_allowed_tags) {
            $form_constructor = new helpdeskFormConstructor();
            $text = $form_constructor->unescapeAllowedTags($text);
        }

        $attachments = array();
        $files = waRequest::file('fld_attachments');
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

        //
        // Validation
        //

        // Check captcha if enabled
        if (!empty($source->params->fld_captcha) && !wa()->getUser()->isAuth()) {
            if (!wa()->getCaptcha()->isValid()) {
                $errors['captcha'] = _ws('This field is required.');
            }
        }


        if ($email && !wao(new waEmailValidator())->isValid($email)) {
            $errors['fldc_data[email]'] = _ws('Invalid email');
        }

        $subject = trim($subject);
        if (!$subject) {
            if ($text) {
                $subject = mb_substr(strip_tags($text), 0, 100);
            }
        }

        foreach ($this->getFormConstructor()->getContactFields($source, $form_env) as $f_id => $f) {
            if (!empty($f['choosen']) && !empty($f['required']) && empty($fldc_data[$f_id])) {
                $errors["fldc_data[{$f_id}]"] = _ws('This field is required.');
            }
        }

        $agreement_checkbox_prefix = helpdeskFormConstructor::FIELD_AGREEMENT_CHECKBOX_ID_PREFIX;
        $agreement_checkbox_prefix_len = strlen($agreement_checkbox_prefix);

        foreach ($this->getFormConstructor()->getFields($source, $form_env) as $f_id => $f) {
            if ($f_id === 'attachments') {
                if (!empty($f['choosen']) && !empty($f['required']) && !$attachments) {
                    $errors["fld_data[{$f_id}]"] = _ws('This field is required.');
                }
            } else {

                if (substr($f_id, 0, $agreement_checkbox_prefix_len) === $agreement_checkbox_prefix &&
                    $f_id !== $agreement_checkbox_prefix &&
                    empty($fld_data[$f_id])) {
                        $errors["fld_data[{$f_id}]"] = '';
                }

                if (!empty($f['choosen']) && !empty($f['required']) && empty($fld_data[$f_id])) {
                    $errors["fld_data[{$f_id}]"] = _ws('This field is required.');
                }

            }
        }

        // Form JS expects JSON with errors as a result
        if ($errors) {
            return false;
        }

        $source_type = $source->getSourceType();

        // Data to create request from
        $message = array(
            'source' => $source,
            'message_id' => $source->id.'/'.uniqid($source_type->getType().'_', true),
            'summary' => $subject,
            'text' => $text,
            'attachments' => $attachments,
            'params' => array(),
            'data' => array(),
            'client_contact' => null,
        );
        if (wa()->getUser()->isAuth()) {
            $message['creator_contact'] = wa()->getUser();
        }

        // if this form located in backend, first of all try get contact by contact_id
        if ($form_env === 'backend') {
            if (waRequest::post('contact_id')) {
                try {
                    $contact = new waContact(waRequest::post('contact_id', null, waRequest::TYPE_INT));
                    $contact->getName();
                    $contact_id = $contact->getId();
                    $message['client_contact'] = $contact;
                } catch (Exception $e) {

                }
            }
        } elseif (wa()->getUser()->isAuth()) {
            $message['client_contact'] = wa()->getUser();
        }

        // look up contact by email
        if (!$message['client_contact'] && $email) {
            $col = new waContactsCollection('search/email=' . $email);
            $contact = $col->getContacts('*', 0, 1);
            if ($contact) {
                reset($contact);
                $contact_id = key($contact);
                $message['client_contact'] = new waContact($contact_id);
            }
        }
        if (!$message['client_contact']) {
            foreach ($source->params as $fld_id => $val) {
                if (strpos($fld_id, 'fldc_') === 0 && $val->identification === true) {
                    $field = substr($fld_id, 5);
                    if ($value = trim(ifset($fldc_data[$field]))) {
                        if ($field == 'phone') {
                            $value = preg_replace('/[^0-9]/', '', $value);
                        }
                        $col = new waContactsCollection('search/' . $field . '=' . $value);
                        $contact = $col->getContacts('*', 0, 1);
                        if ($contact) {
                            reset($contact);
                            $contact_id = key($contact);
                            $message['client_contact'] = new waContact($contact_id);
                        } else {
                            if ($field == 'phone' && strlen($value) > 10) { // attempt #2
                                $value = substr($value, -10);
                                $col = new waContactsCollection('search/' . $field . '=' . $value);
                                $contact = $col->getContacts('*', 0, 1);
                                if ($contact) {
                                    reset($contact);
                                    $contact_id = key($contact);
                                    $message['client_contact'] = new waContact($contact_id);
                                }
                            }
                        }
                    }
                }
            }
        }

        foreach ($this->getFormConstructor()->getContactFields($source, $form_env) as $f_id => $field) {
            if (!empty($fldc_data[$f_id])) {
                $message['client_contact'][$f_id] = $fldc_data[$f_id];
            }
        }

        $fld_values = array();
        foreach ($fld_data as $f_id => $f_val) {
            if (!in_array($f_id, self::$special_fields)) {
                $message['data'][$f_id] = $f_val;
                $fld_values[] = $fld_data[$f_id];
            }
        }
//        foreach ($this->getFormConstructor()->getFields($source, $form_env) as $f_id => $field) {
//            if (!in_array($f_id, self::$special_fields)) {
//                if (!empty($fld_data[$f_id])) {
//                    $message['data'][$f_id] = $fld_data[$f_id];
//                    $fld_values[] = $fld_data[$f_id];
//                }
//            }
//        }

        // save contact data from the Post to helpdesk_request_data
        // see helpdeskCommonST->saveNewRequest for details
        if ($fldc_data && is_array($fldc_data)) {
            foreach ($fldc_data as $f_id => $fld_val) {
                if (is_string($fld_val)) {
                    if ($fld_val = trim($fld_val)) {
                        $message['data'][helpdeskRequestDataModel::PREFIX_CONTACT . $f_id] = $fld_val;
                    }
                } else if (is_array($fld_val)) {
                    foreach ($fld_val as $k => $v) {
                        if ($v = trim($v)) {
                            // IMPORTANT: for composite fields ':' for seperate field_id from subfield_id
                            $message['data'][helpdeskRequestDataModel::PREFIX_CONTACT . $f_id . ':' . $k] = $v;
                        }
                    }
                }
            }
        }

        if (!$subject) {
            $subject = mb_substr(strip_tags(implode(', ', $fld_values)), 0, 100);
            $message['summary'] = $subject;
            $message['params']['subject'] = $subject;
        }

        return $message;
    }

    //
    // End of frontend submit logic
    //

    //
    // Settings editor logic
    //

    public function settingsPrepareView($submit_errors, $source, $wf)
    {
        $form_html = '';
        if ($source) {
            $form_html = $this->getFormHtml($source);
        }
        $this->view = null;
        $this->getView()->assign('form_html', $form_html);

        parent::settingsPrepareView($submit_errors, $source, $wf);
    }

    protected function postToSource(helpdeskSource $source)
    {
        unset(
            $source->params->redirect_after_submit,
            $source->params->html_after_submit
        );

        $source_data = waRequest::post('source', null, 'array');
        if ($source_data) {
            foreach($source_data as $k => $v) {
                if ($source->keyExists($k)) {
                    $source[$k] = $v;
                }
            }
        }

        $post_data = waRequest::post('params');
        $form_constructor = $this->getFormConstructor();
        $form_constructor->postToSource($source, $post_data);

        foreach ($post_data as $k => $val) {
            $source->params[$k] = $val;
        }

        if (empty($post_data['messages'])) {
            $source->params['messages'] = null;
        }
    }

    protected function settingsValidationErrors($source)
    {
        $result = array();
        if (!empty($source->params->after_submit) && $source->params->after_submit == 'redirect'
            && empty($source->params->redirect_after_submit)) {
            $result['params[redirect_after_submit]'] = _ws('This field is required.');
        }
        return $result;
    }

    //
    // End of settings editor logic
    //

    public function getRequestParams()
    {
        return array();
    }

    protected function buildNewSource()
    {
        $source = parent::buildNewSource();
        $source->params->setAll(array(
            'fldc_name' => array( 'caption' => _w('Name'), ),
            'fldc_email' => array( 'caption' => _w('Email'), ),
            'fld_subject' => array( 'caption' => _w('Subject'), ),
            'fld_text' => array( 'caption' => _w('Text'), ),
            'html_after_submit' => self::getDefaultParam('html_after_submit'),
            'html_after_submit_auth' => self::getDefaultParam('html_after_submit_auth')
        ));
        return $source;
    }

    // declared public to use in install.php
    public static function getDefaultParam($p)
    {
        switch ($p) {
            case 'html_after_submit':
                return _w('Thank you for sending request to our support team!');
            case 'html_after_submit_auth':
                return _w('Thank you for sending request to our support team!');
        }
        return null;
    }

    // @deprecated
    public function isFormEnabled(helpdeskSource $source)
    {
        return true;
    }

    /**
     * @return helpdeskFormConstructor
     */
    function getFormConstructor()
    {
        if ($this->form_constructor === null) {
            $this->form_constructor = new helpdeskFormConstructor();
        }
        return $this->form_constructor;
    }

    public static function getPreviewHash()
    {
        $app_settings_model = new waAppSettingsModel();
        $hash = $app_settings_model->get('helpdesk', 'form_preview_hash');
        if ($hash) {
            $hash_parts = explode('.', $hash);
            if (time() - $hash_parts[1] > 14400) {
                $hash = '';
            }
        }
        if (!$hash) {
            $hash = uniqid().'.'.time();
            $app_settings_model->set('helpdesk', 'form_preview_hash', $hash);
        }

        return md5($hash);
    }

    protected function getCssViewContent($type)
    {
        $css = '';
        $file = dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/form/include_form_frontend_'.$type.'_css.html';
        if (file_exists($file)) {
            $css = file_get_contents($file);
        }
        return $css;
    }
}
