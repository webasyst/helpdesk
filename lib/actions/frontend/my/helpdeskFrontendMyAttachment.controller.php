<?php
/**
 * Attachment download for frontend users.
 */
class helpdeskFrontendMyAttachmentController extends helpdeskBackendAttachController
{
    protected function isAllowed($r, $log_id, $attach_id)
    {
        try {
            // Check if the request belongs to client
            if ($r->client_contact_id != wa()->getUser()->getId()) {
                return false;
            }

            // Check if the log record is visible for this user in frontend
            if ($log_id) {
                $l = new helpdeskRequestLog($log_id);
                if ($l->request_id != $r->id) {
                    return false;
                }
                $action = $r->getWorkflow()->getActionById($l->action_id);
                if (!$action->getOption('client_visible')) {
                    return false;
                }
            }

            return true;
        } catch(Exception $e) {
            // Request, log, workflow or action do not exist
            return false;
        }
    }
}

