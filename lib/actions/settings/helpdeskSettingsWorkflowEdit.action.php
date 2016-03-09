<?php
/**
 * Workflow editor graph page.
 */
class helpdeskSettingsWorkflowEditAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
        $wfid = waRequest::request('id');

        
        $this->view->assign(array(
            'id' => $wfid,
            'last_id' => helpdeskWorkflow::getWorkflowsLastId()
        ));
        
    }
}

