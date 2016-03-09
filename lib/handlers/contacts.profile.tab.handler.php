<?php

class helpdeskContactsProfileTabHandler extends waEventHandler
{
    public function execute(&$params)
    {
        $old_app = wa()->getApp();
        wa('helpdesk')->setActive('helpdesk');
        try {
            $a = new helpdeskHandlersProfiletabAction();
            $result = $a->getTabContent($params);
        } catch (Exception $e) {
            $result = array(
                'title' => wa()->getApp().' error',
                'html' => (string) $e,
                'count' => 0,
            );
        }

        waSystem::setActive($old_app);
        return $result;
    }
}
