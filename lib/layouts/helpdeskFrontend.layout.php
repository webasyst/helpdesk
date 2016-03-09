<?php

class helpdeskFrontendLayout extends waLayout
{
    public function execute()
    {
        $this->view->assign('action', waRequest::param('action', 'default'));
        $this->view->assign('my_url', wa()->getRouteUrl('helpdesk/frontend/myRequests'));

        $main_form_id = waRequest::param('main_form_id', 0, 'int');
        if ($main_form_id) {
            $sm = new helpdeskSourceModel();
            $main_source = $sm->getById($main_form_id);
            $this->view->assign('main_source', $main_source);
        }

        $this->setThemeTemplate('index.html');
    }
}
