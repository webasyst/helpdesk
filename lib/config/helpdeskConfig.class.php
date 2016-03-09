<?php

class helpdeskConfig extends waAppConfig
{
    /**
     * Automagically called in background by system JS.
     * Returns the number of unread messages to show near app icon in header.
     */
    public function onCount()
    {
        $asm = new waAppSettingsModel();
        $asm->set('helpdesk', 'backend_url', wa('helpdesk')->getConfig()->getBackendUrl());

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


    public function getHelpdeskBackendUrl()
    {
        if (wa()->getEnv() !== 'cli') {
            return wa()->getRootUrl(true).wa()->getConfig()->getBackendUrl()."/helpdesk";
        } else {
            $asm = new waAppSettingsModel();
            $backend_url = $asm->get('helpdesk', 'backend_url');
            if ($backend_url) {
                return $backend_url;
            } else {
                $domains = wa('helpdesk')->getRouting()->getDomains();
                $root_url = $domains ? 'http://' . reset($domains) . '/' : '';
                return $root_url . wa()->getConfig()->getBackendUrl()."/helpdesk";
            }
        }
    }

}

