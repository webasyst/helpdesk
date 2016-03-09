<?php

class helpdeskFaqCategoryAction extends waViewAction
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fm = new helpdeskFaqModel();
        $fcm = new helpdeskFaqCategoryModel();

        $id = waRequest::request('id', null, waRequest::TYPE_STRING_TRIM);
        $category = $this->getCategory($id);
        
        $this->view->assign(array(
            'faq' => $fm->getEmptyRow(),
            'category' => $category,
            'icons' => helpdeskHelper::getIcons(),
            'count' => $fcm->countAll()
        ));
    }

    public function getCategory($id)
    {
        $fcm = new helpdeskFaqCategoryModel();

        if (is_numeric($id) && $id == '0') {
            $category = $fcm->getNoneCategory(true);
        } else if ($id > 0) {
            $category = $fcm->get($id);
            $category['url'] = $category['url'] ? $category['url'] : helpdeskHelper::transliterate($category['name']);
            foreach ($category['questions'] as &$q) {
                $q['url'] = $q['url'] ? $q['url'] : helpdeskHelper::transliterate($q['question']);
            }
            unset($q);
        } else {
            $category = $fcm->getEmptyRow();
        }
        if (!$category) {
            throw new waException('Unkown category: ' . $id);
        }
        return $category;
    }

}

// EOF