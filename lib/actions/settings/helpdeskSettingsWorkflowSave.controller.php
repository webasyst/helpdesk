<?php
/**
 * Workflow editor graph page.
 */
class helpdeskSettingsWorkflowSaveController extends waJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
        
        $wfid = waRequest::request('id', null, waRequest::TYPE_INT);
        $data = $this->getData();
        if (!$wfid) {
            $wfid = helpdeskWorkflow::addWorkflow($data);
        } else {
            helpdeskWorkflow::updateWorkflow($wfid, $data);
        }
        $this->response['workflow'] = helpdeskWorkflow::get($wfid);
    }
    
    public function getData()
    {
        $data = waRequest::post('data', array());
        if (isset($data['name']) && !$data['name']) {
            unset($data['name']);
        }
        return $data;
    }
}

