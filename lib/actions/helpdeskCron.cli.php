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
    /**
     * @var helpdeskConfig
     */
    private $config;

    public function __construct()
    {
        $this->config = wa('helpdesk')->getConfig();
    }

    public function execute()
    {
        $asm = new waAppSettingsModel();
        $last_cron_time = $asm->get('helpdesk', 'last_cron_time');
        $asm->set('helpdesk', 'last_cron_time', time());

        // making app active
        wa('helpdesk', true);

        $this->config->checkMail();

        // performAutoActions before send messages from queue, because perform auto actions could push messages into queue
        $this->config->performAutoActions();

        $this->config->sendMessagesFromQueue();

        $last_cron_temp_clean_date = $asm->get('helpdesk', 'last_cron_temp_clean_time');
        if ((time() - $last_cron_temp_clean_date) >= 86400) { // one day
            $tm = new helpdeskTempModel();
            $tm->cleanOldTemp();
            $asm->set('helpdesk', 'last_cron_temp_clean_time', time());
        }

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

    /**
     * Use by hosting app
     * @return bool
     */
    public function isCanBeScheduled()
    {
        return helpdeskSourceType::cronSourceTypesExist() ||
                helpdeskWorkflow::workflowsAutoActionsExist() ||
                !helpdeskSendMessages::isQueueIsEmpty();
    }
}

