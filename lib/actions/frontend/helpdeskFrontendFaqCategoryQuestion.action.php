<?php
/**
 * Public frontend.
 */
class helpdeskFrontendFaqCategoryQuestionAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $category_url = waRequest::param('category');
        $faq_url = waRequest::param('question');
        if (!$category_url || !$faq_url) {
            throw new waException('Empty category or question URL');
        }
        $fcm = new helpdeskFaqCategoryModel();
        $category = $fcm->getByField('url', $category_url);
        if (!$category) {
            throw new waException('Category not found');
        }

        if (!$this->checkCategoryAccess($category)) {
            throw new waException('Category not found');
        }

        $fm = new helpdeskFaqModel();
        $faq = $fm->getByField('url', $faq_url);
        if (!$faq) {
            throw new waException('Question not found');
        }

        $this->setThemeTemplate('faq.category.question.html');
        $this->getResponse()->setTitle($faq['question']);
        $this->view->assign('category', $category);
        $this->view->assign('faq', $faq);

        $canonical_url = wa()->getRouteUrl('helpdesk/frontend/faqCategoryQuestion', [
            'category' => $category['url'],
            'question' => $faq['url'],
        ], true);
        $this->getResponse()->setCanonical($canonical_url);

        parent::execute();
    }
}
