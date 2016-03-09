<?php
/**
 * Instances of this class represent transitions between workflow states.
 *
 * This is a tricky matter and requires some background info.
 *
 * Developer of workflow action does not know state_ids in advance and can not hard-code them into source code.
 * State ids are stored in config file and may be different for different installations. On the other hand,
 * developer may want the action to select one of several states to move the request into, depending on
 * action's internal logic.
 *
 * Transitions allow to do that without hard-coding state_ids into source.
 *
 * Workflow stores (transition_id => state_id) in action settings in config file.
 * Action logic hard-codes transition_ids and retrieves actual transitions from workflow at run time.
 * Everyone's pleased and live happily ever after.
 *
 * As a free bonus, it is possible to store a list of actions to run along with main action
 * as a part of transition.
 */
class helpdeskTransition
{
    public $config = null;
    public $wf = null;

    /**
     * $config may be one of:
     * - null
     * - (string) state_id
     * - array(
     *     state_id => null or (string) state_id
     *     actions => null, (string) action_id, or array(action_id, action_id, ...)
     *   )
     *
     * @param mixed $config
     * @param helpdeskWorkflow $workflow
     */
    public function __construct($workflow, $config=null)
    {
        $this->config = $config;
        $this->wf = $workflow;
    }

    /** @return int|null state_id or null if state does not change */
    public function getStateId()
    {
        if ($this->config === null) {
            return null;
        }

        if (!is_array($this->config)) {
            return $this->config;
        }

        if (!isset($this->config['state_id'])) {
            return null;
        }

        return $this->config['state_id'];
    }

    public function getStateName()
    {
        if (null !== ( $s = $this->getStateId())) {
            return $this->wf->getStateById($s)->getName();
        }
        return _w('<no change>');
    }

    /**
     * List of actions to run as a part of this transition
     * @return array of helpdeskWorkflowAction
     */
    public function getActions()
    {
        if (!$this->config || !is_array($this->config) || !isset($this->config['actions']) || !$this->config['actions']) {
            return array();
        }

        if (!is_array($this->config['actions'])) {
            return array($this->wf->getActionById($this->config['actions']));
        }

        $result = array();
        foreach($this->config['actions'] as $aid) {
            $result[] = $this->wf->getActionById($aid);
        }
        return $result;
    }

    public function getConfig()
    {
        return $this->config;
    }
}

