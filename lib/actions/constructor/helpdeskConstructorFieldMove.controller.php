<?php

class helpdeskConstructorFieldMoveController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }
        
        $field_ids = $this->getRequest()->post('field_ids');
        if (!$field_ids) {
            $this->errors[] = "No field ids";
            return;
        }
        
        $data = array();
        $fields = helpdeskRequestFields::getFields();
        foreach ($field_ids as $field_id) {
            $f = null;
            if (isset($fields[$field_id])) {
                $f = $fields[$field_id];
            }
            if ($f) {
                $data[$field_id] = $f;
            }
        }
                
        helpdeskRequestFields::setFields($data);
        
        $this->response = 'done';
    }
}

// EOF