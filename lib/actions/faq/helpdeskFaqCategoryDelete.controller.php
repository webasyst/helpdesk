<?php

class helpdeskFaqCategoryDeleteController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fcm = new helpdeskFaqCategoryModel();
        $fm = new helpdeskFaqModel();
        $id = waRequest::request('id', null, waRequest::TYPE_INT);
        if ($id) {
            $fcm->deleteById($id);
            $fm->updateByField('faq_category_id', $id, array(
                'faq_category_id' => 0
            ));
            $this->response['none_count'] = $fm->countByField('faq_category_id', 0);
        }
    }
}
