<?php
/**
 * Base class for all workflows.
 * Also, a collection of static functions to work with multiple workflows.
 *
 * Although it is possible to create custom workflow classes, it should not be needed
 * most of the time. If you want to extend workflow functionality, most likely you want
 * to create custom actions instead.
 *
 * Still, for the curious, there goes some explanation.
 *
 * Each request is normally attached to a single workflow and is in a single state
 * at every instant of time.
 *
 * Workflow is responsible for the following:
 *
 * - Determine list of all possible actions and states.
 *   Instantiate action and state objects.
 *   See $this->getAllActions(), $this->getAllStates(),
 *       $this->getStateById(), $this->getActionById().
 *
 * - Determine the list of actions that can be performed in given state.
 *   See $this->getActions()
 *
 * - Determine transitions for given action from given state.
 *   See $this->getTransition()
 *   Also see helpdeskTransition class for background info.
 *
 * Default workflow implementation stores its action and state settings in config file:
 * /wa-config/apps/helpdesk/workflows.php
 *
 * You may also be interested in: helpdeskWorkflowAction, helpdeskSourceType
 */
class helpdeskWorkflow extends waWorkflow
{
    /** Cache for self::getWorkflows() */
    protected static $workflows = null;

    /** Cache for self::getWorkflowsConfig() */
    protected static $workflows_cfg = null;

    protected $client_triggerable_actions;


    const PREFIX_ONE_CLICK_FEEDBACK_HREF = '1_click_feedback_';

    //
    // Static functions to work with multiple workflows
    //

    /**
     * Get workgflow instance by numeric id (as kept in DB)
     * @param string|int $id
     * @return helpdeskWorkflow
     * @throws waException if no such workflow exists
     */
    public static function get($id = null)
    {
        if ($id === null) {
            $wfs = self::getWorkflows();
            if (!$wfs) {
                throw new waException('No workflows found');
            }
            reset($wfs);
            $id = key($wfs);
        }
        if (self::$workflows && isset(self::$workflows[$id])) {
            return self::$workflows[$id];
        }

        $wfs = self::getWorkflowsConfig();
        if (!wa_is_int($id) || !isset($wfs['workflows'][$id])) {
            self::$workflows_cfg = null;
            throw new waException('Unknown workflow: ' . htmlspecialchars(print_r($id, true)), 404);
        }
        if (! ( $class = $wfs['workflows'][$id]['classname'])) {
            $class = 'helpdeskWorkflow';
        }
        if (!class_exists($class)) {
            throw new waException('Workflow class not found: '.$class);
        }
        return new $class($id);
    }

    public static function addWorkflow(array $data = array())
    {
        $cnf = self::getWorkflowsConfig();
        $cnf['workflows'] = ifset($cnf['workflows'], array());
        $id = self::getWorkflowsLastId() + 1;
        $cnf['workflows'][$id] = array_merge(array(
                'id' => $id,
                'name' => _w('Workflow'),
                'classname' => 'helpdeskWorkflow',
                'states' => array(),
                'actions' => array(),
        ), $data);
        self::saveWorkflowsConfig($cnf);
        return $id;
    }

    public static function updateWorkflow($id, array $data)
    {
        if ($data) {
            $wf = self::getWorkflow($id);
            if ($wf) {
                $wf->update($id, $data);
            }
        }
    }

    public static function getWorkflowsLastId()
    {
        $cfg = self::getWorkflowsConfig();
        $exist_ids = array_keys($cfg['workflows']);
        if ($exist_ids) {
            $last_id = max($exist_ids);
        } else {
            $last_id = 0;
        }
        return $last_id;
    }

    /**
     * Alias for self::get()
     * @return helpdeskWorkflow
     */
    public static function getWorkflow($id = null)
    {
        return self::get($id);
    }

    /** @return array list of all workflows as workflow_id => helpdeskWorkflow instance */
    public static function getWorkflows()
    {
        if (!self::$workflows) {
            self::$workflows = array();
            $wfs = self::getWorkflowsConfig();
            foreach (ifempty($wfs['workflows'], array()) as $id => $data) {
                $class = $data['classname'];
                if (!class_exists($class)) {
                    throw new waException('Workflow class not found: '.$class);
                }
                self::$workflows[$id] = new $class($id);
            }
        }
        return self::$workflows;
    }

    /**
     * @param bool $flatten
     * @return array of helpdeskWorkflowAction|helpdeskWorkflowActionAutoInterface
     *     If flatten then one-dimensional array of objects (not indexed)
     *     If not flatten then one-dimensional array of objects (indexed by workflow_id and action_id)
     * @throws waException
     */
    public static function getWorkflowsAutoActions($flatten = false)
    {
        $auto_actions = array();

        $wfs = self::getWorkflowsConfig();
        foreach (ifempty($wfs['workflows'], array()) as $wfid => $data) {
            try {
                $wf = self::get($wfid);
                foreach (ifset($data['actions'], array()) as $action_id => $action) {
                    $action = $wf->getActionById($action_id);
                    if ($action instanceof helpdeskWorkflowActionAutoInterface) {
                        $auto_actions[$wfid][$action_id] = $action;
                    }
                };
            } catch (waException $e) {}
        }

        if (!$flatten) {
            return $auto_actions;
        }

        $flatten_actions = array();
        foreach ($auto_actions as $wf => $actions) {
            foreach ($actions as $action) {
                $flatten_actions[] = $action;
            }
        }
        return $flatten_actions;
    }

    public static function workflowsAutoActionsExist()
    {
        $wfs = self::getWorkflowsConfig();
        foreach (ifempty($wfs['workflows'], array()) as $wfid => $data) {
            try {
                $wf = self::get($wfid);
                foreach (ifset($data['actions'], array()) as $action_id => $action) {
                    $action = $wf->getActionById($action_id);
                    if ($action instanceof helpdeskWorkflowActionAutoInterface) {
                        return true;
                    }
                }
            } catch (waException $e) {}
        }
        return false;
    }

    /**
     * Returns an array from workflows.php config file.
     */
    public static function getWorkflowsConfig()
    {
        if (self::$workflows_cfg === null) {
            $file = wa()->getConfig()->getConfigPath('workflows.php', true, 'helpdesk');
            if(file_exists($file)) {
                if (function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
                self::$workflows_cfg = include($file);
            }
            if (empty(self::$workflows_cfg)) {
                self::$workflows_cfg = array(
                    'actions' => array(),
                    'states' => array(),
                    'workflows' => array(
                        1 => array(
                            'id' => 1,
                            'name' => _w('Workflow'),
                            'classname' => 'helpdeskWorkflow',
                            'states' => array(),
                            'actions' => array(),
                        ),
                    ),
                );
                self::saveWorkflowsConfig(self::$workflows_cfg);
            }
        }
        return self::$workflows_cfg;
    }

    /**
     * Write $data to workflows.php config file.
     * @param array $data see self::getWorkflowsConfig() return value structure
     */
    public static function saveWorkflowsConfig($data)
    {
        // TODO: use system function
        $file = wa()->getConfig()->getConfigPath('workflows.php', true, 'helpdesk');
        waFiles::create($file);
        waUtils::varExportToFile($data, $file);
        self::$workflows_cfg = $data;
        self::$workflows = null;
        if (!file_exists($file) || !is_writable($file)) {
            throw new waException('Config file is not writable: '.$file);
        }
        clearstatcache();
    }

    //
    // Non-static functions common to all helpdesk workflows
    //

    /** @param int $id workflow_id as kept in database */
    public function __construct($id = null)
    {
        $this->id = $id;
        $cfg = self::getWorkflowsConfig();
        if (!isset($cfg['workflows'][$this->id])) {
            throw new waException('Unknown workflow: '.htmlspecialchars($this->id));
        }
        $this->name = $cfg['workflows'][$this->id]['name'];
    }

    public function getClientTriggerableActions()
    {
        if ($this->client_triggerable_actions === null) {
            $this->client_triggerable_actions = array();
            foreach ($this->getAvailableActions() as $action_id => $action) {
                if (!empty($action['options']['client_triggerable'])) {
                    try {
                        $action = $this->getActionById($action_id);
                        $this->client_triggerable_actions[$action_id] = $action;
                    } catch (Exception $e) {

                    }
                }
            }
        }
        return $this->client_triggerable_actions;
    }

    // see waWorkflow
    public function getAvailableActions()
    {
        return $this->getAvailableX('actions');
    }

    // see waWorkflow
    public function getAvailableStates()
    {
        return $this->getAvailableX('states');
    }

    /** getAvailableActions() and getAvailableStates() helper. */
    protected function getAvailableX($entityType)
    {
        $wcfg = self::getWorkflowsConfig();
        if (!isset($wcfg['workflows'][$this->getId()]) || !isset($wcfg['workflows'][$this->getId()][$entityType])) {
            return array();
        }

        $result = $wcfg['workflows'][$this->getId()][$entityType];
        foreach($result as $id => &$data) {
            if (isset($wcfg[$entityType][$id])) {
                $data += $wcfg[$entityType][$id];
                if (isset($wcfg[$entityType][$id]['options'])) {
                    $data['options'] += $wcfg[$entityType][$id]['options'];
                }
            }
        }
        return $result;
    }

    /**
     * @param string $state
     * @param array|null $params = null
     *
     * List of actions that can be performed from given state.
     * uses func_get_args() instead of signature args for historical reasons (i.e. waWorkflow)
     *
     * @param mixed $state helpdeskWorkflowState or state_id
     * @return array id => helpdeskWorkflowAction
     */
    public function getActions(/*$state, $params = null*/)
    {
        $args = func_get_args();
        $state = $args[0];
        // not used already
        // $params = isset($args[1]) ? $args[1] : null;

        if ($state instanceof helpdeskWorkflowState) {
            $state = $state->getId();
        }
        $cfg = self::getWorkflowsConfig();

        if (isset($cfg['workflows'][$this->getId()]['states'][$state]['available_actions'])) {
            $action_ids = $cfg['workflows'][$this->getId()]['states'][$state]['available_actions'];
        } else if (isset($cfg['states'][$state]['available_actions'])) {
            $action_ids = $cfg['states'][$state]['available_actions'];
        } else {
            return array();
        }

        $actions = array();
        foreach ($action_ids as $action_id) {
            try {
                if ( ( $action = $this->getActionById($action_id))) {
                    $actions[$action->getId()] = $action;
                }
            } catch (Exception $e) {
                // No such action for some reason. Ignore.
            }
        }
        return $actions;
    }

    /**
     * @param string $state
     * @return array[helpdeskWorkflowActionAutoInterface]
     */
    public function getAutoActions($state)
    {
        $actions = array();
        foreach ($this->getActions($state) as $action) {
            if ($action instanceof helpdeskWorkflowActionAutoInterface) {
                $actions[$action->getId()] = $action;
            }
        }
        return $actions;
    }

    /**
     * Return transition(s) of given action.
     * See helpdeskTransition for background info.
     *
     * By consideration, the default transition for action is stored with '' (empty string) key.
     * BasicAction uses it.
     *
     * @param mixed $action helpdeskWorkflowAction or action_id
     * @param string $key
     * @return mixed when no $key specified, returns array(key => helpdeskTransition); othersize, returns helpdeskTransition (possibly empty, never null)
     */
    public function getTransition($action, $key=null)
    {
        if ($action instanceof helpdeskWorkflowAction) {
            $action = $action->getId();
        }
        $cfg = self::getWorkflowsConfig();

        if (isset($cfg['workflows'][$this->getId()]['actions'][$action]) && is_array($cfg['workflows'][$this->getId()]['actions'][$action]) && array_key_exists('transition', $cfg['workflows'][$this->getId()]['actions'][$action])) {
            $ts = $cfg['workflows'][$this->getId()]['actions'][$action]['transition'];
        } else if (isset($cfg['actions'][$action]) && is_array($cfg['actions'][$action]) && array_key_exists('transition', $cfg['actions'][$action])) {
            $ts = $cfg['actions'][$action]['transition'];
        } else {
            $ts = null;
        }

        // canonic form: array(key => config as accepted by helpdeskTransition)
        if (!strlen($ts)) {
            $ts = array();
        } else if (!is_array($ts) || isset($ts['actions']) || isset($ts['state_id'])) {
            $ts = array('' => $ts);
        }

        if ($key === null) {
            foreach($ts as $k => &$v) {
                $v = new helpdeskTransition($this, $v);
            }
            return $ts;
        }

        if(!isset($ts[$key]) && isset($ts[''])) {
            $key = '';
        }

        if (isset($ts[$key])) {
            return new helpdeskTransition($this, $ts[$key]);
        }

        return new helpdeskTransition($this);
    }

    // override to support default for 'deleted' state
    public function getStateById($id)
    {
        // is there a state in parent cache?
        if (isset($this->states[$id])) {
            return $this->states[$id];
        }

        $cfg = self::getWorkflowsConfig();

        // workflow defines this state?
        if (isset($cfg['workflows'][$this->getId()]['states'][$id])) {
            return parent::getStateById($id);
        }

        // is there a common state with this id?
        if (isset($cfg['states'][$id])) {
            return $this->createEntity($id, $cfg['states'][$id]);
        }

        throw new waException('Unknown state: '.htmlspecialchars($id), 404);
    }

    // override to support defaults for 'delete', 'restore' and 'delete_forever' actions
    public function getActionById($id)
    {
        // first look in cache
        if (isset($this->actions[$id])) {
            return $this->actions[$id];
        }

        $cfg = self::getWorkflowsConfig();

        // workflow defines the action?
        if (isset($cfg['workflows'][$this->getId()]['actions'][$id])) {
            return parent::getActionById($id);
        }

        // is there a common action with this id?
        if (isset($cfg['actions'][$id])) {
            return $this->createEntity($id, $cfg['actions'][$id]);
        }

        throw new waException('Unknown action: '.htmlspecialchars($id), 404);
    }

    /**
     * Relative path from app root to workflow root (no trailing slash, no leading slash).
     * May be oferriden e.g. when workflow lives in a plugin.
     * @param string $path appended at the end of the path, leading slash required
     * @return string
     */
    public function getPath($path = null)
    {
        return dirname(waAutoload::getInstance()->get(get_class($this)));
    }

    public function _w($msgid1, $msgid2 = null, $n = null, $sprintf = true)
    {
        return _w($msgid1, $msgid2, $n, $sprintf);
    }

    public function getAllActions()
    {
        $result = parent::getAllActions();
        uasort($result, array($this, 'cmpNameFunc'));
        return $result;
    }

    public function getAllStates()
    {
        $result = parent::getAllStates();
        uasort($result, array($this, 'cmpNameFunc'));
        return $result;
    }

    public function cmpNameFunc($a, $b)
    {
        return strcmp($a->getName(), $b->getName());
    }

    /**
     * Request params for advanced search.
     * @return array param_id => options
     */
    public function getRequestParams()
    {
        $result = array();

        // request parameters added by actions
        foreach($this->getAllActions() as $act) {
            $result += $act->getRequestParams();
        }

        // request parameters added by sources
        $sql = "SELECT source_id
                FROM helpdesk_source_params
                WHERE name='workflow'
                AND value=:wid";
        $m = new waModel();
        foreach ($m->query($sql, array('wid' => $this->getId()))->fetchAll() as $row) {
            try {
                $s = new helpdeskSource($row['source_id']);
                $result += $s->getSourceType()->getRequestParams();
            } catch (Exception $e) {
                // Source probably does not exist anymore, ignore.
            }
        }

        return $result;
    }

    /**
    * Get related sources
    * @return array id => helpdeskSource
    */
    public function getSources()
    {
        $sources = array();
        foreach (wao(new helpdeskSourceModel())->getByWorkflowId($this->getId()) as $s) {
            try {
                $sources[$s['id']] = new helpdeskSource($s['id']);
            } catch (waException $e) {

            }
        }
        return $sources;
    }


    public function delete()
    {
        $wfs = self::getWorkflowsConfig();
        $id = $this->getId();
        if (isset($wfs['workflows'][$id])) {
            unset($wfs['workflows'][$id]);

            self::saveWorkflowsConfig($wfs);

            $sql = "SELECT source_id
                    FROM helpdesk_source_params
                    WHERE name='workflow'
                    AND value=:wid";
            $m = new waModel();
            foreach ($m->query($sql, array('wid' => $id))->fetchAll() as $row) {
                $s = new helpdeskSource($row['source_id']);
                $s->delete();
            }

            $file = wa()->getConfig()->getConfigPath('graph.php', true, 'helpdesk');
            $graph = include($file);
            unset($graph[$id]);

            waUtils::varExportToFile($graph, $file);

        }
    }

    public function update($id, $data)
    {
        $wfs = self::getWorkflowsConfig();
        if (isset($wfs['workflows'][$id])) {
            // TODO: support only name yet, fix it
            $wfs['workflows'][$id]['name'] = $data['name'];
            self::saveWorkflowsConfig($wfs);
        }
    }

    static public function getOneClickFeedbackFields($with_html = false)
    {
        $vars = array();
        foreach (helpdeskRequestFields::getFields() as $field_id => $field) {
            if ((($field instanceof helpdeskRequestSelectField) ||
                    ($field instanceof helpdeskRequestCheckboxField)) &&
                        $field->getParameter('my_visible')) {
                $vars[$field->getId()] = $with_html ? $field->getInfo() : array('name' => $field->getName());
            }
        }

        $res = array();
        $prefix = self::PREFIX_ONE_CLICK_FEEDBACK_HREF;
        foreach ($vars as $field_id => $field_info) {
            $field_name = $field_info['name'];
            $id = '@{' . $field_id . '}';
            if ($with_html) {
                $html = "<p>{$field_info['name']}:<br>";
                
                $elems = array();
                if ($field_info['type'] === 'Select') {
                    foreach ($field_info['options'] as $val => $name) {
                        $elems[] = "<a href=\"{{$prefix}{$field_id}:{$val}}\">{$name}</a>";
                    }
                }
                if ($field_info['type'] === 'Checkbox') {
                    $elems[] = "<a href=\"{{$prefix}{$field_id}:1}\">"._w('Yes')."</a>";
                    $elems[] = "<a href=\"{{$prefix}{$field_id}:0}\">"._w('No')."</a>";
                }
                $html .= implode(' &middot; ', $elems);
                $html .= '</p>';
                $res[$id]['name'] = $field_name;
                $res[$id]['html'] = $html;
                $res[$id]['field_id'] = $field_id;
                $res[$id]['description'] = sprintf(_w('Available values for "%s"'), $field_name);
            } else {
                $res[$id] = $field_name;
            }
        }
        return $res;
    }
}

