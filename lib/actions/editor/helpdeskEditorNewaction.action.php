<?php
/**
 * One of sub-editors of the workflow editor.
 *
 * Returns the form to add new action to given workflow and state,
 * and accepts POST submit from this form.
 */
class helpdeskEditorNewactionAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $workflow_id = waRequest::request('wid');
        $wf = helpdeskWorkflow::getWorkflow($workflow_id);

        $state_id = waRequest::request('state_id');
        $state = $wf->getStateById($state_id);

        $errors = array();
        $data = waRequest::post();
        if ($data) {
            $cfg = helpdeskWorkflow::getWorkflowsConfig();

            if ($data['new_or_existing'] == 'existing') {
                $unchecked_ids = ifempty($data['existing_action_ids'], array());
                if (!is_array($unchecked_ids)) {
                    $unchecked_ids = array($unchecked_ids);
                }

                $ids = array();
                foreach($unchecked_ids as $action_id) {
                    try {
                        $ids[] = $wf->getActionById(ifset($action_id))->getId();
                    } catch (Exception $e) {
                        continue;
                    }
                }
            } else {
                $classname = waRequest::request('new_classname');
                if (!class_exists($classname)) {
                    throw new waException('Unknown classname: '.$classname);
                }

                $action_name = waRequest::request('new_name');
                foreach(ifset($cfg['actions'], array()) as $a_id => $a_data) {
                    if ($wf->getActionById($a_id)->getName() == $action_name) {
                        $errors['new_name'] = _w('Action with this name already exists');
                        break;
                    }
                }

                if (!$errors) {
                    $action_id = strtolower(waLocale::transliterate($action_name));
                    $action_id = preg_replace('~[^a-z0-9]+~u', '_', preg_replace('~[`\'"]~', '', $action_id));
                    $action_id = trim($action_id, '_');
                    if (!$action_id) {
                        $action_id = 'a';
                    }
                    while (!empty($cfg['actions'][$action_id])) {
                        $action_id .= rand(0, 9);
                    }

                    $ids = array($action_id);
                    $cfg['actions'][$action_id] = array(
                        'classname' => $classname,
                        'options' => array(
                            'name' => $action_name,
                        ),
                    );
                }
            }

            if (!$errors) {
                if (!$ids) {
                    self::closeDialog();
                    exit;
                }

                foreach($ids as $action_id) {
                    if ($action_id != 'delete' && !isset($cfg['workflows'][$workflow_id]['actions'][$action_id])) {
                        $cfg['workflows'][$workflow_id]['actions'][$action_id] = array(
                            'options' => array(),
                        );
                    }
                    $cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions'][] = $action_id;
                    $cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions'] =
                        array_values(array_unique($cfg['workflows'][$workflow_id]['states'][$state_id]['available_actions']));
                }
                helpdeskWorkflow::saveWorkflowsConfig($cfg);

                if ($data['new_or_existing'] == 'existing') {
                    self::closeDialog();
                } else {
                    self::showNextDialog($wf->getId(), $state, $action_id);
                }
                exit;
            }
        }

        $actions = array();
        $cfg = helpdeskWorkflow::getWorkflowsConfig();
        foreach(array_diff_key(ifempty($cfg['workflows'][$workflow_id]['actions'], array()), $wf->getActions($state_id)) as $id => $a) {
            $actions[$id] = $wf->getActionById($id)->getName();
        }

        $this->view->assign('wf', $wf);
        $this->view->assign('data', $data);
        $this->view->assign('errors', $errors);
        $this->view->assign('actions', $actions);
        $this->view->assign('uniqid', uniqid('e'));
        $this->view->assign('state', $state);
    }

    protected static function showNextDialog($wf_id, $state, $action_id, $action_class='')
    {
        $state_id = $state->getId();
        echo '<script>(function() { "use strict";
            $.wa.dialogHide();
            $.wa.helpdesk_controller.redispatch();
            $.wa.helpdesk_controller.showActionSettings("'.$wf_id.'", "'.$state_id.'", "'.$action_id.'", "'.$action_class.'", "'._w('Save').'", "'._w('or').'", "'._w('cancel').'", "'._w('Delete this action').'", "'.sprintf_wp('The action will be eliminated only for the state &ldquo;%s&rdquo;. For other states this action will stay available.', $state->getName()).'");
        })();</script>';
        exit;
    }

    protected static function closeDialog()
    {
        echo '<script>(function() { "use strict";
            $.wa.dialogHide();
            $.wa.helpdesk_controller.redispatch();
        })();</script>';
        exit;
    }
}

