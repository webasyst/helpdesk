<?php
/**
 * Single request page in customer portal.
 * Controller for my.requestpage.html in themes.
 */
class helpdeskFrontendMyRequestAction extends helpdeskFrontendViewAction
{
    public $request;
    public $request_info;
    public $request_logs;
    public $buttons;
    public $request_data;

    const MODEL_REQUEST_DATA = 'request_data';
    const MODEL_REQUEST_LOG_PARAMS = 'request_log_params';

    public function execute()
    {
        $id = waRequest::param('id');
        $this->request = new helpdeskRequest($id);

        if ($this->request->client_contact_id != wa()->getUser()->getId()) {
            throw new waRightsException(_w('You have no access rights to this request.'));
        }

        $this->request_info = $this->request->getInfo();

        $form_constructor = new helpdeskFormConstructor();
        $source = new helpdeskSource($this->request_info['source_id']);
        $contact_fields_order = array_keys($form_constructor->getContactFields($source));
        $request_fields_order = array_keys($form_constructor->getFields($source));

        $this->request_logs = $this->workupLogs(
            $this->request->getLogsClient(),
            $request_fields_order
        );

        $data = $this->request->getData(array(0, -1));
        $contact_data = $this->filterByType($data,
            helpdeskRequestDataModel::TYPE_CONTACT,
            $contact_fields_order
        );

        $request_data = $this->filterByFlag(
            $this->filterByType($data,
                helpdeskRequestDataModel::TYPE_REQUEST,
                $request_fields_order
            ),
            'my_visible'
        );

        $this->request_data = $contact_data + $request_data;

        //$original_data = $this->getOriginalData($this->request, $request_fields_order);

        // Buttons for available actions
        $this->buttons = array();
        try {
            foreach ($this->request->getWorkflow()->getActions($this->request->state_id) as $action) {
                if (!$action->getOption('client_visible') || !$action->getOption('client_triggerable')) {
                    continue;
                }
                if ( ( $html = $action->getButton())) {
                    $this->buttons[] = $html;
                }
            }
        } catch (Exception $e) {
        }

        // State name for client
        try {
            $state = $this->request->getState();
            $this->request_info['status'] = $state->getOption('customer_portal_name');
            $this->request_info['status_css'] = $state->getOption('list_row_css');
        } catch (Exception $e) {
            $this->request_info['status'] = '';
            $this->request_info['status_css'] = '';
        }

        $this->request_info['client_contact'] = $this->request->getClient();

        // Antispam confirmation redirects here. Show special info block in this case.
        $this->just_confirmed = !!wa()->getStorage()->get('helpdesk/antispam_confirmed/'.$id);
        if ($this->just_confirmed) {
            wa()->getStorage()->del('helpdesk/antispam_confirmed/'.$id);
        }

        $portal_actor_display = wa()->getSetting(
            'portal_actor_display',
            wa()->getSetting('name', 'Webasyst', 'webasyst'),
            'helpdesk'
        );

        $this->view->assign(array(
            'log' => $this->request_logs,
            'buttons' => $this->buttons,
            'request' => $this->request_info,
            'request_data' => $this->formatFields($this->request_data),
            'request_data_original' => array()/*$this->formatFields($original_data, true)*/,
            'just_confirmed' => $this->just_confirmed,
            'my_nav_selected' => 'requests',
            'form_url' => wa()->getRouteUrl(
                'helpdesk/frontend/actionForm',
                array(
                    'workflow_id' => $this->request->workflow_id,
                    'action_id' => '%ACTION_ID%',
                    'params' => $id
                )
            ),
            'portal_actor_display' => $portal_actor_display
        ));

        $this->setThemeTemplate('my.requestpage.html');
        $this->getResponse()->setTitle(_w('Request').' — '.$this->request->summary);
        parent::execute();
    }

    public function getBreadcrumbs()
    {
        $result = parent::getBreadcrumbs();
        $result[] = array(
            'name' => _w('My account'),
            'url' => wa()->getRouteUrl('helpdesk/frontend/myRequests'),
        );
        $result[] = array(
            'name' => _w('My requests'),
            'url' => wa()->getRouteUrl('helpdesk/frontend/myRequests'),
        );
        return $result;
    }

    public function filterByType($data, $type, $order = array(), $model = self::MODEL_REQUEST_DATA)
    {
        $res = array();
        foreach ($data as $field_id => $data_val) {
            if ($model === self::MODEL_REQUEST_DATA) {
                if (helpdeskRequestDataModel::getType($field_id) === $type) {
                    $f_id = helpdeskRequestDataModel::cutOffPrefix($field_id);
                    $res[$f_id] = array(
                        'field_id' => $field_id,
                        'val' => $data_val
                    );
                }
            } else if ($model === self::MODEL_REQUEST_LOG_PARAMS) {
                if (helpdeskRequestLogParamsModel::getType($field_id) === $type) {
                    $f_id = helpdeskRequestLogParamsModel::cutOffPrefix($field_id);
                    $res[$f_id] = array(
                        'field_id' => $field_id,
                        'val' => $data_val
                    );
                }
            }
        }
        $res_ordered = array();
        foreach ($order as $f_id) {
            if (isset($res[$f_id])) {
                $info = $res[$f_id];
                $res_ordered[$info['field_id']] = $info['val'];
                unset($res[$f_id]);
            }
        }
        foreach ($res as $f_id => $info) {
            $res_ordered[$info['field_id']] = $info['val'];
        }
        return $res_ordered;
    }

    public function filterByFlag($data, $flag, $model = self::MODEL_REQUEST_DATA)
    {
        $res = array();
        foreach ($data as $field_id => $data_val) {
            if ($model === self::MODEL_REQUEST_DATA) {
                $field = helpdeskRequestDataModel::getField($field_id);
            } else if ($model === self::MODEL_REQUEST_LOG_PARAMS) {
                $field = helpdeskRequestLogParamsModel::getField($field_id);
            }
            if ($field && $field->getParameter($flag)) {
                $res[$field_id] = $data_val;
            }
        }
        return $res;
    }

    public function workupLogs($logs, $order)
    {
        foreach ($logs as &$log) {
            $fields = $this->filterByFlag(
                $this->filterByType($log['params'],
                    helpdeskRequestLogParamsModel::TYPE_REQUEST,
                    $order,
                    self::MODEL_REQUEST_LOG_PARAMS
                ),
                'my_visible',
                self::MODEL_REQUEST_LOG_PARAMS
            );
            $log['fields'] = helpdeskRequestLogParamsModel::formatFields($fields);
        }
        unset($log);

        return $logs;
    }

    public function formatFields($data, $save_status = false)
    {
        $formatted = helpdeskRequestDataModel::formatFields($data);
        if ($save_status) {
            foreach ($data as $field_id => $item) {
                if (isset($formatted[$field_id]) && isset($item['status'])) {
                    $formatted[$field_id]['status'] = $item['status'];
                }
            }
        }
        return $formatted;
    }

    public function getOriginalData(helpdeskRequest $request, $order)
    {
        $data = $request->getData(array(0, -1));

        $changed = false;
        foreach ($data as $item) {
            if (isset($item['status']) && $item['status'] == -1) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $contact_data_original = $this->filterByType($data,
                helpdeskRequestDataModel::TYPE_CONTACT,
                $order
            );
            $request_data_original = $this->filterByFlag(
                $this->filterByType($data,
                    helpdeskRequestDataModel::TYPE_REQUEST,
                    $order
                ),
                'my_visible'
            );
            return $contact_data_original + $request_data_original;
        }

        return array();

    }

}

