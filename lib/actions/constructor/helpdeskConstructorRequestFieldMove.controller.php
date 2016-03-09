<?php

class helpdeskConstructorRequestFieldMoveController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }

        $right = wa()->getRequest()->post('right', array());
        $left = wa()->getRequest()->post('left', array());

        if (empty($right) && empty($left)) {
            $this->errors[] = "No field ids";
            return;
        }

        $request_page_contructor = helpdeskRequestPageConstructor::getInstance();
        $all_fields = $request_page_contructor->getAllFields();
        $fields = array();
        foreach (array('right' => $right, 'left' => $left) as $place => $field_ids) {
            foreach ($field_ids as $id) {
                if (isset($all_fields[$id])) {
                    $fields[$id] = $all_fields[$id];
                    $fields[$id]['place'] = $place;
                    unset($all_fields[$id]);
                }
            }
        }

        $request_page_contructor->updateFields($fields + $all_fields);

        $this->response = 'done';
    }
}

// EOF