<?php

class helpdeskRequestPageConstructor
{
    const TYPE_MAIN = 'main';
    const TYPE_CUSTOM = 'custom';
    const PREFIX_MAIN = '';
    const PREFIX_CUSTOM = 'fld_';

    static private $instance;

    protected $config;
    protected $fields = array();

    private function __construct() {}
    private function __clone() {}

    /**
     * @return helpdeskRequestPageConstructor
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new helpdeskRequestPageConstructor();
        }
        return self::$instance;
    }


    protected function getConfig()
    {
        if ($this->config === null) {
            $this->config = array();
            $files = array(
                $this->getCustomConfigFilepath(),
                $this->getDefaultConfigFilepath()
            );
            foreach ($files as $file) {
                if (file_exists($file)) {
                    $this->config = include($file);
                    break;
                }
            }
            $this->mixinCustomFields($this->config);
        }
        return $this->config;
    }

    protected function saveConfig($config)
    {
        // check if changed first
        if (serialize($config) != serialize($this->getConfig())) {
            waUtils::varExportToFile($config, $this->getCustomConfigFilepath());
            $this->clearCache();
        }
    }

    public function getAllFields()
    {
        if (!isset($this->fields['all'])) {
            $this->fields['all'] = $this->getConfig();
            $this->l10n($this->fields['all']);
        }
        return $this->fields['all'];
    }

    public function updateField(helpdeskRequestField $field)
    {
        if (!$field) {
            return;
        }
        $fields = $this->getAllFields();
        $field_id = self::PREFIX_CUSTOM.$field->getId();
        if (isset($fields[$field_id])) {
            $fields[$field_id]['name'] = $field->getName();
            $this->updateFields($fields);
        }
    }

    public function updateFields($fields)
    {
        $config = $this->getConfig();

        $data = array();
        foreach ($fields as $field_id => $field) {
            if (isset($field['id'])) {
                $field_id = $field['id'];
            }
            if (isset($config[$field_id])) {
                $field = array_merge($config[$field_id], $field);
                unset($config[$field_id]);
            }
            $data[$field_id] = $field;
        }

        foreach ($config as $field_id => $field) {
            if (self::getType($field_id) === self::TYPE_MAIN) {
                if (isset($field['place'])) {
                    unset($field['place']);
                }
                $data[$field_id] = $field;
            }
        }

        $this->saveConfig($data);
    }

    public function deleteRequestField($field_id)
    {
        $config = $this->getConfig();
        if (isset($config[self::PREFIX_CUSTOM . $field_id])) {
            unset($config[self::PREFIX_CUSTOM . $field_id]);
            $this->saveConfig($config);
        }
    }

    public function getRightFields()
    {
        return $this->getFieldsByPlace('right');
    }

    public function getLeftFields()
    {
        return $this->getFieldsByPlace('left');
    }

    public function getUnplacedFields()
    {
        return $this->getFieldsByPlace('unplaced');
    }

    public function getFieldsByPlace($place = 'unplaced')
    {
        if (!isset($this->fields[$place])) {
            $this->fields[$place] = array();
            $config = $this->getConfig();
            foreach ($config as $field_opt) {
                if ((empty($field_opt['place']) && ($place === null || $place === 'unplaced'))
                        ||
                    (!empty($field_opt['place']) && $field_opt['place'] === $place))
                {
                    $this->fields[$place][$field_opt['id']] = $field_opt;
                }
            }
            $this->l10n($this->fields[$place]);
        }
        return $this->fields[$place];
    }

    public function resetConfig()
    {
        $file_path = $this->getCustomConfigFilepath();
        if (file_exists($file_path)) {
            waFiles::delete($file_path, true);
        }
        if (file_exists($file_path)) {
            return false;
        }
        return true;
    }

    protected function mixinCustomFields(&$config)
    {
        $fields = helpdeskRequestFields::getFields();
        foreach ($fields as $field) {
            $field_id = $field->getId();
            if (!isset($config[self::PREFIX_CUSTOM.$field_id])) {
                $config[self::PREFIX_CUSTOM.$field_id] = array(
                    'id' => self::PREFIX_CUSTOM.$field_id,
                    'name' => $field->getName()
                );
            }
        }
    }

    protected function l10n(&$fields)
    {
        foreach ($fields as &$f) {
            if ($f['id'] === 'status') {
                $f['name'] = _w('Status');
                $f['value'] = _w('Status');
            } else if ($f['id'] === 'assigned') {
                $f['name'] = _w('Assigned');
                $f['value'] = _w('Name');
            } else if ($f['id'] === 'tags') {
                $f['name'] = _w('Tags');
                $f['value'] = _w('Tags');
            }
        }
        unset($f);
        return $fields;
    }

    protected function getCustomConfigFilepath()
    {
        return wa('helpdesk')->getConfig()->getConfigPath('request_page.php');
    }

    protected function getDefaultConfigFilepath()
    {
        return wa('helpdesk')->getConfig()->getAppPath().'/lib/config/request_page.php';
    }

    protected function clearCache()
    {
        $this->config = null;
        $this->fields = array();
    }

    public static function cutOffPrefix($field_id)
    {
        if (is_string($field_id) || is_numeric($field_id)) {
            $field_id = '' . $field_id;
            foreach (self::getTypes() as $type_opt) {
                if (substr($field_id, 0, $type_opt['len']) === $type_opt['prefix']) {
                    return substr($field_id, $type_opt['len']);
                }
            }
        }
        return $field_id;
    }

    public static function getType($field_id)
    {
        if (is_string($field_id) || is_numeric($field_id)) {
            $field_id = '' . $field_id;
            foreach (self::getTypes() as $type => $type_opt) {
                if (substr($field_id, 0, $type_opt['len']) === $type_opt['prefix']) {
                    return $type;
                }
            }
        }
        return null;
    }

    private static function getTypes()
    {
        return array(
            self::TYPE_CUSTOM => array(
                'prefix' => self::PREFIX_CUSTOM,
                'len' => strlen(self::PREFIX_CUSTOM)
            ),
            self::TYPE_MAIN => array(
                'prefix' => self::PREFIX_MAIN,
                'len' => strlen(self::PREFIX_MAIN)
            )
        );
    }

}
