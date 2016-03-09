<?php
/**
 * Performs frontend-enabled workflow actions from customer portal
 */
class helpdeskFrontendActionFormController extends waController
{
    public function execute()
    {
        $this->processAction(
            waRequest::param('workflow_id'),
            waRequest::param('action_id'),
            waRequest::param('params'),
            wa()->getUser()
        );
    }

    public function processAction($workflow_id, $action_id, $params, $user, $reload = true)
    {
        try {
            $params = helpdeskWorkflowAction::prepareParams($params, $action_id);
            if (!$user->getId() || $params['request']->client_contact_id != $user->getId()) {
                throw new waRightsException(_w('You have no access rights to this request.'));
            }
            $action = $this->getAction($workflow_id, $action_id);

            $plugin = $action->getPlugin();
            $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
            $form_html = $action->formController($params);
            $plugin && waSystem::popActivePlugin();

            if ($form_html) {
                if (!waRequest::isXMLHttpRequest()) {
                    echo "<!DOCTYPE html><html><body>";
                }
                echo $form_html;
                if (!waRequest::isXMLHttpRequest()) {
                    echo "</body></html>";
                }
            } else {
                $action->run($params);
                if ($reload) {
                    $this->echoReload();
                }
            }
        } catch (Exception $e) {
            if (waSystemConfig::isDebug()) {
                throw $e;
            } else {
                waLog::log('Unable to perform workflow action in customer portal: '.$e->getMessage()."\n".$e->getTraceAsString(), 'helpdesk.log');
                throw new waException('Bad parameters.', 500);
            }
        }
    }

    public function getAction($workflow_id, $action_id)
    {
        if ($action_id !== helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID) {
            $action = helpdeskWorkflow::getWorkflow($workflow_id)->getActionById($action_id);
            if (!$action->getOption('client_visible') || !$action->getOption('client_triggerable')) {
                throw new waException(_w('This action cannot be performed from frontend.'));
            }
            return $action;
        } else {
            return helpdeskOneClickFeedback::getAction($workflow_id);
        }
    }

    public function echoReload()
    {
        if (!waRequest::isXMLHttpRequest()) {
            echo "<!DOCTYPE html><html><body>";
        }
        echo "<script>if (typeof $ != 'undefined') { window.location.reload(); }</script>";
        if (!waRequest::isXMLHttpRequest()) {
            echo "</body></html>";
        }
    }
}

