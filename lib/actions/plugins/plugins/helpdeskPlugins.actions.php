<?php

class helpdeskPluginsActions extends waPluginsActions
{
    protected $shadowed = true;

    public function preExecute()
    {
        if (!$this->getUser()->isAdmin('helpdesk')) {
            throw new waRightsException(_ws('Access denied'));
        }
    }

    public function defaultAction()
    {
        parent::defaultAction();
    }
}
