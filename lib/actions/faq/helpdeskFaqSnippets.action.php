<?php

class helpdeskFaqSnippetsAction extends waViewAction
{
    public function execute()
    {
        $fcm = new helpdeskFaqCategoryModel();

        $faq_list = array();

        $category = array();
        $category_id = waRequest::request('category_id', null, waRequest::TYPE_INT);
        if ($category_id) {
            $category = $fcm->get($category_id, null, true);
            $faq_list = $category['questions'];
        }

        $query = urldecode(waRequest::request('query', null, waRequest::TYPE_STRING_TRIM));
        if ($query) {
            $fm = new helpdeskFaqModel();
            $faq_list = $fm->getList($query, null, true);
        }

        $this->view->assign(array(
            'categories' => $fcm->getAll(),
            'category'   => $category,
            'faq_list'   => $faq_list,
            'query'      => $query
        ));
    }

}

// EOF
