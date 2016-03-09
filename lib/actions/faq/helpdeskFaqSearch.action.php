<?php

class helpdeskFaqSearchAction extends waViewAction
{
    public function execute()
    {
        $fm = new helpdeskFaqModel();
        $faq_list = array();
        $query = urldecode(waRequest::request('query', '', waRequest::TYPE_STRING_TRIM));
        if ($query) {
            $faq_list = $fm->getList($query);
            $this->workupList($faq_list);
        }

        $this->view->assign(array(
            'faq_list' => $faq_list,
            'query' => $query
        ));
    }

    public function workupList(&$faqs)
    {
        if ($faqs) {
            $cat_ids = array();
            foreach ($faqs as $q) {
                if ($q['is_public']) {
                    $cat_ids[] = $q['faq_category_id'];
                }
            }
            $cat_ids = array_unique($cat_ids);
            $fqm = new helpdeskFaqCategoryModel();
            $categories = $fqm->getById($cat_ids);
            foreach ($faqs as &$q) {
                if ($q['is_public'] && isset($categories[$q['faq_category_id']])) {
                    $category = $categories[$q['faq_category_id']];
                    $q['frontend_url'] = wa()->getRouteUrl('/', array(), true) . 'faq/' . ifset($category['url'], '') . '/' . ifset($q['url'], '') . '/';
                }
            }
            unset($q);
        }
    }

}

// EOF