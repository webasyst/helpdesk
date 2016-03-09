<?php
/**
 * Bulk update for requests list.
 */
class helpdeskRequestsChangeSummaryController extends helpdeskJsonController
{
    public function execute()
    {
        $id = waRequest::post('id');

        $request = new helpdeskRequest($id);
        if (!$request->isVisibleForUser()) {
            throw new waRightsException(_w('Access denied.'));
        }

        $allowed = null;
        if (!helpdeskRightsModel::isAllowed($request->workflow_id, '!action.all')) {
            $rm = new helpdeskRightsModel();
            $allowed = $rm->getAllowedActions($rm->getIdsByUser(), $request->workflow_id);
        }
        $allowed_actions = array();
        try {
            foreach ($request->getWorkflow()->getActions($request->state_id) as $action) {
                if (!$action->getOption('user_triggerable')) {
                    continue;
                }
                if ($allowed !== null && !isset($allowed[$action->getId()])) {
                    continue;
                }
                if ( ( $html = $action->getButton())) {
                    $allowed_actions[$action->getId()] = $action;
                }
            }
        } catch (Exception $e) {
        }

        if (!$allowed_actions) {
            throw new waRightsException(_w('Access denied.'));
        }

        $summary = waRequest::request('summary');

        if ($request['summary'] !== $summary) {

            $log = new helpdeskRequestLog();
            $log->action_id = '!one_change_summary';
            $log->actor_contact_id = wa()->getUser()->getId();
            $log->params->old_summary = $request['summary'];
            $log->params->new_summary = $summary;
            helpdeskHelper::prepareRequestLog($request, $log);
            $log->save();

            $request['summary'] = $summary;
            $request->save();
        }

    }

}

