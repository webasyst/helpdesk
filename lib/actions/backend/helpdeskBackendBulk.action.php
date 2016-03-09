<?php
/**
 * Dialogs to perform operations with multiple requests.
 */
class helpdeskBackendBulkAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        // Workflow states
        $states = array();
        $workflows = helpdeskWorkflow::getWorkflows();
        foreach($workflows as $wf) {
            foreach($wf->getAllStates() as $state) {
                if (count($workflows) > 1) {
                    $states[$wf->getId().'@'.$state->getId()] = $wf->getName().' -> '.$state->getName();
                } else {
                    $states[$state->getId()] = $state->getName();
                }
            }
        }

        // Assignees
        $assignees = helpdeskHelper::getAssignOptions();

        if ( ( $ids = waRequest::request('ids', '', 'string'))) {
            $this->view->assign('selected_ids', explode(',', $ids));
        }

        $this->view->assign('states', $states);
        $this->view->assign('assignees', $assignees);
        $this->view->assign('action_type', waRequest::request('action_type'));
    }
}

