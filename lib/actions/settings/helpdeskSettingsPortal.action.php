<?php
/** Customer portal settings page */
class helpdeskSettingsPortalAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        if (waRequest::post()) {

            $cfg = helpdeskWorkflow::getWorkflowsConfig();

            // Client-visible state names
            $states = waRequest::request('states', array(), 'array');
            foreach($cfg['workflows'] as $wf_id => &$wf_cfg) {
                foreach ($wf_cfg['states'] as $state_id => &$state_cfg) {
                    if (!empty($states[$wf_id][$state_id]['enabled'])) {
                        $state_cfg['options']['customer_portal_name'] = ifset($states[$wf_id][$state_id]['name']);
                    } else {
                        unset($state_cfg['options']['customer_portal_name']);
                    }
                }
            }
            unset($wf_cfg, $state_cfg);

            // Actions available for clients
            $actions = waRequest::request('actions', array(), 'array');
            foreach($cfg['workflows'] as $wf_id => &$wf_cfg) {
                foreach ($wf_cfg['actions'] as $action_id => &$action_cfg) {
                    unset($action_cfg['options']['client_triggerable']);
                    unset($action_cfg['options']['client_visible']);
                    switch (ifset($actions[$wf_id][$action_id])) {
                        case 'triggerable_visible_all':
                            $action_cfg['options']['client_triggerable'] = 1;
                            $action_cfg['options']['client_visible'] = 'all';
                            break;
                        case 'triggerable_visible_own':
                            $action_cfg['options']['client_triggerable'] = 1;
                            $action_cfg['options']['client_visible'] = 'own';
                            break;
                        case 'visible_all':
                            $action_cfg['options']['client_visible'] = 'all';
                            break;
                    }
                }
            }
            unset($wf_cfg, $action_cfg);

            helpdeskWorkflow::saveWorkflowsConfig($cfg);

            // Client-visible names of assigned users
            $portal_actor_display = waRequest::request('portal_actor_display', 'company_name', 'string');
            if ($portal_actor_display !== 'company_name' && $portal_actor_display !== 'contact_name') {
                $portal_actor_display = waRequest::request('portal_actor_display_custom', '', 'string');
                if (!strlen($portal_actor_display) || $portal_actor_display == wa()->getSetting('name', 'Webasyst', 'webasyst')) {
                    $portal_actor_display = 'company_name';
                }
            }
            wao(new waAppSettingsModel())->set('helpdesk', 'portal_actor_display', $portal_actor_display);

            // 'customerportal' source settings
            if ( ( $source_id = waRequest::request('source_id', 0, 'int'))) {
                try {
                    $source = new helpdeskSource($source_id);
                    if ($source->type !== 'customerportal') {
                        throw new waException('Wrong source');
                    }
                    $source->params->workflow = waRequest::request('workflow_id', 0, 'int');
                    if (!$source->params->workflow) {
                        $source->params->workflow = helpdeskWorkflow::get()->getId();
                    }
                    $source->params->new_request_state_id = waRequest::request('new_requests_state');
                    $source->params->new_request_assign_contact_id = waRequest::request('default_assignee', '', 'int');
                    $source->save();
                } catch (Exception $e) {
                    // Source does not exist, or is not of type 'customerportal'. Ignore.
                }
            }
        }

        $workflows = helpdeskWorkflow::getWorkflows();

        $settings = array(
            'portal_actor_display' => wa()->getSetting('portal_actor_display', 'company_name'),
        );

        // Forms available for client to submit new requests
        $sources = array();
        foreach (wao(new helpdeskSourceModel())->getAll(true) as $source_id => $source) {
            try {
                $s = helpdeskSource::get($source);
                $st = $s->getSourceType();
            } catch (Exception $e) {
                // Something is wrong, e.g. source type does not exist. Ignore this source.
                continue;
            }

            // This page only allows to set up one specific kind of form
            if ($s->status > 0 && $s->type == 'customerportal') {
                $sources[] = $s;
            }
        }

        if (count($sources) == 1) {
            $source = reset($sources);
            $source_id = $source->getId();
            $new_requests_state = $source->params->ifset('new_request_state_id');
            $wf_id = $source->params->ifset('workflow');
            if ($wf_id) {
                try {
                    $wf = helpdeskWorkflow::get($wf_id);
                } catch (Exception $e) {
                    $wf_id = null;
                }
            }
            if (!$wf_id) {
                $wf = helpdeskWorkflow::get();
                $wf_id = $wf->getId();
            }

            $wf_states = array();
            foreach($wf->getAllStates() as $s) {
                $wf_states[$s->getId()] = $s->getName();
            }

            $assignees = helpdeskHelper::getAssignOptions($wf_id);
            $default_assignee = $source->params->ifset('new_request_assign_contact_id');
        } else {
            $wf_id = null;
            $source_id = null;
            $wf_states = array();
            $new_requests_state = null;
            $assignees = null;
            $default_assignee = null;
        }

        $this->view->assign(array(
            'wf_id' => $wf_id,
            'source_id' => $source_id,
            'wf_states' => $wf_states,
            'assignees' => $assignees,
            'new_requests_state' => $new_requests_state,
            'default_assignee' => $default_assignee,
            'workflows' => $workflows,
            'settings' => $settings,
        ));
    }
}

