<?php

abstract class helpdeskRequestField
{
    protected $id;
    /**
     * Available options
     *
     * array(
     *     'sort' => bool,        // ?..
     *     'unique' => bool,      // only allows unique values
     *     'required' => bool,    // is required in visual contact editor
     *     'multi' => bool,
     *     // for multi fields
     *     'ext' => array(
     *             'ext1' => 'ext1 Name',
     *             ...
     *     ),
     *     // subfields for composite fields
     *     'fields' => array(
     *             new waContactField($sub_id, $sub_name, $sub_options),
     *             ...
     *     ),
     *     // any options for specific field type
     *     ...
     * )
     */
    protected $options;

    /** array(locale => name) */
    protected $name = array();

    /** used by __set_state() */
    protected $_type;

    static protected $fields;

    /**
     * Constructor
     *
     * Because of a specific way this class is saved and loaded via var_dump,
     * constructor parameters order and number cannot be changed in subclasses.
     * Subclasses also must always provide a call to parent's constructor.
     *
     * @param string $id
     * @param mixed $name either a string or an array(locale => name)
     * @param array $options
     */
    public function __construct($id, $name, $options = array())
    {
        $this->id = $id;
        $this->setParameter('localized_names', $name);
        $this->options = $options;
        $this->_type = get_class($this);
        $this->init();
    }


    protected function init()
    {

    }

    /**
     * Returns id of the field
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }


    public function getInfo()
    {
        $info = array(
            'id' => $this->id,
            'name' => $this->getName(),
            'multi' => $this->isMulti(),
            'type' => $this->getType(),
            'unique' => $this->isUnique(),
            'required' => $this->isRequired(),
        );
        if ($this->isMulti() && isset($this->options['ext'])) {
            $info['ext'] = $this->options['ext'];
            foreach ($info['ext'] as &$ext) {
                $ext = _ws($ext);
            }
        }
        return $info;
    }

    /**
     * Returns name of the field
     *
     * @param string $locale - locale
     * @return string
     */
    public function getName($locale = null, $escape = false)
    {
        if (!$locale) {
            $locale = waSystem::getInstance()->getLocale();
        }

        $name = '';
        if (isset($this->name[$locale])) {
            $name = $this->name[$locale];
        } else if (isset($this->name['en_US'])) {
            if ($locale == waSystem::getInstance()->getLocale() && wa()->getEnv() == 'backend') {
                $name = _ws($this->name['en_US']);
            } else {
                $name = waLocale::translate('webasyst', $locale, $this->name['en_US']);
            }
        } else {
            $name = reset($this->name); // reset() returns the first value
        }
        return $escape ? htmlspecialchars($name, ENT_QUOTES) : $name;
    }

    public function isMulti()
    {
        return isset($this->options['multi']) && $this->options['multi'];
    }

    public function isUnique()
    {
        return isset($this->options['unique']) && $this->options['unique'];
    }

    public function isRequired()
    {
        return isset($this->options['required']) && $this->options['required'];
    }


    public function isExt()
    {
        return $this->isMulti() && isset($this->options['ext']);
    }

    public function get(helpdeskRequest $request, $format = null)
    {
        $data = $request->getData();
        if ($this->isMulti()) {
            if ($format) {
                return $this->format(ifset($data[$this->id], array()), $format);
            } else {
                return ifset($data[$this->id], array());
            }
        } else {
            $val = ifset($data[$this->id], array());
            if ($val) {
                if ($format) {
                    return $this->format(reset($val), $format);
                } else {
                    return reset($val);
                }
            }
            return null;
        }
    }

    /**
     * Prepare value to be stored in DB.
     */
    public function set(helpdeskRequestField $field, $value, $params = array(), $add = false)
    {
        $this->setValue($value);
    }

    /**
     * Helper for $this->set() to format value of a field before inserting into DB
     * (actually even before validation, so beware).
     *
     * $value is a single value passed to waContact['field_id'] via assignment.
     * No extension, just the value, e.g.: 'a@b.com', not array('value' => 'a@b.com', 'ext' => 'work').
     * Note that for composite fields this behaves a little differently, see waContactCompositeField.
     *
     * @param mixed $value
     * @return mixed possibly changed $value
     */
    protected function setValue($value)
    {
        return $value;
    }

    protected function _format($data, $format = null)
    {
        if (is_array($data) && isset($data['value'])) {
            return $data['value'];
        } else if ($data || $data === '0' || $data === 0) {
            return $data;
        } else {
            return '';
        }
    }


    public function format($data, $format = null)
    {
        $val = $this->_format($data, $format);
        if ($format === 'html') {
            return htmlspecialchars($val);
        } else {
            return $val;
        }
    }

    public function getField()
    {
        return $this->getId();
    }

    /**
     * @return string
     */
    public function getType()
    {
        if (isset($this->options['type'])) {
            return $this->options['type'];
        }
        return str_replace(array('helpdeskRequest', 'Field'), array('', ''), get_class($this));
    }

    /**
     * Get the current value of option $p.
     * Used by a Request field constructor editor to access field parameters.
     *
     * waContactField has one parameter: localized_names = array(locale => name)
     *
     * @param $p string parameter to read
     * @return array|null
     */
    public function getParameter($p)
    {
        if ($p == 'localized_names') {
            return $this->name;
        }

        if (!isset($this->options[$p])) {
            return null;
        }
        return $this->options[$p];
    }

    /**
     * Set the value of option $p.
     * Used by a Request field constructor editor to change field parameters.
     *
     * localized_names = array(locale => name)
     * required = boolean
     * unique = boolean
     *
     * @param $p string parameter to set
     * @param $value mixed value to set
     */
    public function setParameter($p, $value)
    {
        if ($p == 'localized_names') {
            if (is_array($value)) {
                if (!$value) {
                    $value['en_US'] = '';
                }
                $this->name = $value;
            } else {
                $this->name = array('en_US' => $value);
            }
            return;
        }

        $this->options[$p] = $value;
    }

    public function getParameters()
    {
        $options = $this->options;
        $options['localized_names'] = $this->name;
        return $options;
    }

    /**
     * Set array of parameters
     * @param array $param parameter => value
     * @throws waException
     */
    public function setParameters($param)
    {
        if (!is_array($param)) {
            throw new waException('$param must be an array: '.print_r($param, TRUE));
        }
        foreach($param as $p => $val) {
            $this->setParameter($p, $val);
        }
    }

    protected function getHTMLName($params)
    {
        $prefix = $suffix = '';
        if (isset($params['namespace'])) {
            $prefix .= $params['namespace'].'[';
            $suffix .= ']';
        }
        if (isset($params['parent'])) {
            if ($prefix) {
                $prefix .= $params['parent'].'][';
            } else {
                $prefix .= $params['parent'].'[';
                $suffix .= ']';
            }
        }

        if (isset($params['multi_index'])) {
            if (isset($params['parent'])) {
                // For composite multi-fields multi_index goes before field id:
                // namespace[parent_name][i][field_id]
                $prefix .= $params['multi_index'].'][';
            } else {
                // For non-composite multi-fields multi_index goes after field id:
                // namespace[field_id][i]
                $suffix = ']['.$params['multi_index'].$suffix;
            }
        }
        $name = isset($params['id']) ? $params['id'] : $this->getId();

        return $prefix.$name.$suffix;
    }

    public function getHtmlOne($params = array(), $attrs = '')
    {
        $value = isset($params['value']) ? $params['value'] : '';
        $ext = null;

        if (is_array($value)) {
            $ext = $this->getParameter('force_single') ? null : ifset($value['ext'], '');
            $value = ifset($value['value'], '');
        }

        $name_input = $name = $this->getHTMLName($params);
        if ($this->isMulti() && $ext) {
            $name_input .= '[value]';
        }

        $disabled = '';
        if (wa()->getEnv() === 'frontend' && isset($params['my_profile']) && $params['my_profile'] == '1') {
            $disabled = 'disabled="disabled"';
        }

        $result = '<input '.$attrs.' '.$disabled.' type="text" name="'.htmlspecialchars($name_input).'" value="'.htmlspecialchars($value).'">';
        if ($ext) {
            // !!! add a proper <select>?
            $result .= '<input type="hidden" '.$disabled.' name="'.htmlspecialchars($name.'[ext]').'" value="'.htmlspecialchars($ext).'">';
        }

        return $result;
    }

    public function getHtmlOneWithErrors($errors, $params = array(), $attrs = '')
    {
        // Validation errors?
        $errors_html = '';
        if (!empty($errors)) {
            if (!is_array($errors)) {
                $errors = array((string) $errors);
            }
            foreach($errors as $error_msg) {
                if (is_array($error_msg)) {
                    $error_msg = implode("<br>\n", $error_msg);
                }
                $errors_html .= "\n".'<em class="errormsg">'.htmlspecialchars($error_msg).'</em>';
            }

            $attrs = preg_replace('~class="~', 'class="error ', $attrs);
            if (false === strpos($attrs, 'class="error')) {
                $attrs .= ' class="error"';
            }
        }

        return $this->getHtmlOne($params, $attrs).$errors_html;
    }

    public function getHTML($params = array(), $attrs = '')
    {
        if ($this->isMulti()) {
            if (!empty($params['value']) && is_array($params['value']) && !empty($params['value'][0])) {
                // Multi-field with at least one value
                $params_one = $params;
                unset($params_one['validation_errors']);
                $i = 0;
                $result = array();
                while (isset($params['value'][$i])) {
                    if (!empty($params['value'][1])) {
                        $params_one['multi_index'] = $i;
                    }
                    $params_one['value'] = $params['value'][$i];

                    // Validation errors?
                    $errors = null;
                    if (!empty($params['validation_errors']) && is_array($params['validation_errors']) && !empty($params['validation_errors'][$i])) {
                        $errors = $params['validation_errors'][$i];
                    }

                    $result[] = $this->getHtmlOneWithErrors($errors, $params_one, $attrs);
                    $i++;

                    // Show single field when forced to show one value even for multi fields
                    if ($this->getParameter('force_single')) {
                        return $result[0];
                    }
                }
                return '<p>'.implode('</p><p>', $result).'</p>';
            } else {
                // Multi-field with no values
                return '<p>'.$this->getHtmlOneWithErrors(ifempty($params['validation_errors']), $params, $attrs).'</p>';
            }
        }

        // Non-multi field
        return $this->getHtmlOneWithErrors(ifempty($params['validation_errors']), $params, $attrs);
    }

    public function prepareVarExport()
    {
    }

    public static function __set_state($state)
    {
         return new $state['_type']($state['id'], $state['name'], $state['options']);
    }

    public static function getFields()
    {
        if (self::$fields === null) {
            $files = array(
                wa('helpdesk')->getConfig()->getConfigPath('fields/fields.php'),
                wa('helpdesk')->getConfig()->getAppPath().'/lib/config/fields/fields.php'
            );
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $fields = include($file);
                    break;
                }
            }
            self::$fields = $fields;
        }
        return self::$fields;
    }

    public static function fieldExists($field_id)
    {
        $fields = self::getFields($field_id);
        return isset($fields[$field_id]);
    }

    public static function setFields(array $all_fields)
    {
        $file = wa('helpdesk')->getConfig()->getConfigPath('fields/fields.php');
        waUtils::varExportToFile($all_fields, $file);
        self::$fields = null;   // clear cache
    }

    public static function deleteField($field_id)
    {
        $fields = self::getFields();
        if (isset($fields[$field_id])) {
            unset($fields[$field_id]);
            self::setFields($fields);
        }
    }

    public static function updateField(helpdeskRequestField $field)
    {
        $f_id = $field->getId();
        $fields = self::getFields();
        if (!isset($fields[$f_id])) {
            $fields[$f_id] = $field;
            self::setFields($fields);
        }
    }

}

