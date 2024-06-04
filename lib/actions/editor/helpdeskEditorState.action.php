<?php
/**
 * One of sub-editors of the workflow editor.
 *
 * Shows the form to add or edit a workflow state,
 * and accepts POST submit from this form.
 */
class helpdeskEditorStateAction extends helpdeskViewAction
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $state_id = waRequest::request('id');
        $workflow_id = waRequest::request('wid');
        $wf = helpdeskWorkflow::getWorkflow($workflow_id);

        $errors = array();
        $data = waRequest::post();
        if ($data) {
            $state_name = trim(waRequest::post('name'));
            if (!$state_name) {
                $errors['name'] = _ws('This field is required.');
            } else {
                foreach($wf->getAllStates() as $id => $s) {
                    if ($s->getName() == $state_name && (string)$id !== $state_id) {
                        $errors['name'] = _w('State with this name already exists');
                        break;
                    }
                }
            }

            $state_id = trim($state_id);
            if (!strlen($state_id)) {
                $errors['id'] = _ws('This field is required.');
            } else {
                $state_id = strtolower(waLocale::transliterate($state_id));
                $state_id = preg_replace('~[^a-z0-9]+~u', '_', preg_replace('~[`\'"]~', '', $state_id));
                $state_id = trim($state_id, '_');
                $data['id'] = $state_id;
                if (empty($state_id)) {
                    $errors['id'] = _w('This field only allows Latin letters, numbers and underscores.');
                }
            }

            if (strlen($state_id) && !empty($data['is_new'])) {
                foreach($wf->getAllStates() as $id => $s) {
                    if ((string)$id === $state_id) {
                        $errors['id'] = _w('State with this ID already exists');
                        break;
                    }
                }
            }

            if (!$errors) {

                $cfg = helpdeskWorkflow::getWorkflowsConfig();

                $state_cfg = array(
                    'classname' => 'helpdeskWorkflowState',
                    'available_actions' => array(),
                    'options' => array(),
                );
                if (isset($cfg['states'][$state_id])) {
                    $state_cfg = $cfg['states'][$state_id] + $state_cfg;
                    $state_cfg['options'] = ifset($cfg['states'][$state_id]['options'], array()) + $state_cfg['options'];
                }
                if (isset($cfg['workflows'][$workflow_id]['states'][$state_id])) {
                    $state_cfg = $cfg['workflows'][$workflow_id]['states'][$state_id] + $state_cfg;
                    $state_cfg['options'] = ifset($cfg['workflows'][$workflow_id]['states'][$state_id]['options'], array()) + $state_cfg['options'];
                }

                $state_cfg['options'] = array(
                    'name' => $state_name,
                    'list_row_css' => ifset($data['style'], ''),
                    'closed_state' => !!ifset($data['closed'], false),
                    'customer_portal_name' => ifset($data['customer_portal_name'], ''),
                ) + $state_cfg['options'];

                $available_actions = waRequest::post('available_actions');
                if ($available_actions) {
                    $state_cfg['available_actions'] = explode(',', $available_actions);
                }

                $cfg['workflows'][$workflow_id]['states'][$state_id] = $state_cfg;
                helpdeskWorkflow::saveWorkflowsConfig($cfg);

                echo (helpdeskHelper::isLegacyUi()
                    ? '<script>(function() { "use strict"; $("#c-core-content .tab-content:first").html(\'<div class="triple-padded block"><i class="icon16 loading"></i></div>\'); $.wa.helpdesk_controller.redispatch(); $.wa.dialogHide(); })();</script>'
                    : 'ok'
                );
                exit;
            }
        } else {
            $data = array(
                'id' => '',
                'name' => '',
                'closed' => false,
                'style' => 'color:#000000',
                'customer_portal_name' => '',
            );

            if (strlen($state_id)) {
                $s = $wf->getStateById($state_id);
                $data['id'] = $state_id;
                $data['name'] = $s->getOption('name');
                $data['style'] = $s->getOption('list_row_css');
                $data['closed'] = $s->getOption('closed_state');
                $data['customer_portal_name'] = $s->getOption('customer_portal_name');
            }
        }

        $pre_selected_color = null;
        if (!empty($data['style']) && preg_match('~(^|[^-])color:([^;]+)~', $data['style'], $m)) {
            $pre_selected_color = $m[2];
        }

        // Number of requests in this state
        $rm = new helpdeskRequestModel();
        $state_requests_num = $rm->countByField(array(
            'workflow_id' => $workflow_id,
            'state_id' => $state_id,
        ));

        $this->view->assign('wf', $wf);
        $this->view->assign('data', $data);
        $this->view->assign('errors', $errors);
        $this->view->assign('uniqid', uniqid('e'));
        $this->view->assign('pre_selected_color', $pre_selected_color);
        $this->view->assign('state_requests_num', $state_requests_num);
        $this->view->assign('available_actions', $wf->getActions($state_id));
    }
}
