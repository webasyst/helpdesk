<?php
/**
 * One of sub-editors of the workflow editor.
 *
 * Removes given state from given workflow.
 */
class helpdeskEditorRemstateSaveController extends helpdeskJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $workflow_id = waRequest::request('wid');
        $state_id = waRequest::request('eid');

        $wf = helpdeskWorkflow::getWorkflow($workflow_id);
        $s = $wf->getStateById($state_id); // check that state exists

        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        if (!empty($cfg['workflows'][$workflow_id]['states'][$state_id])) {
            $old_actions = ifset($cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions'], array());
            unset($cfg['workflows'][$workflow_id]['states'][$state_id]);

            // If there are actions that transfer to this state, fix their transitions to keep existing state instead
            foreach($wf->getAllActions() as $a) {
                if (isset($cfg['workflows'][$workflow_id]['actions'][$a->getId()]['transition'])) {
                    $transitions =& $cfg['workflows'][$workflow_id]['actions'][$a->getId()]['transition'];
                    if (is_array($transitions)) {
                        foreach($transitions as $k => &$v) {
                            if ($v == $state_id) {
                                $v = null;
                            }
                        }
                        unset($v);
                    } else if ($transitions == $state_id) {
                        $transitions = null;
                    }
                    unset($transitions);
                }
            }

            // Check if actions of this state are available anywhere else.
            // If they don't, remove them from this workflow.
            foreach($old_actions as $action_id) {
                $can_remove = true;
                if (!empty($cfg['workflows'][$workflow_id]['states'])) {
                    foreach($cfg['workflows'][$workflow_id]['states'] as $s) {
                        if (!empty($s['available_actions']) && is_array($s['available_actions']) && in_array($action_id, $s['available_actions'])) {
                            $can_remove = false;
                            break;
                        }
                    }
                }
                if($can_remove) {
                    unset($cfg['workflows'][$workflow_id]['actions'][$action_id]);
                }
            }
        }

        helpdeskWorkflow::saveWorkflowsConfig($cfg);
        $this->response = 'ok';
    }
}

