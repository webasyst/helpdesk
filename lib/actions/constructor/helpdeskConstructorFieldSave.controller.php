<?php

class helpdeskConstructorFieldSaveController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }

        if (! ( $id = $this->getRequest()->post('id'))) {
            $this->errors[] = _w('Empty id.');
            return;
        }

        switch($id) {
            case '#new':    // Add new custom field
                if (!($field = $this->getUpdatedField())) {
                    return;
                }
                helpdeskRequestFields::updateField($field);
                break;
            default:
                if (!($field = helpdeskRequestFields::getField($id, 'all'))) {
                    $this->errors[] = _w('Unknown field:').' '.$id;
                    return;
                }
                if (!($field = $this->getUpdatedField($field))) {
                    return;
                }
                helpdeskRequestFields::updateField($field);
                $this->updateRequestPageField($field);
                break;
        }

        $this->response = 'done';
    }

    protected function updateRequestPageField(helpdeskRequestField $field)
    {
        if (!$field) {
            return;
        }
        $request_page_constructor = helpdeskRequestPageConstructor::getInstance();
        $request_page_constructor->updateField($field);
    }

    public function getUpdatedField($field = null) {

        $names = array(
            'en_US' => $this->getRequest()->post('name')
        );
        $id = trim($this->getRequest()->post('id_val'));
        $ftype = $this->getRequest()->post('ftype');

        if ($field) {
            // $id = $field->getId();
        } else {
            if (strlen($id) === 0) {
                $this->errors[] = array("id_val" => _w('Required field'));
                return false;
            }
            if (preg_match('/[^a-z_0-9]/i', $id)) {
                $this->errors[] = array('id_val' => _w('Only English alphanumeric, hyphen and underline symbols are allowed'));
                return false;
            }

            // field id exists
            if (helpdeskRequestFields::fieldExists($id)) {
                $this->errors[] = _w('This ID is already in use');
                return false;
            }

            switch($ftype) {
                case "String":
                    $field = new helpdeskRequestStringField($id, $names);
                    break;
                case "Number":
                    $field = new helpdeskRequestNumberField($id, $names);
                    break;
                case "Select":
                    $select_values = array_map('trim', array_filter(explode("\r\n", $this->getRequest()->post('select_field_value'))));
                    $options = array();
                    foreach ($select_values as $val) {
                        $options[$val] = $val;
                    }
                    $field = new helpdeskRequestSelectField($id, $names, array(
                        'options' => $options
                    ));
                    break;
                case "Checkbox":
                    $field = new helpdeskRequestCheckboxField($id, $names);
                    break;
                default:
                    $this->errors[] = _w('Unknown field type:').' '.$ftype;
                    return false;
            }
        }

        if ($this->getRequest()->post('select_field_value')) {
            $opts = array_map('trim', array_filter(explode("\r\n", $this->getRequest()->post('select_field_value'))));
            if (!empty($opts)) {
                $select_options = array();
                foreach ($opts as $val) {
                    $select_options[$val] = $val;
                }
                $field->setParameter('options', $select_options);
            }
        }

        $field->setParameter('localized_names', $names);
        $field->setParameter('my_visible', waRequest::post('my_visible'));

        if (!$this->errors) {
            return $field;
        }

        return false;
    }

}
