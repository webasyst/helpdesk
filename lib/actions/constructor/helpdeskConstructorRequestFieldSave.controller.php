<?php

class helpdeskConstructorRequestFieldSaveController extends helpdeskJsonController
{

    public function execute()
    {
        $id = wa()->getRequest()->post('id');
        $place = wa()->getRequest()->post('place', null, waRequest::TYPE_STRING_TRIM);
        $request_page_constructor = helpdeskRequestPageConstructor::getInstance();
        $fields = $request_page_constructor->getAllFields();
        if (isset($fields[$id])) {
            $field = $fields[$id];
            $field['place'] = $place;
            unset($fields[$id]);
            $fields[$id] = $field;
            $request_page_constructor->updateFields($fields);
        }
    }
}
