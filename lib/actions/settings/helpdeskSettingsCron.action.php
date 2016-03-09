<?php
/** Cron Job Setup page */
class helpdeskSettingsCronAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $this->view->assign('cron_ok', helpdeskHelper::isCronOk());
        $this->view->assign('last_cron_time', wa()->getSetting('last_cron_time'));
        $this->view->assign('cron_command', 'php '.wa()->getConfig()->getRootPath().'/cli.php helpdesk cron');
    }
}

