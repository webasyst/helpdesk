<?php
/**
 * Should be executed by cron to check mail every once in a while:
 *
 *     /path/to/php /path/to/wa/cli.php helpdesk cron
 *
 * Note that while there are backend users online, helpdesk relies
 * on background XHR-requests to check mail every minute.
 * Cron job only makes difference for offline mail gathering.
 * It is safe to call it as seldom as once in 30 minutes or even once an hour.
 */
class helpdeskCronCli extends waCliController
{
    public function execute()
    {
        $asm = new waAppSettingsModel();
        $last_cron_time = $asm->get('helpdesk', 'last_cron_time');
        $asm->set('helpdesk', 'last_cron_time', time());

        // making app active
        wa('helpdesk', true);

        wa('helpdesk')->getConfig('helpdesk')->checkMail();

        wa('helpdesk')->getConfig('helpdesk')->sendMessagesFromQueue();

        /**
         * @event cron
         * @param int $params['last_cron_time'] timestamp of previous run, or null
         * @return void
         */
        $params = array(
            'last_cron_time' => $last_cron_time,
        );
        wa('helpdesk')->event('cron', $params);
    }
}

