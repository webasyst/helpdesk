<?php
/**
 * Workflow editor graph page.
 */
class helpdeskSettingsWorkflowDeleteController extends waJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
        
        $wfid = waRequest::request('id');
        $wf = helpdeskWorkflow::getWorkflow($wfid);
        $wf->delete();
        $this->response['id'] = $wfid;
    }
}

