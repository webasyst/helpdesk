<?php
/**
 * Public frontend.
 */
class helpdeskFrontendFaqCategoryAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $url = waRequest::param('category');
        if (!$url) {
            throw new waException('Empty category URL');
        }
        $fcm = new helpdeskFaqCategoryModel();
        $category = $fcm->getByField('url', $url);
        if (!$category) {
            throw new waException('Category not found');
        }
        $fm = new helpdeskFaqModel();
        $faq_list = $fm->getByFaqCategory($category['id'], true);

        $this->setThemeTemplate('faq.category.html');
        $this->getResponse()->setTitle($category['name']);
        $this->view->assign('category', $category);
        $this->view->assign('faq_list', $faq_list);

        parent::execute();
    }
}
