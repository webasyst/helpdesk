<?php
/**
 * One of sub-editors of the workflow editor.
 *
 * Removes given action from given state.
 */
class helpdeskEditorDelactionController extends helpdeskJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $workflow_id = waRequest::request('workflow_id');
        $state_id = waRequest::request('state_id');
        $action_id = waRequest::request('action_id');

        $wf = helpdeskWorkflow::getWorkflow($workflow_id);
        $s = $wf->getStateById($state_id); // check that state exists

        $cfg = helpdeskWorkflow::getWorkflowsConfig();

        // Remove action from given state
        if (!empty($cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions'])) {
            $aa =& $cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions'];
            foreach($aa as $i => $a) {
                if ($a == $action_id) {
                    unset($aa[$i]);
                }
            }
            $aa = array_values($aa);
            unset($aa);
        }

        // Check if this action is available in any other state.
        // If it's not, remove it from this workflow.
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

        helpdeskWorkflow::saveWorkflowsConfig($cfg);
        $this->response = 'ok';
    }
}

