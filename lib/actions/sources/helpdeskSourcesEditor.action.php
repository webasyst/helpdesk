<?php
/**
 * Form HTML to create new or edit existing source.
 */
class helpdeskSourcesEditorAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $wf = null;
        $workflow_id = waRequest::request('workflow_id', null, 'int');
        if ($workflow_id) {
            try {
                $wf = helpdeskWorkflow::getWorkflow($workflow_id);
            } catch (Exception $e) {
                $wf = null;
                $workflow_id = null;
            }
        }

        // edit existing source instance?
        if ( ( $id = waRequest::request('id', 0, waRequest::TYPE_STRING_TRIM))) {
            $source = helpdeskSource::get($id);
            $st = $source->getSourceType();
        }
        // create new source instance of given type?
        else if ( ( $st = waRequest::request('st'))) {
            $source = null;
            $st = helpdeskSourceType::get($st);
        }
        // dunno what to do...
        else {
            throw new waException('Neither source id nor source type given.');
        }

        // Get HTML content. Possibly with validation errors highlighted.
        // This also saves the source if POST came and validation passed.
        $plugin = $st->getPlugin();
        $plugin && waSystem::pushActivePlugin($plugin, 'helpdesk');
        $form_html = $st->settingsController($wf, $source);
        $plugin && waSystem::popActivePlugin();

        /**
         * @event source_editor
         * @param helpdeskSource $params['source'] may be null
         * @param helpdeskSourceType $params['source_type']
         * @param helpdeskWorkflow $params['workflow'] may be null
         * @param string $params['form_html']
         * @return string HTML to add to the request page
         */
        $params = array(
            'source' => $source,
            'source_type' => $st,
            'workflow' => $wf,
            'form_html' => &$form_html,
        );
        $event_results = wa('helpdesk')->event('source_editor', $params);

        // If source returns an empty string, it wants us to go back to workflow editor page
        if (!$form_html) {
            $redirect = '$.wa.back();';
            if ($wf) {
                $redirect = 'window.location.hash = "#/settings/workflow/'.$wf->getId().'";';
            }
            echo '<div class="block"></div><script>(function() { "use strict";
                $.wa.helpdesk_controller.reloadSidebar();
                '.$redirect.'
            })();</script>';
            exit;
        }

        $form_html .= join("\n\n", $event_results);

        // Error messages from checking this source recently
        $last_error = null;
        if ($source && $source->id && !empty($source->params->error_datetime)) {
            $last_error = wao(new helpdeskErrorModel())->getLastBySource($source->id);
        }

        // Data for common error indocators in sidebar and above layout
        list($workflows_errors, $sources_errors) = helpdeskHelper::getWorkflowsErrors();
        
        $this->view->assign('wf', $wf);
        $this->view->assign('st', $st);
        $this->view->assign('source', $source);
        $this->view->assign('form_html', $form_html);
        $this->view->assign('last_error', $last_error);
        $this->view->assign('icon_url', helpdeskHelper::getSourceIconUrl($source ? $source : $st->getNewSource()));
        $this->view->assign('workflows_errors', $workflows_errors);
        $this->view->assign('sources_errors', $sources_errors);
    }
}

