<?php

class helpdeskConstructorRequestFieldDeleteController extends helpdeskJsonController
{

    public function execute()
    {
        $id = wa()->getRequest()->post('id');
        $request_page_contructor = helpdeskRequestPageConstructor::getInstance();
        $fields = $request_page_contructor->getAllFields();
        if (isset($fields[$id])) {
            $field = $fields[$id];
            $field['place'] = null;
            unset($fields[$id]);
            $fields[$id] = $field;
            $request_page_contructor->updateFields($fields);
        }
    }
}
