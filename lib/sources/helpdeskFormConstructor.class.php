<?php
/**
 * !!!
 */
class helpdeskFormConstructor
{
    const FIELD_AGREEMENT_CHECKBOX_ID_PREFIX = '!agreement_checkbox';

    public function getHtml(helpdeskSource $source)
    {
        $view = wa()->getView();

        $vars = $view->getVars();
        $view->clearAllAssign();

        $app_id = wa()->getApp();
        wa('site')->setActive('site');
        $site_url = wa('site')->getUrl();
        wa()->setActive($app_id);

        $view->assign(array(
            'source' => $source,
            'fields' => $this->getFields($source),
            'contact_fields' => $this->getContactFields($source),
            'captcha_url' => wa()->getRootUrl(true).'captcha.php',
            'domains' => array_keys(wa()->getRouting()->getByApp(wa()->getApp())),
            'site_url' => $site_url . '#/personal/app/helpdesk/',
        ));

        $dir = dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/form/';

        $html = $view->fetch($dir . 'form_constructor.html');

        $view->assign($vars);

        return $html;
    }


    protected function extractFromPost(array &$post_data = array())
    {
        foreach ($post_data as $param_key => $value) {
            if (in_array($param_key, array('button_caption', 'after_submit', 'formwidth')) || substr($param_key, 0, 4) === helpdeskRequestLogParamsModel::PREFIX_REQUEST || substr($param_key, 0, 5) === 'fldc_') {
                $source_params[$param_key] = $value;
                unset($post_data[$param_key]);
            }
        }
        return $source_params;
    }

    public function postToSource(helpdeskSource $source, &$post_data = array())
    {
        $fields = $this->getFields($source);
        $contact_fields = $this->getContactFields($source);

        foreach ($fields as $f_id => $f) {
            unset($source->params[helpdeskRequestLogParamsModel::PREFIX_REQUEST . $f_id]);
        }
        foreach ($contact_fields as $f_id => $f) {
            unset($source->params['fldc_' . $f_id]);
        }

        $source_params = $this->extractFromPost($post_data);

        $sorts = array(
        helpdeskRequestLogParamsModel::PREFIX_REQUEST => 0,
            'fldc_' => 0
        );
        $param_keys = array(
            'caption',
            'placeholder',
            'captionplace',
            'required',
            'view',
            'redactor',
            'text',
            'subfields_captionplace',
            'identification',
            'html_label',
            'default_checked'
        );

        if ($source_params && is_array($source_params)) {
            foreach($source_params as $k => $val) {

                foreach (array(helpdeskRequestLogParamsModel::PREFIX_REQUEST, 'fldc_') as $prefix) {
                    $len_prefix = strlen($prefix);
                    if (substr($k, 0, $len_prefix) === $prefix) {
                        $excl = substr($k, $len_prefix, 1) === '!';
                        $val = json_decode($val, true);

                        if (!$excl && !is_array($val)) {
                            $val = array(
                                'caption' => $this->fields[substr($k, $len_prefix)]['name']
                            );
                        }

                        if (!isset($val['caption'])) {
                            if ($k !== 'fld_captcha') {
                                $val['caption'] = isset($val['name']) ? $val['name'] : '';
                            } else {
                                $val['caption'] = '';
                            }
                        }

                        foreach (array_keys($val) as $param_k) {
                            if (!in_array($param_k, $param_keys) ||
                                    ($param_k !== 'caption' && empty($val[$param_k]))) {
                                unset($val[$param_k]);
                            }
                        }
                        $val['sort'] = $sorts[$prefix];
                        $sorts[$prefix] += 1;
                    }
                }

                if (!$val) {
                    continue;
                }

                $source->params[$k] = $val;
            }
        }
    }

    public function getContactFields(helpdeskSource $source, $form_env = null)
    {
        $all_fields = array();
        $prefix = 'fldc_';
        $exclude = array(); // array('name', 'firstname', 'middlename', 'lastname', 'title', 'sex');

        $contact_fields = waContactFields::getAll();

        foreach ($contact_fields as $field_id => $fld) {
            if (wa()->getEnv() == 'frontend' && wa()->getUser()->isAuth()) {
                if (in_array($field_id, $exclude)) {
                    continue;
                }
            }
            $field = array(
                'id' => $fld->getId(),
                'name' => $fld->getName(),
                'type' => $fld->getType(),
                'placeholder_need' =>
                    ($fld instanceof waContactSelectField
                        || $fld instanceof waContactBirthdayField || $fld instanceof waContactAddressField
                        ) ? false : true,
                'value' => '',
            );
            if (strtolower($field['type']) == 'hidden') {
                continue;
            }
            if ($field_id === 'name') {
                $field['name'] = _w('Full name');
            }

            $param_key = $prefix . $field_id;
            if (!empty($source['params'][$param_key])) {
                $field['choosen'] = true;
                if ($source['params'][$param_key] instanceof waArrayObject) {
                    foreach ($source['params'][$param_key]->toArray() as $param_id => $param_val) {
                        $field[$param_id] = $param_val;
                    }
                }
            }

            $field_params = array(
                'namespace' => "{$prefix}data"
            );

            if (!empty($field['value'])) {
                $field_params['value'] = $field['value'];
            }

            $attrs = 'placeholder="' . ifset($field['placeholder'], '') . '"';

            if (wa()->getEnv() == 'frontend') {
                if ($field_id === 'address') {
                    $field_params['xhr_url'] = wa()->getRouteUrl('helpdesk/frontend/regions', array());
                    $field_params['xhr_cross_domain'] = true;
                }
                if (wa()->getUser()->isAuth() && empty($field_params['value'])) {
                    $field_params['value'] = wa()->getUser()->get($field_id);
                    if (is_array($field_params['value'])) {
                        $field_params['value'] = array_shift($field_params['value']);
                    }
                    $field['value'] = $field_params['value'];
                }
            } else {
                if (!$form_env) {
                    $attrs .= 'disabled="disabled"';
                }
            }
            $field['html'] = $this->renderContactField($fld, $field_params, $attrs, $form_env);
            $all_fields[$field_id] = $field;
        }

        // insert special pseudo fields: !hrule, !paragraph
        foreach ($source['params'] as $name => $params) {
            if (substr($name, 0, 6) === 'fldc_!') {
                $id = substr($name, 5);
                $params['id'] = $id;
                $params['special'] = true;
                $params['choosen'] = true;
                $params['multi'] = false;
                $parts = explode('_', $id);
                if (count($parts) > 1) {
                    $last = array_pop($parts);
                    if (is_numeric($last)) {
                        $params['multi'] = true;
                    } else {
                        $parts[] = $last;
                    }
                }
                $params['excl'] = true;
                $params['type'] = implode('_', $parts);
                $all_fields[$id] = $params->toArray();
            }
        }

        $form_fields = array();
        $other_fields = array();

        $sort = 0;
        foreach ($all_fields as $field_id => $field) {
            $field['id'] = $field_id;
            if (!empty($field['choosen'])) {
                $sort = ifset($field['sort'], $sort);
                $field['sort'] = $sort;
                if (!isset($form_fields[$sort])) {
                    $form_fields[$sort] = $field;
                } else {
                    $sort += 1;
                    $form_fields[$sort] = $field;
                }
                $sort += 1;
            } else {
                $other_fields[$field['id']] = $field;
            }
        }
        ksort($form_fields);

        $all_fields = array();
        foreach ($form_fields as $field) {
            $all_fields[$field['id']] = $field;
        }
        $all_fields = $all_fields + $other_fields;

        return $all_fields;
    }

    /**
     * @param waContactField $field
     * @param $field_params
     * @param $attrs
     * @param $form_env
     * @return string
     */
    protected function renderContactField($field, $field_params, $attrs, $form_env)
    {
        if (!($field instanceof waContactBranchField) || wa()->getEnv() == 'frontend' || $form_env) {
            return $field->getHTML($field_params, $attrs);
        }
        $hide_param = $field->getParameter('hide');
        if (empty($hide_param) || !is_array($hide_param)) {
            return $field->getHTML($field_params, $attrs);
        }
        $field->setParameter('hide', array());
        $html = $field->getHTML($field_params, $attrs);
        $field->setParameter('hide', $hide_param);
        return $html;
    }

    protected function renderCaptcha($form_env = null)
    {
        if (wa()->getEnv() == 'frontend' || $form_env) {
            return wa('helpdesk')->getCaptcha()->getHtml();
        }
        $img_url = 'img/waCaptchaImg.png';
        $isReCaptcha = waCaptcha::getCaptchaType('helpdesk') == 'waReCaptcha';
        if ($isReCaptcha) {
            $img_url = 'img/reCaptchaEN.png';
            if (wa()->getLocale() === 'ru_RU') {
                $img_url = 'img/reCaptchaRU.png';
            }
        }

        $view = wa()->getView();
        $old_vars = $view->getVars();
        $view->clearAllAssign();
        $view->assign(array(
            'img_url' => $img_url,
            'isReCaptcha' => $isReCaptcha
        ));
        $html = $view->fetch(wa()->getAppPath('lib/sources/templates/form/form_constructor_captcha.html', 'helpdesk'));
        $view->assign($old_vars);
        return $html;

    }

    public function getFields(helpdeskSource $source, $form_env = null)
    {
        $fields = array(
            'subject' => array(
                'id' => 'subject',
                'name' => _w('Subject'),
                'placeholder_need' => true,
            ),
            'text' => array(
                'id' => 'text',
                'name' => _w('Text'),
                'placeholder_need' => true,
            ),
            'attachments' => array(
                'id' => 'attachments',
                'name' => _w('Attachments'),
                'placeholder_need' => false,
            )
        );
        if (wa()->getEnv() == 'backend' || !wa()->getUser()->isAuth()) {
            $fields['captcha'] = array(
                'id' => 'captcha',
                'name'  => _w('Captcha'),
                'placeholder_need' => true,
            );
            $fields['captcha']['html'] = $this->renderCaptcha($form_env);
        }

        $all_fields = helpdeskRequestFields::getFields() + $fields;

        $form_fields = array();
        $other_fields = array();

        $prefix = helpdeskRequestLogParamsModel::PREFIX_REQUEST;
        foreach ($all_fields as $field_id => &$field) {

            $info = array();
            if (is_array($field)) {
                $info = $field;
            }

            if (!empty($source['params'][$prefix . $field_id])) {
                $info['choosen'] = true;
                if ($source['params'][$prefix . $field_id] instanceof waArrayObject) {
                    foreach ($source['params'][$prefix . $field_id]->toArray() as $param_id => $param_val) {
                        $info[$param_id] = $param_val;
                    }
                }
            }

            if ($field instanceof helpdeskRequestField) {

                $info['id'] = $field->getId();
                $info['name'] = $field->getName();
                $info['type'] = $field->getType();

                $params = array();
                if (!empty($info['view'])) {
                    $params['view'] = $info['view'];
                }
                $params['namespace'] = 'fld_data';
                $params['value'] = ifset($info['value'], '');

                $attrs = '';
                if (!$form_env) {
                    $attrs .= 'disabled="disabled"';
                }

                $attrs .= 'placeholder="' . ifset($info['placeholder'], '') . '"';

                $info['html'] = $field->getHTML($params, $attrs);
                if ($info['type'] === 'Select') {
                    $info['options'] = $field->getOptions();
                }

                $info['placeholder_need'] = !($field instanceof helpdeskRequestSelectField || $field instanceof helpdeskRequestCheckboxField);
            }

            $field = $info;

        }
        unset($field);

        // insert special pseudo fields: !hrule, !paragraph
        $prefix = helpdeskRequestLogParamsModel::PREFIX_REQUEST;
        foreach ($source['params'] as $name => $params) {

            if (substr($name, 0, 5) !== $prefix . '!') {
                continue;
            }

            $id = substr($name, 4);

            if ($id === self::FIELD_AGREEMENT_CHECKBOX_ID_PREFIX) {
                continue;
            }

            $params['id'] = $id;
            $params['special'] = true;
            $params['choosen'] = true;
            $params['multi'] = false;
            $parts = explode('_', $id);
            if (count($parts) > 1) {
                $last = array_pop($parts);
                if (is_numeric($last)) {
                    $params['multi'] = true;
                } else {
                    $parts[] = $last;
                }
            }
            $params['excl'] = true;
            $params['type'] = implode('_', $parts);
            if ($params['type'] === '!paragraph') {
                if (strpos($params['text'], '<p>') === false) {
                    $params['text'] = '<p>' . $params['text'] . '</p>';
                }
            }
            $all_fields[$id] = $params->toArray();
        }

        $all_fields = $this->addAgreementCheckboxes($source, $all_fields);

        foreach ($all_fields as $field_id => $field) {
            if (!empty($field['choosen'])) {
                $field['sort'] = isset($field['sort']) ? (int)$field['sort'] : 0;
                $form_fields[] = $field;
            } else {
                $other_fields[$field['id']] = $field;
            }
        }

        usort($form_fields, wa_lambda('$a, $b', 'return $a["sort"] - $b["sort"];'));
        $form_fields = array_values($form_fields);
        
        $top_fields = array();

        $top_fields_order = array(
            'subject',
            'text',
            'attachments',
            'captcha',
            '!agreement_checkbox'
        );
        foreach ($top_fields_order as $field_id) {
            if (isset($other_fields[$field_id])) {
                $top_fields[$field_id] = $other_fields[$field_id];
                unset($other_fields[$field_id]);
                $top_fields[$field_id]['top'] = true;
            }
        }

        $other_fields = $top_fields + $other_fields;

        $all_fields = array();
        foreach ($form_fields as $field) {
            $all_fields[$field['id']] = $field;
        }
        $all_fields = $all_fields + $other_fields;

        if ($form_env === 'backend') {
            unset($all_fields['captcha']);
        }

        return $all_fields;
    }

    public function escapeAllowedTags($text)
    {
        $map = array();
        $lt = '#!ESCAPE_OPEN_BRACKET#!';
        $lts = '#!ESCAPE_OPEN_BRACKET_SLASH#!';
        $gt = '#!ESCAPE_CLOSED_BRACKET#!';
        foreach (array(
            'b',
            'i',
            'u',
            'em',
            'strong',
            'p',
            'ol',
            'ul',
            'li',
            'a',
            'img',
            'span',
            'br') as $t)
        {
            $map["<".$t."(.*?)>"] = $lt.$t."$1".$gt;
            $map["<\/".$t.">"] = $lts.$t.$gt;
        }
        foreach ($map as $t => $r)  {
            $text = preg_replace("/".$t."/i", $r, $text);
        }

        $text = preg_replace("/href=['\"]\s*?javascript:['\"]/", "href=''", $text);
        return $text;
    }

    public function unescapeAllowedTags($text)
    {
        $map = array();
        $lt = '#!ESCAPE_OPEN_BRACKET#!';
        $lts = '#!ESCAPE_OPEN_BRACKET_SLASH#!';
        $gt = '#!ESCAPE_CLOSED_BRACKET#!';
        foreach (array(
            'b',
            'i',
            'u',
            'em',
            'strong',
            'p',
            'ol',
            'ul',
            'li',
            'a',
            'img',
            'span',
            'br') as $t)
        {
            $map[$lt.$t."(.*?)".$gt] = "<".$t."$1>";
            $map[$lts.$t.$gt] = "</".$t.">";
        }
        foreach ($map as $t => $r)  {
            $text = preg_replace("/".$t."/i", $r, $text);
        }

        // Remove harmful attributes that survived escaping
        $all_harmful_attributes = [
            'onAbort',
            'onBlur',
            'onChange',
            'onClick',
            'onDblClick',
            'onDragDrop',
            'onError',
            'onFocus',
            'onKeyDown',
            'onKeyPress',
            'onKeyUp',
            'onLoad',
            'onMouseDown',
            'onMouseMove',
            'onMouseOut',
            'onMouseOver',
            'onMouseUp',
            'onMove',
            'onReset',
            'onResize',
            'onSelect',
            'onSubmit',
            'onUnload'
        ];

        // collect only existing in this text attributes, to prevent build very complicated (in complexity meaning) regexp that couldn't work on long text
        $harmful_attributes = [];
        foreach ($all_harmful_attributes as $attribute) {
            if (stripos($text, $attribute) !== false) {
                $harmful_attributes[] = $attribute;
            }
        }

        if ($harmful_attributes) {
            $pattern = '(?:' . join('|', $harmful_attributes) . ')';
            $pattern = '~<([^>]*?)' . $pattern . '[^>]*?>~is';
            $text = preg_replace($pattern, '<$1>', $text);
        }

        $text = preg_replace('~expression\s*\(~is', '_expression_ (', $text);
        $text = preg_replace('~javascript:~is', '_javascript_ : ', $text);

        return $text;
    }

    protected function getAgreementCheckbox(helpdeskSource $source, $index = null)
    {
        $field_id = self::FIELD_AGREEMENT_CHECKBOX_ID_PREFIX . ($index !== null ? "_{$index}" : '');
        $field = new helpdeskAgreementCheckboxField($field_id, _w('Consent to terms'));

        $prefix = helpdeskRequestLogParamsModel::PREFIX_REQUEST;
        $is_frontend = wa()->getEnv() === 'frontend';

        $params = $source['params']->toArray();
        $params = (array) ifset($params[$prefix . $field->getId()]);

        $attrs = array();
        if (!$is_frontend) {
            $attrs[] = 'disabled="disabled"';
        }

        $default_checked = ifset($params['default_checked']) ? 1 : 0;
        if ($default_checked) {
            $attrs[] = 'checked="checked"';
        }

        $attrs = join(' ', $attrs);

        $html_label = ifset($params['html_label']);
        $html_params = array(
            'html_label' => $html_label,
        );
        if ($is_frontend) {
            $html_params['namespace'] = "{$prefix}data";
        }

        $html = $field->getHTML($html_params, $attrs);
        if ($html_label === null) {
            $html_label = $field->getDefaultHtmlLabel(true);
        }

        return array(
            'id' => $field->getId(),
            'name'  => $field->getName(),
            'html' => $html,
            'multi' => true,
            'type' => self::FIELD_AGREEMENT_CHECKBOX_ID_PREFIX,
            'html_label' => $html_label,
            'default_checked' => $default_checked ? 1 : 0,
            'excl' => true,
            'captionplace' => 'none',
            'html_label_default_href_placeholder' => $field->getDefaultLinkHrefPlaceholder()
        );
    }

    protected function addAgreementCheckboxes(helpdeskSource $source, $fields)
    {
        $prefix = helpdeskRequestLogParamsModel::PREFIX_REQUEST;
        $prefix_len = strlen($prefix);
        $checkbox = $this->getAgreementCheckbox($source);
        $checkbox_id = $checkbox['id'];
        $checkbox_id_len = strlen($checkbox_id);

        $checkboxes = array();

        foreach ($source['params'] as $name => $params) {
            if (substr($name, 0, $prefix_len) !== $prefix) {
                continue;
            }
            if (substr($name, $prefix_len, $checkbox_id_len) !== $checkbox_id) {
                continue;
            }
            $index = (int) substr($name, $prefix_len + $checkbox_id_len + 1);
            $field = $this->getAgreementCheckbox($source, $index);
            $field = array_merge($field, $params->toArray());
            $field['choosen'] = true;
            $field['special'] = true;
            $checkboxes[] = $field;
        }

        $fields[$checkbox_id] = $checkbox;
        $fields[$checkbox_id]['choosen'] = false;

        foreach ($checkboxes as $chbx) {
            $fields[$chbx['id']] = $chbx;
        }
        return $fields;
    }
}
