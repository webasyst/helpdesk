<?php
/**
 * Base class for all workflow actions.
 *
 * Actions always modify existing requests.
 * (Sources, on the other hand, create requests out of nowhere, i.e. from user input.)
 * Action does not control whether it is available for given request. Workflow class controls that.
 *
 * Action is responsible for the following:
 *
 * - Show button on the request page.
 *   See $this->getButton()
 *
 * - Show form when user clicks action button on the request page.
 *   This is optional. May return empty string to execute the action immidiately.
 *   See $this->formController()
 *
 * - Execute action on given request (possibly with data from $_POST).
 *   This possibly changes request, adds a record to history, etc.
 *   See $this->run(), $this->execute()
 *
 * - Show action settings form in workflow editor
 *   and accept submit from that form.
 *   See $this->settingsController()
 *
 * See helpdeskWorkflowBasicAction for live example.
 */
class helpdeskWorkflowAction extends waWorkflowAction
{
    const PREFIX_REQUEST_FIELD = 'allow_fld_';

    protected $request_fields;


    /**
     * Perform this action on given request.
     *
     * Responsible for 'request_action' event.
     * Actual event call is in helpdeskHelper::saveRequestLog(), though.
     *
     * Responsible for waSystem::pushActivePlugin()/popActivePlugin()
     * for localization, in case this action lives in a plugin.
     *
     * $params may be one of:
     * - (int) request_id
     * - helpdeskRequest
     * - array with at least one key set: request_id => int, or request => helpdeskRequest
     * See self::prepareParams() for details.
     *
     * @param mixed $params
     * @return int|null newly created request_log_id, or null when no log created
     */
    public function run($params = null)
    {
        // Wrapping code for plugins requred for localization
        $plugin = $this->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');

        $params = self::typecastParams($params);
        $params = self::prepareParams($params, $this->getId());

        // Make sure request log record is created and have an ID before entering execute()
        if (!$params['request_log']->getId()) {
            helpdeskHelper::prepareRequestLog($params['request'], $params['request_log']);
            $params['request_log']->save();
        }

        // Run preExecute(), execute(), postExecute()
        $result = null;
        $exception = null;
        try {
            parent::run($params);
        } catch (Exception $e) {
            $exception = $e;
            $params['request_log']->params->error_message = $e->getMessage();
        }

        // Save the requestLog unless asked not to
        if (empty($params['do_not_save'])) {
            $result = helpdeskHelper::saveRequestLog($params['request'], $params['request_log']);
        }

        // Wrapping code for plugins requred for localization
        $plugin && waSystem::popActivePlugin($params);
        $this->logPerformAction($params);

        if ($exception) {
            throw $exception;
        }
        return $result;
    }

    public function logPerformAction($params)
    {
        helpdeskHelper::logAction('perform_action', null, null, $params['request_log']->actor_contact_id);
    }

    /**
     * Core logic for this action.
     * Subclasses should override this instead of run().
     * Responsible for changing request state accorging to transition,
     * as well as calling additional actions specified in transition, if any.
     *
     * preExecute() and postExecute() may also be useful for subclassing, see waWorkflowAction.
     */
    public function execute($params = null)
    {
        // Change request state according to transition set up in wirkflow config
        $transition = $this->getTransition($params['request'], $params['request_log']);
        $params['request_log']->after_state_id = $transition->getStateId();

        // Perform other actions specified in transition
        $params['do_not_save'] = true;
        foreach($transition->getActions() as $action) {
            $action->run($params);
        }
    }

    /**
     * @param array|helpdeskRequest|int $params
     * @return array
     * @throws waException
     */
    protected static function typecastParams($params)
    {
        // Prepare $params['request'] and $params['request_id'], and run basic sanity checks
        if (!is_array($params)) {
            if (wa_is_int($params)) {
                $params = array(
                    'request_id' => $params,
                );
            } else if ($params instanceof helpdeskRequest) {
                $params = array(
                    'request_id' => $params->id,
                    'request' => $params,
                );
            } else {
                throw new waException('Workflow action expects array, request_id or helpdeskRequest as $params.');
            }
        }
        if (empty($params['request']) || !($params['request'] instanceof helpdeskRequest)) {
            if (empty($params['request_id']) || !wa_is_int($params['request_id'])) {
                throw new waException('No request for workflow action.');
            }
            $params['request'] = new helpdeskRequest($params['request_id']);
        }
        $params['request_id'] = $params['request']->getId();
        if (empty($params['request_id'])) {
            throw new waException('Request must be saved for workflow actions to run on them.');
        }

        if (empty($params['request_log'])) {
            $params['request_log'] = new helpdeskRequestLog();
        }
        return $params;
    }

    /**
     * @param array|helpdeskRequest|int $params
     * @param string
     *
     * $this->run() helper. Builds $params for $this->execute()
     */
    public static function prepareParams($params, $action_id)
    {
        $params = self::typecastParams($params);
        foreach(array(
            'request_id' => $params['request_id'],
            'action_id' => $action_id,
            'before_state_id' => $params['request']['state_id'],
            'actor_contact_id' => ifset($params['actor_contact_id'], wa()->getUser()->getId()),
        ) as $k => $v) {
            if (empty($params['request_log'][$k])) {
                $params['request_log'][$k] = $v;
            }
        }
        return $params;
    }

    /**
     * $this->execute() helper.
     * Determine transition for this request according to action's internal logic.
     * See helpdeskTransition for background info.
     */
    protected function getTransition($request, $log)
    {
        return $this->getWorkflow()->getTransition($this, '');
    }

    /** Human-readable name of this action. */
    public function getName()
    {
        if ( ( $name = $this->getOption('name'))) {
            return waLocale::fromArray($name);
        }
        return parent::getName();
    }

    /**
     * Return HTML for action button to show on request info page
     * when action can be performed.
     */
    public function getButton()
    {
        // Buttin for backend
        if ($this->getOption('user_triggerable') && wa()->getEnv() == 'backend') {
            $attrs = '';
            if ($this->getOption('user_button_border_color')) {
                $attrs = ' style="border-color:'.htmlspecialchars($this->getOption('user_button_border_color')).'"';
            }
            return '<input class="button '.$this->getOption('user_button_css_class').'" type="submit" name="'.$this->getId().'" value="'.htmlspecialchars(waLocale::fromArray($this->getOption('user_button_value'))).'"'.$attrs.'>';
        }

        // Button for customer portal
        if ($this->getOption('client_triggerable') && wa()->getEnv() == 'frontend') {
            $attrs = '';
            if ($this->getOption('user_button_border_color')) {
                $attrs = ' data-action-color="'.htmlspecialchars($this->getOption('user_button_border_color')).'"';
            }
            return '<input type="submit" name="'.$this->getId().'" value="'.htmlspecialchars(waLocale::fromArray($this->getOption('user_button_value'))).'"'.$attrs.'>';
        }

        return null;

    }

    /**
     * Return HTML to show on request info page when user clicks action button.
     * May return '' to perform action immidiately after user presses the button, without the second step.
     *
     * Also called when user submits that form. At this time, it may apply validation if necessary
     * and return HTML again if something's wrong. HTML will be shown to user. Or it may return empty string
     * to run the action.
     *
     * @param mixed $params same as for $this->run()
     * @return string HTML, or '' to perform immidiately without the second step
     */
    public function formController($params)
    {
        return '';
    }

    /**
     * Interface function for workflow editor.
     * Returns HTML form for this action's settings.
     *
     * If data came via POST, saves them to workflow config.
     * In this case may return '' to hide the dialog and reload the page.
     */
    public function settingsController()
    {
        return '';
    }

    /**
     * Action description to use in log.
     * @param array $action_db_row a row from helpdesk_request_log
     * @return string to use in context %username% %performs_action%
     */
    public function getPerformsActionString($action_db_row=null)
    {
        // Reasonable default for debugging only. Should be overriden in subclasses.
        return _w('performs action').' <span style="color:'.$this->getOption('user_button_border_color', '#000').'">'.htmlspecialchars($this->getName()).'</span>';
    }

    /**
     * Request params that this action possibly adds to request.
     * Used to build list of fields for advanced search.
     * @return array param_id => options; for simple text fields options is just a string with human readable name.
     */
    public function getRequestParams()
    {
        return array();
    }

    /**
     * Default values for $this->options.
     * See waWorkflowEntity for details.
     */
    public function getDefaultOptions()
    {
        return array_merge(parent::getDefaultOptions(), array(
            /** Whether to show a button to execute this action in backend interface. For system and client actions this is false. */
            'user_triggerable' => true,

            /** Whether to show a button to execute this action in customer portal interface. */
            'client_triggerable' => false,

            /** Whether to ignore client_triggerable and user_triggerable settings. Used by sources and transitions to run the action no matter what. */
            'always_triggerable' => false,

            /** Whether to show this action in history log in customer portal. */
            'client_visible' => false,

            /** Whether to show form when user tries to execute an action from backend; false to execute immidiately */
            'execute_immidiately' => true,

            /** CSS class to add to button in backend interface. */
            'user_button_css_class' => '',

            /** Border color for button in backend interface. */
            'user_button_border_color' => '',

            /** Text on the button in backend interface. */
            'user_button_value' => $this->getName(),

            /** Name of this action */
            'name' => get_class($this),

            /** Whether a user may change states this action is available from. */
            'allow_change_available_states' => true,
        ));
    }

    /** template file name for $this->display */
    protected function getTemplateBasename($template='')
    {
        if (preg_match('~Workflow(.+)Action$~', get_class($this), $m)) {
            return $m[1].($template ? "_".$template : "").$this->getView()->getPostfix();
        }
        return parent::getTemplateBasename($template);
    }

    /**
     * template dir for $this->display()
     * By default looks in `templates` subdir of the directory where the action .php file is
     */
    protected function getTemplateDir()
    {
        return dirname(waAutoload::getInstance()->get(get_class($this))).'/templates/';
    }

    /**
     * Helper for formController() and settingsController()
     * to work with Smarty templates.
     */
    protected function display($template = '')
    {
        $dir = $this->getTemplateDir();
        $basename = $this->getTemplateBasename($template);
        if (!file_exists($dir.$basename)) {
            return '<p>Template '.$dir.$basename.' not found.</p>';
        }
        $result = $this->getView()->fetch($dir.$basename);
        $params = array(
                'action' => $this,
                'view' => $this->view,
        );
        $plugin_blocks = wa('helpdesk')->event('view_workflow_action', $params);
        foreach($plugin_blocks as $html) {
            $result .= $html;
        }
        return $result;
    }

    /**
     * If this action is a part of a plugin, then return plugin id.
     * Otherwise, return null.
     * @return string|null
     */
    public function getPlugin()
    {
        return helpdeskHelper::getPluginByClass(get_class($this));
    }

    protected function init()
    {
        // Load plugin locale if this action lives in a plugin
        $plugin = $this->getPlugin();
        $plugin && helpdeskHelper::loadPluginLocale($plugin);
    }

    public function getMessagePreview($request = null,  $params = null)
    {
        return '';
    }

    public function getRequestFieldsIds()
    {
        $prefix = self::PREFIX_REQUEST_FIELD;
        $prefix_len = strlen($prefix);
        $request_fields = array();
        foreach ($this->options as $key => $val) {
            if (substr($key, 0, $prefix_len) === $prefix && $val) {
                $request_fields[] = substr($key, $prefix_len);
            }
        }
        return $request_fields;
    }

    public function getRequestFields()
    {
        if ($this->request_fields === null) {
            $this->request_fields = array();
            foreach ($this->getRequestFieldsIds() as $field_id) {
                $field = helpdeskRequestFields::getField($field_id);
                if ($field) {
                    $this->request_fields[$field_id] = $field;
                }
            }
        }
        return $this->request_fields;
    }

    public function getAvailableStates()
    {
        $states = array();
        foreach ($this->workflow->getAvailableStates() as $state_id => $state) {
            $available_actions = ifset($state['available_actions'], array());
            if (in_array($this->id, $available_actions)) {
                $states[$state_id] = $state;
            }
        }
        return $states;
    }

    /**
     * @param $options
     * @return $this
     */
    public function setOptions($options)
    {
        foreach ($options as $key => $value) {
            $this->setOption($key, $value);
        }
        return $this;
    }

}

