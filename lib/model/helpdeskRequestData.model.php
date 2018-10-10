<?php

class helpdeskRequestDataModel extends waModel
{
    protected $table = 'helpdesk_request_data';

    const TYPE_CONTACT = 'contact';
    const TYPE_REQUEST = 'request';
    const TYPE_ONE_CLICK_FEEDBACK = '1_click_feedback';

    const PREFIX_CONTACT = 'c_';
    const PREFIX_REQUEST = '';
    const PREFIX_ONE_CLICK_FEEDBACK = '1_click_feedback_';

    const STATUS_SERVICE_AND_HIDDEN = -2;
    const STATUS_COME_WITH_REQUEST_AND_CHANGED = -1;
    const STATUS_COME_WITH_REQUEST = 0;
    const STATUS_COME_WITH_REQUEST_LOG_ACTION = 1;


    public function add($data, $type = null, $insert_type = 0)
    {
        if (!isset($data['request_id'])) {
            return false;
        }
        $data['field'] = ifset($data['field'], '');
        $data['value'] = ifset($data['value'], '');
        if ($type !== null) {
            $types = self::getTypes();
            if (isset($types[$type])) {
                $descriptor = $types[$type];
                if (self::cutOffPrefix($data['field']) != $descriptor['prefix']) {
                    $data['field'] = $descriptor['prefix'] . $data['field'];
                }
                if ($type === self::TYPE_ONE_CLICK_FEEDBACK && (!isset($data['status']) || $data['status'] == null)) {
                    $data['status'] = self::STATUS_SERVICE_AND_HIDDEN;
                }
            }
        }
        return $this->insert($data, $insert_type);
    }

    public function addOneClickFeedbackHashes($request_id, $hashes)
    {
        $ids = array();
        foreach ($hashes as $field_id => $hash) {
            $ids[] = $this->add(array(
                'request_id' => $request_id,
                'field' => $field_id,
                'value' => $hash
            ), self::TYPE_ONE_CLICK_FEEDBACK, 1);
        }
        return $ids;
    }

    public function getByOneClickFeedbackField($data, $all = false, $limit = false)
    {
        $data['field'] = self::PREFIX_ONE_CLICK_FEEDBACK . ifset($data['field'], '');
        if (!isset($data['status']) || $data['status'] == null) {
            $data['status'] = self::STATUS_SERVICE_AND_HIDDEN;
        }
        return $this->getByField($data, $all, $limit);
    }

        /**
     * @param int $request_id
     */
    public function getByRequest($request_id, $status = null)
    {
        $where = 'request_id = i:0';
        if ($status !== null) {
            $status = (array) $status;
            $where .= ' AND status IN (i:1)';
        }
        $raw_data = $this->select('*')->
                where($where, array($request_id, $status))->
                order('id')->
                fetchAll();
        $fields = helpdeskRequestFields::getFields();
        $contact_fields = waContactFields::getAll();

        $data = array();
        foreach ($raw_data as $row) {
            $data[$row['field']] = ifset($data[$row['field']], array());

            $field_id = $row['field'];
            if (!in_array(self::getType($field_id), array(self::TYPE_CONTACT, self::TYPE_REQUEST))) {
                $data[$field_id] = $row;
            }

            $value = $row['value'];
            $flds = &$fields;
            if (self::getType($row['field']) === self::TYPE_CONTACT) {
                $flds = &$contact_fields;
                $field_id = self::cutOffPrefix($field_id);
            }
            $row['name'] = !empty($flds[$field_id]) ? $flds[$field_id]->getName() : $field_id;
            $row['value'] = $value;
            $data[$row['field']] = $row;
        }
        return $data;
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

    private static function getTypes()
    {
        return array(
            self::TYPE_CONTACT => array(
                'prefix' => self::PREFIX_CONTACT,
                'len' => strlen(self::PREFIX_CONTACT)
            ),
            self::TYPE_REQUEST => array(
                'prefix' => self::PREFIX_REQUEST,
                'len' => strlen(self::PREFIX_REQUEST)
            ),
            self::TYPE_ONE_CLICK_FEEDBACK => array(
                'prefix' => self::PREFIX_ONE_CLICK_FEEDBACK,
                'len' => strlen(self::PREFIX_ONE_CLICK_FEEDBACK)
            )
        );
    }

    public static function formatFields($fields = array())
    {
        $data = array();
        $birthday_data = array(
            'year' => null, 'month' => null, 'day' => null
        );

        // grouping multi-fields
        $multi_fields = array();
        $order = array();
        foreach ($fields as $field_id => $field) {
            if (substr($field_id, 2, 9) !== 'birthday_' && strstr($field_id, ':') !== false) {
                $field_id_ar = explode(':', $field_id, 2);
                $f_id = $field_id_ar[0];
                $subf_id = $field_id_ar[1];
                $multi_fields[$f_id]['value']['data'][$subf_id] = $field['value'];
                $order[$f_id] = $f_id;
                unset($fields[$field_id]);
            } else {
                $order[$field_id] = $field_id;
            }
        }

        // order
        $ordered_fields = array();
        foreach ($order as $field_id) {
            if (isset($fields[$field_id])) {
                $ordered_fields[$field_id] = $fields[$field_id];
            } else if (isset($multi_fields[$field_id])) {
                $ordered_fields[$field_id] = $multi_fields[$field_id];
            }
        }

        $fields = $ordered_fields;

        $first = null;
        foreach ($fields as $field_id => $field) {
            if (substr($field_id, 2, 9) === 'birthday_') {
                if (!$first) {
                    $first = $field_id;
                }
                $birthday_data[substr($field_id, 11)] = $field['value'];
            }

            $subfield_id = null;        // if not null then field is multiple
            if (strstr($field_id, ':') !== false) {
                $field_id_ar = explode(':', $field_id, 2);
                $field_id = $field_id_ar[0];
                $subfield_id = $field_id[1];
            }
            $name = $field_id;
            $fld = self::getField($field_id);
            if ($fld) {
                $field['value'] = $fld->format($field['value'], 'html');
                if (in_array($fld->getType(), array('SocialNetwork', 'Phone', 'IM'))) {
                    $field['value'] = htmlspecialchars($field['value']);
                }
                $name = $fld->getName();
            }
            $data[$field_id] = array(
                'name' => ifset($field['name'], $name),
                'value' => $field['value']
            );
        }

        if ($first) {       // first founded, so format birthday
            $bf = waContactFields::get('birthday');
            if ($bf) {

                $data_keys = array_keys($data);
                foreach ($data as $field_id => $field) {
                    if (substr($field_id, 2, 9) === 'birthday_') {
                        if (isset($data[$field_id])) {
                            unset($data[$field_id]);
                        }
                    }
                }

                $value = $bf->format(array(
                    'data' => $birthday_data
                ), 'html');

                $data['c_birthday'] = array(
                    'name' => $bf->getName(),
                    'value' => $value
                );

                $k = array_search($first, $data_keys);
                $data_keys[$k] = 'c_birthday';

                $sorted_data = array();
                foreach ($data_keys as $field_id) {
                    if (isset($data[$field_id])) {
                        $sorted_data[$field_id] = $data[$field_id];
                    }
                }

                $data = $sorted_data;
            }
        }

        return $data;
    }

    public static function getField($field_id)
    {
        if (self::getType($field_id) === self::TYPE_CONTACT) {
            return waContactFields::get(self::cutOffPrefix($field_id));
        } else if (self::getType($field_id) === self::TYPE_REQUEST) {
            return helpdeskRequestFields::getField(self::cutOffPrefix($field_id));
        } else {
            return null;
        }
    }

    public static function filterByType($data, $type, $cut_off = false)
    {
        $res = array();
        foreach ($data as $name => $value) {
            if (self::getType($name) === $type) {
                $res[$cut_off ? self::cutOffPrefix($name) : $name] = $value;
            }
        }
        return $res;
    }

}

