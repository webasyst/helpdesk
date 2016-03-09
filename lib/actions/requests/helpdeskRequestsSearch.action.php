<?php
/**
 * Advanced search form HTML.
 */
class helpdeskRequestsSearchAction extends helpdeskViewAction
{
    public function execute()
    {
        // min and max year to show in form
        $m = new waModel();
        $sql = "SELECT YEAR(MIN(created)) FROM helpdesk_request";
        $min_year = $m->query($sql)->fetchField();
        $this->view->assign('min_year', $min_year);
        $this->view->assign('max_year', date('Y') + 1);

        $rm = new helpdeskRightsModel();

        // allowed workflows
        $allowed = $rm->getWorkflowsRights();

        // sources
        $sm = new helpdeskSourceModel();
        $sources = $sm->getAllWithWorkflowNotDeleted();
        if (!wa()->getUser()->isAdmin()) {

            $allowed_workflows = array_keys($allowed);
            $allowed_sources = array();
            $spm = new helpdeskSourceParamsModel();
            if ($allowed_workflows) {
                $sql = "SELECT DISTINCT source_id FROM {$spm->getTableName()} WHERE name='workflow' AND value IN(i:ids)";
                $allowed_sources = $spm->query($sql, array('ids' => $allowed_workflows))->fetchAll('source_id');
            }

            $s = array();
            if ($allowed_sources) {
                foreach ($allowed_sources as $sid=>$val) {
                    if (!empty($sources[$sid])) {
                        $s[$sid] = $sources[$sid];
                    }
                }
            }
            $sources = $s;
        }
        $src = array();
        $workflows = array();
        foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
            $workflows[$wf_id] = $wf->getName();
        }
        asort($workflows);
        foreach ($workflows as $wf_id => $wf_name) {
            foreach ($sources as $s_id=>$s) {
                if (!empty($s['workflow_id']) && $s['workflow_id'] == $wf_id) {
                    $src[$wf_name][$s_id] = $s;
                }
            }
        }
        foreach ($sources as $s_id=>$s) {
            if (empty($s['workflow_id'])) {
                $src[_w('Others')][$s_id] = $s;
            }
        }

        $this->view->assign('sources', $src);

        $this->assignAssigned($allowed);
        $this->assignActors($allowed);
        $this->assignActionIds($allowed);
        $this->assignWorkflowsAndStates($allowed);
        $this->assignTags($allowed);

        $hash = waRequest::request('filters_hash');
        $this->view->assign('filters', $hash ? helpdeskRequestsCollection::parseSearchHash($hash, true) : array());

        $rdm = new helpdeskRequestDataModel();
        $fields = helpdeskRequestField::getFields();

        $select_fields_options = array();
        foreach ($fields as $field_id => $field) {
            if ($field->getType() === "Select") {
                $all_values = $rdm->select('DISTINCT value AS value, value AS name')->where('field = :0', array($field_id))->fetchAll('value', true);
                $options = $field->getOptions();
                foreach ($all_values as $val => $name) {
                    if (!isset($options[$val])) {
                        $options[$val] = $name;
                    }
                }
                $select_fields_options[$field_id] = $options;
            }
        }
        $this->view->assign('select_fields_options', $select_fields_options);
        $this->view->assign('fields', helpdeskRequestField::getFields());


    }

    /** 'Assigned to' contacts */
    protected function assignAssigned($allowed)
    {
        $assigned_to = helpdeskHelper::getAssignOptions();
        if (!wa()->getUser()->isAdmin()) {
            $m = new helpdeskRequestLogModel();
            $assigned_to = $m->getAssignedByWorkflows(array_keys($allowed), array_keys($assigned_to));
        }
        $assigned_to[wa()->getUser()->getId()] = wa()->getUser()->getName();
        $this->view->assign('assigned_to', $assigned_to);
    }

    protected function assignActors($allowed)
    {
        $actors = helpdeskHelper::getAssignOptions();
        if (!wa()->getUser()->isAdmin()) {
            $m = new helpdeskRequestLogModel();
            $actors = $m->getActorsByWorkflows(array_keys($allowed), array_keys($actors));
        }
        $actors[wa()->getUser()->getId()] = wa()->getUser()->getName();
        $this->view->assign('actors', $actors);
    }

    protected function assignTags($allowed)
    {
        $tag_model = new helpdeskTagModel();
        if (!wa()->getUser()->isAdmin()) {
            $this->view->assign('tags', $tag_model->getTagsByWorkflows(array_keys($allowed)));
        } else {
            $this->view->assign('tags', $tag_model->select('*')->where('count > 0')->order('name')->fetchAll());
        }
    }

    /** All ection ids */
    protected function assignActionIds($allowed)
    {
        $workflows = array();
        foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
            $workflows[$wf_id] = $wf->getName();
        }
        asort($workflows);

        $actions = array();
        foreach ($workflows as $wf_id => $wf_name) {
            if (!isset($allowed[$wf_id])) {
                continue;
            }
            $wf = helpdeskWorkflow::getWorkflow($wf_id);
            foreach ($wf->getAvailableActions() as $action_id => $action) {
                if (empty($actions[$wf_name][$action_id])) {
                    $actions[$wf_id][$action_id] = array(
                        'name' => $action['options']['name'],
                        'id' => $action_id,
                        'deleted' => false,
                    );
                }
            }
            if (!empty($actions[$wf_id])) {
                asort($actions[$wf_id]);
            }
        }

        // filter by rights
        $rm = new helpdeskRightsModel();
        $helpdesk_backend_rights = wa()->getUser()->getRights('helpdesk', 'backend');
        if (!$helpdesk_backend_rights) {

            return false;

        } else if ($helpdesk_backend_rights <= 1) {
            $limited_rights = $rm->getWorkflowActionsRights(wa()->getUser()->getId());

            foreach ($actions as $w_id => $workflow_actions) {
                if (!isset($limited_rights[$w_id])) {
                    unset($actions[$w_id]);
                } else if (empty($limited_rights[$w_id]['!action.all'])) {
                    foreach ($workflow_actions as $action_id => $action_name) {
                        if (empty($limited_rights[$w_id][$action_id])) {
                            unset($actions[$w_id][$action_id]);
                        }
                    }
                }
            }

            foreach ($workflows as $wf_id => $wf_name) {
                if (empty($actions[$wf_id])) {
                    unset($workflows[$wf_id]);
                }
            }

        }

        foreach ($actions as $wf_id => $_actions) {
            if (!empty($_actions)) {
                $actions[$workflows[$wf_id]] = $_actions;
            }
            unset($actions[$wf_id]);
        }

        if ($helpdesk_backend_rights > 1) {

            $deleted_actions = array();
            $rows = wao(new waModel())->query("SELECT DISTINCT action_id FROM helpdesk_request_log ORDER BY action_id");
            foreach ($rows as $row) {
                $action = null;
                $action_id = $row['action_id'];
                $action_name = $action_id;
                if ($wf) {
                    try {
                        $action = $wf->getActionById($action_id);
                        $action_name = $action->getName();
                    } catch (Exception $e) {
                    }
                }
                $deleted_actions[$action_id] = array(
                    'name' => $action_name,
                    'id' => $action_id,
                    'deleted' => true,
                );
            }
            if ($deleted_actions) {
                asort($deleted_actions);
                $actions = $actions + array(_w('Others') => $deleted_actions);
            }
        }

        $this->view->assign('all_actions', $actions);
    }

    /** Workflows and states */
    protected function assignWorkflowsAndStates($allowed)
    {
        $wfs = array();
        $states = array();

        $workflows = array();
        foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
            $workflows[$wf_id] = $wf->getName();
        }
        asort($workflows);

        if ($allowed) {
            foreach(helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
                if (!isset($allowed[$wf->getId()])) {
                    continue;
                }
                $wfs[$wf->getId()] = $wf->getName();
                $available_states = $wf->getAvailableStates();
                if ($available_states) {
                    $states[$wf->getId()] = array();
                    foreach ($available_states as $id => $info) {
                        $states[$wf->getId()][$id] = $info['options']['name'];
                    }
                }
            }
        }

        // filter by rights
        $rm = new helpdeskRightsModel();
        $helpdesk_backend_rights = wa()->getUser()->getRights('helpdesk', 'backend');
        if (!$helpdesk_backend_rights) {

            return false;

        } else if ($helpdesk_backend_rights <= 1) {
            $limited_rights = $rm->getWorkflowStatesRights(wa()->getUser()->getId());

            foreach ($states as $w_id => $workflow_states) {
                if (!isset($limited_rights[$w_id])) {
                    unset($states[$w_id]);
                } else if (empty($limited_rights[$w_id]['!state.all'])) {
                    foreach ($workflow_states as $state_id => $state_name) {
                        if (empty($limited_rights[$w_id][$state_id])) {
                            unset($states[$w_id][$state_id]);
                        }
                    }
                }
            }

            foreach ($wfs as $wf_id => $wf) {
                if (empty($states[$wf_id])) {
                    unset($wfs[$wf_id]);
                }
            }

            foreach ($states as $wf_id => $_states) {
                if (empty($_states)) {
                    unset($states[$wf_id]);
                }
            }
        }

        $all_states = array();
        if ($helpdesk_backend_rights > 1) {
            $all_states = helpdeskHelper::getAllStates();
            foreach($all_states as &$s) {
                if (!empty($s['deleted'])) {
                    $s['name'] .= ' '._w('(deleted)');
                }
            }
            unset($s);
        }

        $this->view->assign('all_states', array_values($all_states));
        $this->view->assign('workflows', $wfs);
        $this->view->assign('states', $states);
    }
}

