<?php

class helpdeskBackendContactTabsAction extends helpdeskViewAction
{
    public function execute()
    {
        echo helpdeskHelper::getContactTabsHtml(waRequest::get('id'));
        exit;
    }
}

