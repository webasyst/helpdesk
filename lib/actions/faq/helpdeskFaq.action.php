<?php

class helpdeskFaqAction extends waViewAction
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fcm = new helpdeskFaqCategoryModel();

        $fm = new helpdeskFaqModel();
        $id = waRequest::request('id', null, waRequest::TYPE_INT);
        if (!$id) {
            $faq = $fm->getEmptyRow();
            $category_id = waRequest::request('category_id', null, waRequest::TYPE_INT);
        } else {
            $faq = $fm->getById($id);
            if (!$faq) {
                $faq = $fm->getEmptyRow();
            }
            $category_id = $faq['faq_category_id'];
        }

        if ($category_id === 0) {
            $category = $fcm->getNoneCategory();
        } else if ($category_id > 0) {
            $category = $fcm->getById($category_id);
        } else {
            $categories = $fcm->select('*')->limit(1)->fetchAll();
            $category = $categories[0];
        }

        $this->view->assign(array(
            'category' => $category,
            'categories' => $fcm->getAllCategories(),
            'faq' => $faq,
            'id' => $id,
        ));
    }
}
