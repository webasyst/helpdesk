<?php
/**
 * Action editor. One of sub-editors of the workflow editor.
 *
 * Returns form HTML to configure an existing action. Accepts submit from this form.
 * Uses helpdeskAction->settingsController() to do actual work.
 */
class helpdeskEditorActionController extends waController
{
    public function execute()
    {
        $this->checkRights();


        $action = $this->getAction();

        $plugin = $action->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
        $form_html = $action->settingsController();
        $plugin && waSystem::popActivePlugin();

        if ($form_html) {
            echo $form_html;
        } else {
            echo (helpdeskHelper::isLegacyUi()
                ? '<script>(function() { "use strict"; $("#c-core-content .tab-content:first").html(\'<div class="triple-padded block"><i class="icon16 loading"></i></div>\'); $.wa.helpdesk_controller.redispatch(); $.wa.dialogHide(); })();</script>'
                : 'ok'
            );
            $params = array();
            wa('helpdesk')->event('action_editor', $params);
        }
    }

    public function checkRights()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
    }

    /**
     * @return helpdeskWorkflowAction
     */
    public function getAction()
    {
        $workflow_id = waRequest::request('wid');
        $wf = helpdeskWorkflow::getWorkflow($workflow_id);

        $action_id = waRequest::request('action_id');
        $state_id = waRequest::request('state_id');
        if (!strlen($state_id)) {
            throw new waException('Bad parameters.');
        }
        if (!strlen($action_id)) {
            $action_id = '';
            $action_class = waRequest::request('action_class');
            if (empty($action_class) || !class_exists($action_class)) {
                throw new waException('Bad parameters.');
            }
        }

        if ($action_id) {
            $action = $wf->getActionById($action_id);
        } else {
            $action = new $action_class('', $wf, array());
        }

        return $action;
    }
}
