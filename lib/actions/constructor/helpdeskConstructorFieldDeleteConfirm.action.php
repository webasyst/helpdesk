<?php

class helpdeskConstructorFieldDeleteConfirmAction extends waViewAction
{
    protected $models = array();

    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied'));
        }

        $id = waRequest::get('id');
        $field = helpdeskRequestFields::getField($id);

        $this->view->assign(array(
            'id' => $id,
            'name' => $field->getName(),
            'count' => $this->getCount($id)
        ));
    }

    public function getCount($id)
    {
        $m = new waModel();
        return $m->query("SELECT COUNT(DISTINCT r.id) FROM helpdesk_request r
                JOIN helpdesk_request_data rd ON rd.request_id = r.id
                JOIN helpdesk_request_log rl ON rl.request_id = r.id
                JOIN helpdesk_request_log_params rlp ON rlp.request_log_id = rl.id
            WHERE rd.field = :0 OR rlp.name = :1", array($id, "fld_{$id}"))->fetchField();
    }

}

// EOF