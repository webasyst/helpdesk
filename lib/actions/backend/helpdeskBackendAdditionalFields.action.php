<?php
/** 
 * Part of advanced search form with additional parameters depending on workflow.
 */
class helpdeskBackendAdditionalFieldsAction extends helpdeskViewAction
{
    public function execute()
    {
        $wf = waRequest::request('wf');

        // allowed workflows
        $rm = new helpdeskRightsModel();
        $allowed = $rm->getWorkflowsRequestsRights($rm->getIdsByUser());

        $fields = array();
        if ($wf) {
            if (isset($allowed[$wf])) {
                try {
                    $fields = helpdeskWorkflow::getWorkflow($wf)->getRequestParams();
                } catch (Exception $e) {
                }
            }
        } else {
            foreach (helpdeskWorkflow::getWorkflows() as $wf) {
                if (!isset($allowed[$wf->getId()])) {
                    continue;
                }
                $fields += $wf->getRequestParams();
            }
        }
        $this->view->assign('fields', $fields);
    }
}

