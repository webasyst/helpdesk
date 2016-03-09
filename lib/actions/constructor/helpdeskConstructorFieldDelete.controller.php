<?php

class helpdeskConstructorFieldDeleteController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }

        if (! ( $id = $this->getRequest()->post('id'))) {
            $this->errors[] = 'No id.';
            return;
        }

        helpdeskRequestFields::deleteField($id);
        $this->deleteData($id);

        helpdeskRequestPageConstructor::getInstance()->deleteRequestField($id);

        $this->response = 'done';
    }

    public function deleteData($id)
    {
        wao(new helpdeskRequestDataModel())->deleteByField(array('field' => $id));
        wao(new helpdeskRequestLogParamsModel())->deleteByField(array('name' => helpdeskRequestLogParamsModel::PREFIX_REQUEST . $id));
    }

}

// EOF