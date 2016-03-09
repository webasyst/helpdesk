<?php
/**
 * Workflow editor graph page.
 */
class helpdeskSettingsWorkflowAction extends helpdeskViewAction
{
    public function execute()
    {
        $this->getConfig()->updateCliEnvHelpdeskBackendUrl();

        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $wfid = waRequest::request('id');
        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        $wf = helpdeskWorkflow::getWorkflow($wfid);

        // Fetch info about actions of current workflow
        $actions_data = array();
        $foreign_states = array();
        foreach($cfg['workflows'][$wfid]['actions']+$cfg['actions'] as $aid => $data) {
            $actions_data[$aid] = $this->getActionData($wf->getActionById($aid));

            // states of other workflows this action can transit to
            foreach($actions_data[$aid]['states'] as $st_id) {
                if (strpos($st_id, ':') !== false) {
                    list($wf2id, $state2id) = explode(':', $st_id, 2);
                    $foreign_states[$wf2id][$state2id] = $st_id;
                }
            }
        }

        // Fetch info about states of current workflow
        $states_data = array();
        foreach($cfg['workflows'][$wfid]['states'] as $sid => $data) {
            $states_data[$sid] = $this->getStateData($wf->getStateById($sid));
        }

        // Fetch info about actions of other workflows that transit into this one,
        // and states of other workflows that this one can transit to.
        foreach(helpdeskWorkflow::getWorkflows() as $wf2id => $wf2) {
            if ($wf2id == $wfid) {
                continue;
            }

            // Actions of $wf2 that transit to states of $wf
            foreach($cfg['workflows'][$wf2id]['actions'] as $aid => $data) {
                $action_data = $this->getActionData($wf2->getActionById($aid));

                // Filter $action_data['states'] only keeping states of $wf
                $wf_states = array();
                foreach($action_data['states'] as $st_id) {
                    if (strpos($st_id, ':') !== false) {
                        list($wf3id, $state3id) = explode(':', $st_id, 2);
                        if ($wf3id == $wfid) {
                            $wf_states[] = $state3id;
                        }
                    }
                }
                $action_data['states'] = $wf_states;

                // Pass this action to template if it transits to one of this workflow's states
                if ($action_data['states']) {
                    $action_data['foreign'] = true;
                    $actions_data[$wf2id.':'.$aid] = $action_data;
                    $state_data = $this->getFakeStateData($wf2->getName(), $wf2id, $wf2id.':'.$aid);
                    $states_data[$state_data['id']] = $state_data;
                }
            }

            // States of $wf2 that $wf can transit to
            foreach(ifempty($foreign_states[$wf2id], array()) as $short_state_id => $full_state_id) {
                $state_data = $this->getStateData($wf2->getStateById($short_state_id));
                $states_data[$full_state_id] = array(
                    'id' => $full_state_id,
                    'name' => $wf2->getName(),
                    'foreign' => true,
                    'actions' => array(),
                    'foreign_state_name' => $state_data['name'],
                ) + $state_data;
            }
        }

        // whether or not current workflow can be deleted
        $can_remove = true;

        // Fetch info about sources of current workflow
        $sm = new helpdeskSourceModel();
        $sources = $sm->getAll(true);
        foreach ($sources as $k => &$s) {
            if ($s['status'] <= 0) {
                unset($sources[$k]);
                continue;
            }
            $source = helpdeskSource::get($s);
            $b = $source->describeBehaviour();
            if (empty($b[$wfid]) && $b) {
                unset($sources[$k]);
                continue;
            }

            $s['states'] = ifset($b[$wfid]['new_states'], array());
            $s['icon_url'] = helpdeskHelper::getSourceIconUrl($source);
            $s['connections_color'] = helpdeskHelper::getSourceIconColor($source);

            // Forbid to delete workflow with backend source
            $can_remove = $can_remove && $source->type !== 'backend';
        }
        unset($s);
        
        if ($this->getRights('backend') > 1) {
            $can_remove = true;
        }
        
        $sources = helpdeskGraphPositionModel::sortSources($wf->getId(), $sources);

        list($workflows_errors, $sources_errors) = helpdeskHelper::getWorkflowsErrors();

        // Source types
        $source_types = array();
        foreach(helpdeskSourceType::getSourceTypes(false) as $st) {
            $source_types[$st->getType()] = $st->getName();
        }

        $this->view->assign('wf', $wf);
        $this->view->assign('sources', $sources);
        $this->view->assign('states_data', $states_data);
        $this->view->assign('source_types', $source_types);
        $this->view->assign('actions_data', $actions_data);
        $this->view->assign('position_data', helpdeskGraphPositionModel::getPositions($wf->getId()));
        $this->view->assign('workflows_errors', $workflows_errors);
        $this->view->assign('sources_errors', $sources_errors);
        $this->view->assign('can_remove', $can_remove);
    }

    // Helper to build data for template
    protected function getActionData(helpdeskWorkflowAction $action)
    {
        $action_data = array(
            'id' => $action->getId(),
            'name' => $action->getName(),
            'states' => array(),
            'color' => $action->getOption('user_button_border_color'),
            'user_button_css_class' => $action->getOption('user_button_css_class'),
            'workflow_id' => $action->getWorkflow()->getId()
        );

        if ($action instanceof helpdeskWorkflowActionAutoInterface) {
            $action_data['auto'] = 1;
            $action_data['timeout'] = $action->getTimeout();
        }

        // States this action can possibly transit to
        foreach($action->getWorkflow()->getTransition($action) as $t) {
            $action_data['states'][$t->getStateId()] = true;
        }
        $action_data['states'] = array_keys($action_data['states']);

        return $action_data;
    }

    // Helper to build data for template
    protected function getStateData(helpdeskWorkflowState $state)
    {
        $state_data = array(
            'id' => $state->getId(),
            'name' => $state->getName(),
            'list_row_css' => $state->getOption('list_row_css'),
            'workflow_id' => $state->getWorkflow()->getId(),
            'actions' => array_keys($state->getActions()),
        );
        return $state_data;
    }

    protected function getFakeStateData($name, $workflow_id, $action_id)
    {
        return array(
            'id' => '#foreign_'.$workflow_id,
            'name' => $name,
            'list_row_css' => '',
            'workflow_id' => $workflow_id,
            'actions' => array($action_id),
            'foreign' => true,
        );
    }
}

