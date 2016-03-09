<?php
/**
 * Called when user clicks action button on request page in backend,
 * or submits that form.
 *
 * For actions with form, returns form HTML.
 * For actions without form, performs action and returns <script> tag to reload request page.
 * For actions that cannot be performed by backend users, throws 403.
 */
class helpdeskBackendActionFormController extends waController
{
    public function execute() {
        if (! ( $action_id = waRequest::request('wfa'))) {
            throw new waException('No action id given.');
        }
        if (! ( $request_id = waRequest::request('id', 0, 'int'))) {
            throw new waException('No request id given.');
        }

        // check access rights
        $r = new helpdeskRequest($request_id);
        if (!$r->isVisibleForUser()) {
            throw new waRightsException(_w('Access denied.'));
        }
        $action = $r->getWorkflow()->getActionById($action_id);
        if (!$action->getOption('user_triggerable')) {
            throw new waRightsException(_w('This action cannot be performed by user.'));
        }

        $plugin = $action->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
        $form_html = $action->formController($r);
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
            if (waRequest::request('message_preview') !== null) {
                echo $action->getMessagePreview($r, waRequest::request('message_preview'));
                exit;
            } else {
                $action->run($r);
            }
            $this->echoReload();
        }
    }

    public function echoReload()
    {
        if (!waRequest::isXMLHttpRequest()) {
            echo "<!DOCTYPE html><html><body>";
        }
        echo "<script>if (typeof $ != 'undefined' && $.wa && $.wa.helpdesk_controller) { $.wa.helpdesk_controller.redispatch(); }</script>";
        if (!waRequest::isXMLHttpRequest()) {
            echo "</body></html>";
        }
    }
}

