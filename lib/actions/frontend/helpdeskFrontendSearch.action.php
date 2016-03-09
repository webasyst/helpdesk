<?php

class helpdeskFrontendSearchAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $q = waRequest::request('q');
        if (!$q) {
            $q = waRequest::request('query');
        }
        if (!$q) {
            $this->redirect(wa()->getRouteUrl('helpdesk/frontend/'));
        }

        $fm = new helpdeskFaqModel();

        $faq_list = array();
        $query = urldecode(waRequest::request('query', '', waRequest::TYPE_STRING_TRIM));
        if ($query) {
            $faq_list = $fm->getList($query, 1);
        }

        $this->view->assign(array(
            'faq_list' => $faq_list,
            'query' => $query,
        ));

        $this->setThemeTemplate('search.html');
        $this->getResponse()->setTitle(_w('Helpdesk'));

        parent::execute();
    }
}
