<?php

class helpdeskConstructorAction extends waViewAction
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }
        $request_page_constructor = helpdeskRequestPageConstructor::getInstance();
        $this->view->assign(array(
            'uniqid' => uniqid('rconstructor'),
            'left_fields' => $request_page_constructor->getLeftFields(),
            'right_fields' => $request_page_constructor->getRightFields(),
            'all_fields' => $request_page_constructor->getAllFields(),
            'fields' => $this->getFields(),
            'field_types' => $this->getFieldTypes()
        ));

    }

    public function getFields()
    {
        $fields = array();
        foreach (helpdeskRequestFields::getFields() as $fld_id => $fld) {
            /**
             * @var helpdeskRequestField $fld
             */
            $fields[$fld_id] = $fld->getInfo();
            $fields[$fld_id]['my_visible'] = $fld->getParameter('my_visible');
        }
        return $fields;
    }

    public function getFieldTypes()
    {
        return array(
            'String' => _w('single line text'),
            'Number' => _w('number'),
            'Select' => _w('drop down'),
            'Checkbox' => _w('checkbox')
        );
    }
}

// EOF