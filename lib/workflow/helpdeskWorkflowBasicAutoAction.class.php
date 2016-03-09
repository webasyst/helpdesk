<?php

class helpdeskWorkflowBasicAutoAction extends helpdeskWorkflowBasicAction implements helpdeskWorkflowActionAutoInterface
{
    const ACTOR_CONTACT_ID = -1;

    public function run($params = null)
    {
        $params = helpdeskWorkflowAction::typecastParams($params);
        $params['actor_contact_id'] = self::ACTOR_CONTACT_ID;
        return parent::run($params);
    }

    public function getTimeout()
    {
        return $this->getOption('timeout', array('day' => 0, 'hour' => 1, 'minute' => 0));
    }

    public function getButton()
    {
        return null;
    }

    public function getActorName()
    {
        return _w('Auto action');
    }

    public static function getDefaultActorName()
    {
        return _w('Auto action');
    }

    /**
     * Message vars of this action
     *
     * @param bool $plain
     * @return array
     *      If plain is true
     *          [
     *              string var_id => string var_description,
     *          ]
     *      Otherwise
     *          [
     *              string category_id =>
     *                  [
     *                      'name' => string name_of_category
     *                      'vars' =>
     *                          [
     *                              string var_id => string var_description
     *                          ]
     *                  ]
     *          ]
     */
    public function getMessageVars($plain = false)
    {
        $vars = parent::getMessageVars(false);
        if (isset($vars['actor'])) {
            unset($vars['actor']);
        }
        if ($plain) {
            $plain_vars = array();
            foreach ($vars as $category) {
                foreach ($category['vars'] as $var) {
                    $plain_vars[] = $var;
                }
            }
            $vars = $plain_vars;
        }
        return $vars;
    }

    protected function settingsSavePrepareConfig()
    {
        $wf = $this->getWorkflow();
        $wf_id = $wf->getId();
        $cfg = parent::settingsSavePrepareConfig();
        if (empty($cfg['workflows'][$wf_id]['actions'][$this->getId()]['created'])) {
            $cfg['workflows'][$wf_id]['actions'][$this->getId()]['created'] = date('Y-m-d H:i:s');
        }
        return $cfg;
    }

    protected function getOptionsSettingsFromPost()
    {
        $options = parent::getOptionsSettingsFromPost();
        $options['assignment'] = 1;
        if ($options['default_assignee'] !== '' && !wa_is_int($options['default_assignee'])) {
            $options['assignment'] = '';
        }
        $d = (int) ifset($options['timeout']['day'], 0);
        $h = (int) ifset($options['timeout']['hour'], 0);
        if (!$d && !$h) {
            $d = 0;
            $h = 1;
        }
        $options['timeout']['day'] = $d;
        $options['timeout']['hour'] = $h;
        return $options;
    }

    /**
     * @return helpdeskWorkflowActionAutoInterface $this
     */
    public function setTimeout($timeout)
    {
        $this->setOption('timeout', $timeout);
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCreatedDatetime()
    {
        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        $wf = $this->getWorkflow();
        $wf_id = $wf->getId();
        return ifset($cfg['workflows'][$wf_id]['actions'][$this->getId()]['created']);
    }

    public function logPerformAction($params)
    {
        return /* not write to log */;
    }

    protected function getMessagesSettingsFromPost()
    {
        $messages = parent::getMessagesSettingsFromPost();
        foreach ($messages as &$m) {
            if (!empty($m['add_attachments'])) {
                $m['add_attachments'] = null;
            }
        }
        unset($m);
        return $messages;
    }
}
