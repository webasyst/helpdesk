<?php
/**
 * Extension for waDbRecord allowing to save key => value pairs (referred to as params) in separate table
 * along with database row in main table.
 *
 * Params table contains at least three fields: name, value and a foreign key matching id in main table.
 *
 * Params are accessable through $this->params->name (read/write) or $this['params'][name] (read only).
 */
class helpdeskRecordWithParams extends waDbRecord
{
    /** @var waModel model to save params */
    protected $pm;

    /** @var string foreign key field name in params table */
    protected $foreign_key;
    /** @var string name field in params table */
    protected $name;
    /** @var string value field in params table */
    protected $value;
    /** @var int max length of string to be written to $this->value field */
    protected $short_maxlen;
    /** @var string field for values exceeding $this->short_maxlen chars; null to truncate silently */
    protected $long_value;
    /** @var boolean true when it's not needed to load params again: if param is not set locally then it is not set in DB. */
    protected $params_loaded = false;

    /**
      * @param waModel $m model to use for saving main record
      * @param waModel $pm model to use for saving params
      * @param string $foreign_key field in params table to use as foreign key to main table
      * @param mixed $id id of existing record, as accepted by model; or array(field => value) to set for new (if no id among fields) or existing record.
      * @param string $name name field in params table (defaults to `name`)
      * @param string $value value field in params table (defaults to `value`)
      * @param int $short_maxlen max length of string to be written to $value field; null (default) to allow any length
      * @param string $long_value field for values exceeding $short_maxlen chars; null (default) to truncate silently
      */
    public function __construct(waModel $m, waModel $pm, $foreign_key, $id = null, $name = 'name', $value = 'value', $short_maxlen=null, $long_value=null)
    {
        parent::__construct($m, $id);
        $this->pm = $pm;
        $this->foreign_key = $foreign_key;
        $this->name = $name;
        $this->value = $value;
        $this->short_maxlen = $short_maxlen;
        $this->long_value = $long_value;
    }

    protected function getDefaultValues()
    {
        return array(
            'params' => array()
        ) + parent::getDefaultValues();
    }

    protected function getLoadableKeys()
    {
        $k = parent::getLoadableKeys();
        $k[] = 'params';
        return $k;
    }

    protected function doLoad($field_or_db_row = null)
    {
        parent::doLoad($field_or_db_row);

        if ($this->params_loaded) {
            return;
        }

        // load from array?
        if (is_array($field_or_db_row)) {
            if(isset($field_or_db_row['params']) && is_array($field_or_db_row['params'])) {
                if (!isset($this->persistent['params']) || !is_array($this->persistent['params'])) {
                    $this->persistent['params'] = array();
                }

                foreach($field_or_db_row['params'] as $k => $v) {
                    $arr = @unserialize($v);
                    if ($arr !== false || $v === 'b:0;') {
                        $v = $arr;
                    }
                    $this->persistent['params'][$k] = $v;
                }
                $this->restorePersistentInvariant();
                $this->params_loaded = true;
            }
            return;
        }

        // load params from database
        if (!$field_or_db_row || $field_or_db_row === 'params') {
            $params = array();
            if ($this->foreign_key) {
                if (!isset($this->persistent['params']) || !is_array($this->persistent['params'])) {
                    $this->persistent['params'] = array();
                }
                foreach($this->pm->getByField($this->foreign_key, $this->id, true) as $row) {
                    $k = $row[$this->name];
                    $v = $row[$this->value];
                    if ($this->long_value && strlen($row[$this->long_value])) {
                        $v = $row[$this->long_value];
                    }

                    $arr = @unserialize($v);
                    if ($arr !== false || $v === 'b:0;') {
                        $v = $arr;
                    }

                    $this->persistent['params'][$k] = $v;
                }
            }
            $this->restorePersistentInvariant();
            $this->params_loaded = true;
            return;
        }
    }

    protected function beforeSave()
    {
        if (empty($this['params'])) {
            return;
        }
        foreach($this['params'] as $k => $v) {
            if (strlen($k) >= 64) {
                throw new waException('Param key too long, max 64 characters');
            }
        }
    }

    protected function afterSave()
    {
        // don't bother saving params if they're not even loaded
        if (!$this->foreign_key || (isset($this->persistent['id']) && $this->persistent['id'] && !$this->persistent->__isset('params'))) {
            return parent::afterSave();
        }

        $rows = array();
        foreach($this['params'] as $name => $value) {
            if ($value instanceof waArrayObject) {
                $value = serialize($value->toArray());
            }
            $row = array(
                $this->foreign_key => $this->id,
                $this->name => $name,
                $this->value => $value,
            );
            if ($this->long_value) {
                $row[$this->long_value] = null;
                if (strlen($value) > $this->short_maxlen) {
                    $row[$this->value] = null;
                    $row[$this->long_value] = $value;
                }
            }
            $rows[] = $row;
        }

        $this->pm->deleteByField(array(
            $this->foreign_key => $this->id
        ));
        $this->pm->multipleInsert($rows);

        $this->params_loaded = true;
        return parent::afterSave();
    }

    protected function afterDelete()
    {
        parent::afterDelete();
        if ($this->foreign_key) {
            $this->pm->deleteByField($this->foreign_key, $this->id);
        }
    }
}

