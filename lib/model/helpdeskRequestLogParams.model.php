<?php
class helpdeskRequestLogParamsModel extends waModel
{
    protected $table = 'helpdesk_request_log_params';

    const TYPE_REQUEST = 'request';
    const TYPE_OTHER = 'other';
    const PREFIX_REQUEST = 'fld_';
    const PREFIX_OTHER = '';

    public function getByLogId($log_id)
    {
        return $this->select('name, value')->
                where('request_log_id = i:0', array($log_id))->
                fetchAll('name', true);
    }

    public function setByLogId($log_id, $params)
    {
        $data = array();
        foreach ($params as $name => $val) {
            $data[] = array(
                'request_log_id' => $log_id,
                'name' => $name,
                'value' => $val
            );
        }
        $this->deleteByField('request_log_id', $log_id);
        $this->multipleInsert($data);
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

    public static function cutOffPrefix($field_id)
    {
        if (is_string($field_id)) {
            foreach (self::getTypes() as $type_opt) {
                if (substr($field_id, 0, $type_opt['len']) === $type_opt['prefix']) {
                    return substr($field_id, $type_opt['len']);
                }
            }
        }
        return $field_id;
    }

    public static function filterByType($params, $type, $cut_off = false)
    {
        $res = array();
        foreach ($params as $name => $value) {
            if (self::getType($name) === $type) {
                $res[$cut_off ? self::cutOffPrefix($name) : $name] = $value;
            }
        }
        return $res;
    }

    /**
     *
     * Get raw array of params, retrieved from DB in name => value format and reformat it to usable human-readable data
     *
     * @param array $params name => value
     * @return array
     */
    public static function formatFields($params = array(), $format = 'html')
    {
        $res = array();
        foreach ($params as $name => $value) {
            $field = self::getField($name);
            if ($field) {
                $res[$name] = array(
                    'name' => $field->getName(),
                    'value' => $field->format($value, $format)
                );
            } else {
                $res[$name] = array(
                    'name' => $name,
                    'value' => $format === 'html' ? htmlspecialchars($value) : $value
                );
            }
        }
        return $res;
    }

    private static function getTypes()
    {
        return array(
            self::TYPE_REQUEST => array(
                'prefix' => self::PREFIX_REQUEST,
                'len' => strlen(self::PREFIX_REQUEST)
            ),
            self::TYPE_OTHER => array(
                'prefix' => self::PREFIX_OTHER,
                'len' => strlen(self::PREFIX_OTHER)
            )
        );
    }

    /**
     *
     * @param string $name
     * @return helpdeskRequestField
     */
    public static function getField($name)
    {
        if (self::getType($name) === self::TYPE_REQUEST) {
            return helpdeskRequestFields::getField(self::cutOffPrefix($name));
        } else {
            return null;
        }
    }

}
