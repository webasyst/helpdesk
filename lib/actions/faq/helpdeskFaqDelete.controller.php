<?php

class helpdeskFaqDeleteController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fm = new helpdeskFaqModel();
        $id = waRequest::request('id', null, waRequest::TYPE_INT);
        if ($id) {
            $item = $fm->getById($id);
            if ($item) {
                $fm->delete($id);
            }
        }
        $fcm = new helpdeskFaqCategoryModel();
        $this->response['counters'] = $fcm->getCounters();
    }

}

// EOF