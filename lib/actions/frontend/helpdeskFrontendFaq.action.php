<?php
/**
 * Public frontend home.
 * Only used when "with public frontend" option is set
 * in settlement options in Site app. (It is set by default.)
 * Controller for theme/home.html
 */
class helpdeskFrontendFaqAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $fcm = new helpdeskFaqCategoryModel();
        $categories = $fcm->getList('', array(
            'is_public' => true,
            'routes' => array($this->getCurrentRoute())
        ));

        if (!empty($categories[0]['url'])) {
            $this->redirect(wa()->getRouteUrl('helpdesk/frontend/faq') . $categories[0]['url'] . '/');
        }

        $this->setThemeTemplate('home.html');
        $this->getResponse()->setTitle(_w('Categories'));

        parent::execute();
    }
}
