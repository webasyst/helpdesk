<?php

class helpdeskConfig extends waAppConfig
{
    const ROWS_PER_PAGE = 30;

    /**
     * @var string datetime Y-m-d H:i:s
     */
    private $auto_actions_feature_release;

    public function checkUpdates()
    {
        /**
         * Event that triggered before any check updates of apps
         * @event before_check_updates
         */
        wa()->event(array('helpdesk', 'before_check_updates'));

        $result = parent::checkUpdates();

        /**
         * Event that triggered after check updates of apps
         * @event after_check_updates
         */
        wa()->event(array('helpdesk', 'after_check_updates'));

        return $result;
    }

    /**
     * Automagically called in background by system JS.
     * Returns the number of unread messages to show near app icon in header.
     */
    public function onCount()
    {
        // check new mail
        $this->checkMail();

        // send messages from queue
        $this->sendMessagesFromQueue();

        // unseen errors checking mail?
        list($workflows_errors,) = helpdeskHelper::getWorkflowsErrors();
        if ($workflows_errors) {
            return '!';
        }

        // Unread requests for this user?
        if (wa()->getUser()->getSettings('helpdesk', 'display_oncount', true)) {
            $r = wao(new helpdeskUnreadModel())->countByContact();
            return ifempty($r);
        }

        return null;
    }

    protected function getLockFile()
    {
        return wa()->getDataPath('lock/checker.lock', false, $this->getApplication());
    }

    /** Called by $this->onCount() to check sources and download new messages if any. */
    public function checkMail()
    {
        // Make sure we're the only process to check sources at the same time
        $filename = $this->getLockFile();
        waFiles::create($filename);
        @touch($filename);
        @chmod($filename, 0666);
        $x = @fopen($filename, "r+");
        if (!$x || !flock($x, LOCK_EX|LOCK_NB)) {
            /*if (waSystemConfig::isDebug()) {
                waLog::log('Skipping email check because flock failed.', 'helpdesk.log');
            }*/
            $x && fclose($x);
            return;
        }

        try {
            $sm = new helpdeskSourceModel();
            foreach ($sm->getAll(true) as $source_id => $source) {
                if ($source['status'] <= 0) {
                    continue;
                }
                try {
                    $s = helpdeskSource::get($source);
                    $st = $s->getSourceType();
                } catch (Exception $e) {
                    continue;
                }

                $error_datetime = $s->params->ifset('error_datetime');

                if ($st instanceof helpdeskCronSTInterface) {
                    waSystem::pushActivePlugin($st->getPlugin(), 'helpdesk');
                    try {
                        $result = $st->cronJob($s);

                        // Everything seems fine with this source now, reset the error flag
                        if ($result && $error_datetime) {
                            // Create a new copy just in case... never know where the old one has been!
                            $s = helpdeskSource::get($source_id);
                            unset($s->params->error_datetime);
                            $s->save();
                        }
                    } catch (Exception $e) {
                        $a = new waDbRecord(new helpdeskErrorModel());
                        unset($a['id']);
                        $a->datetime = date('Y-m-d H:i:s');
                        $a->source_id = $source_id;
                        $a->message = sprintf(_wd('helpdesk', 'Helpdesk is unable to fetch messages from source “%s”:'), $source['name']).' '.$e->getMessage();
                        $a->save();

                        // Remember that something's wrong with this source
                        // to show later on workflow and source settings page.
                        if (!$error_datetime) {
                            // Create new copy to make sure not to save invalid object state to DB
                            $s = helpdeskSource::get($source_id);
                            try {
                                $s->params->error_datetime = date('Y-m-d H:i:s');
                                $s->save();
                            } catch (Exception $e) {
                                // Source deleted during its cron job?
                                // That's crazy but possible...
                            }
                        }
                    }
                    waSystem::popActivePlugin();
                }
            }
        } catch (Exception $e) {
            flock($x, LOCK_UN);
            fclose($x);
            throw $e;
        }

        flock($x, LOCK_UN);
        fclose($x);
    }

    public function sendMessagesFromQueue()
    {
        // send messages from queue
        $sender = new helpdeskSendMessages();
        $sender->sendMessagesFromQueue();
    }


    public function performAutoActions()
    {
        $rl = new helpdeskRequestModel();
        foreach (helpdeskWorkflow::getWorkflowsAutoActions() as $wf_id => $actions) {
            foreach ($actions as $action_id => $action) {
                /**
                 * @var helpdeskWorkflowActionAutoInterface|helpdeskWorkflowAction $action
                 */
                $timeout = $action->getTimeout();
                $states = $action->getAvailableStates();
                if (empty($states)) {
                    continue;
                }

                $day = (int) ifset($timeout['day'], 0);
                $hour = (int) ifset($timeout['hour'], 1);
                $minute = (int) ifset($timeout['minute'], 0);

                $period = array(
                    $action->getCreatedDatetime(),
                    date('Y-m-d H:i:s', strtotime("-{$day} day -{$hour} hour -{$minute} minute"))
                );

                if (empty($period[0])) {
                    $period[0] = $this->getAutoActionsFeatureReleaseDatetime();
                }

                $request_ids = $rl->getRequestsNotExecutedInPeriod(
                    $period,
                    'id',
                    array(
                        'workflow_id' => $wf_id,
                        'state_id' => array_keys($states)
                    ));

                foreach ($request_ids as $request_id) {
                    $r = new helpdeskRequest($request_id);
                    $action->run($r);
                }

            }
        }
    }

    /**
     * @return array|string
     */
    public function getAutoActionsFeatureReleaseDatetime()
    {
        if (!$this->auto_actions_feature_release) {
            $asm = new waAppSettingsModel();
            $default = date('23-02-2016 00:00:00');
            $datetime = $asm->get('helpdesk', 'auto_actions_feature_release', $default);
            $time = (int)strtotime($datetime);
            if ($time < strtotime($default)) {
                $this->auto_actions_feature_release = $default;
            } else {
                $this->auto_actions_feature_release = $datetime;
            }
        }
        return $this->auto_actions_feature_release;
    }

    public function getRouting($route = array(), $dispatch = false)
    {
        $url_type = isset($route['url_type']) ? $route['url_type'] : 0;
        if (!isset($this->_routes[$url_type]) || $dispatch) {
            $routes = parent::getRouting($route);
            if ($routes) {
                if (isset($routes[$url_type])) {
                    $routes = $routes[$url_type];
                } else {
                    $routes = $routes[0];
                }
            }
            if ($dispatch) {
                return $routes;
            }
            $this->_routes[$url_type] = $routes;
        }
        return $this->_routes[$url_type];
    }


    /**
     * @return string
     */
    public function getHelpdeskBackendUrl()
    {
        if (wa()->getEnv() !== 'cli') {
            return $this->getBackendEnvHelpdeskBackendUrl();
        } else {
            return $this->getCliEnvHelpdeskBackendUrl();
        }
    }

    /**
     * @return string
     */
    public function getBackendEnvHelpdeskBackendUrl()
    {
        return wa()->getRootUrl(true).wa()->getConfig()->getBackendUrl()."/helpdesk";
    }

    /**
     * @return string
     */
    public function getCliEnvHelpdeskBackendUrl()
    {
        $app_id = $this->getApplication();
        $asm = new waAppSettingsModel();
        $backend_url = $asm->get($app_id, 'cli_backend_url');
        if ($backend_url && (substr($backend_url, 0, 6) === 'https:' || substr($backend_url, 0, 5))) {
            return rtrim($backend_url, '/');
        } else {
            $domains = wa($app_id)->getRouting()->getDomains();
            $root_url = $domains ? 'http://' . reset($domains) . '/' : '';
            return $root_url . wa()->getConfig()->getBackendUrl()."/helpdesk";
        }
    }

    /**
     * Update backend url app setting, set it to current backend url in backend env
     */
    public function updateCliEnvHelpdeskBackendUrl()
    {
        $this->setCliEnvHelpdeskBackendUrl(
            $this->getBackendEnvHelpdeskBackendUrl()
        );
    }

    /**
     * @param string $backend_url
     */
    protected function setCliEnvHelpdeskBackendUrl($backend_url)
    {
        if ($backend_url && (substr($backend_url, 0, 6) === 'https:' || substr($backend_url, 0, 5))) {
            $app_id = $this->getApplication();
            $asm = new waAppSettingsModel();
            $asm->set($app_id, 'cli_backend_url', $backend_url);
        }
    }

}
