<?php
/**
 * Public frontend home.
 */
class helpdeskFrontendAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        // no published page found...
        $fcm = new helpdeskFaqCategoryModel();
        $categories = $fcm->getList('', array(
            'routes' => array($this->getCurrentRoute()),
            'is_public' => true
        ));

        if (!empty($categories[0]['url'])) {
            $this->redirect(wa()->getRouteUrl('helpdesk/frontend/faq') . $categories[0]['url'] . '/');
        } else {
            $this->redirect(wa()->getRouteUrl('helpdesk/frontend/faq'));
        }
    }
}
