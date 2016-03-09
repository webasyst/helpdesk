<?php

class helpdeskRequestFields
{

    static protected $fields;

    public static function getFields()
    {
        if (self::$fields === null) {
            $files = array(
                wa('helpdesk')->getConfig()->getConfigPath('fields/fields.php'),
                wa('helpdesk')->getConfig()->getAppPath().'/lib/config/fields/fields.php'
            );
            $fields = array();
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

    public static function setField(helpdeskRequestField $field, $rewrite = false)
    {
        $fields = self::getFields();
        if (!isset($fields[$field->getId()]) || $rewrite) {
            $fields[$field->getId()] = $field;
            self::setFields($fields);
        }
    }

    public static function deleteField($field_id)
    {
        $fields = self::getFields();
        if (isset($fields[$field_id])) {
            unset($fields[$field_id]);
            self::setFields($fields);
        }
    }

    public static function getField($field_id)
    {
        $fields = self::getFields();
        return isset($fields[$field_id]) ? $fields[$field_id] : null;
    }

    public static function updateField(helpdeskRequestField $field)
    {
        $f_id = $field->getId();
        $fields = self::getFields();
        $fields[$f_id] = $field;
        self::setFields($fields);
    }

    public static function getFieldsVars()
    {
        $vars = array();
        $all_fields = self::getFields();
        foreach ($all_fields as $field_id => $field) {
            $vars['${' . $field_id . '}'] = sprintf(_w('Value of the field "%s"'), $field->getName());
        }
        return $vars;
    }

    public static function getSubstituteVars(helpdeskRequest $request)
    {
        $vars = array();
        $request_fields = helpdeskRequestDataModel::formatFields(helpdeskRequestDataModel::filterByType($request->getData(), helpdeskRequestDataModel::TYPE_REQUEST));
        foreach ($request_fields as $field_id => $val) {
            $vars['${'.$field_id.'}'] = $val['value'];
        }
        return $vars;
    }

}

