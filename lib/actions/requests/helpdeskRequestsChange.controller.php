<?php
/**
 * Bulk update for requests list.
 */
class helpdeskRequestsChangeController extends helpdeskJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $ids = array_flip(explode(',', (string) waRequest::post('ids')));
        $field = waRequest::post('field');
        $value = waRequest::post('value');
        $action_id_prefix = waRequest::post('action_prefix', '!bulk_change_', 'string');

        $wf = null;
        $new_workflow_id = null;
        if ($field == 'state_id' && strpos($value, '@') > 0) {
            list($new_workflow_id, $value) = explode('@', $value);
        } else if ($field == 'workflow_id') {
            $new_workflow_id = $value;
        }
        if ($new_workflow_id && !wa_is_int($new_workflow_id)) {
            $new_workflow_id = null;
        }
        if ($new_workflow_id) {
            try {
                $wf = helpdeskWorkflow::get($new_workflow_id);
            } catch (Exception $e) {
                $new_workflow_id = null;
            }
        }

        // Create request log entries
        $logs = array();
        $fake_state_id = uniqid('fake');
        foreach($ids as $id => $tmp) {
            if (!$id || !wa_is_int($id)) {
                unset($ids[$id]);
                continue;
            }
            $logs[] = array(
                'request_id' => $id,
                'datetime' => date('Y-m-d H:i:s'),
                'action_id' => $action_id_prefix.$field,
                'actor_contact_id' => wa()->getUser()->getId(),
                'text' => '',
                'to' => '',
                'assigned_contact_id' => $field == 'assigned_contact_id' ? $value : null,
                'before_state_id' => $fake_state_id,
                'after_state_id' => $field == 'state_id' ? $value : $fake_state_id,
                'workflow_id' => $new_workflow_id
            );
        }
        $ids = array_keys($ids);
        if (!$ids) {
            $this->response = _w('Updated %s request', 'Updated %s requests', 0);
            return;
        }

        $rlm = new helpdeskRequestLogModel();
        $rlm->multipleInsert($logs);
        $rlm->exec(
            "UPDATE helpdesk_request_log AS rl
                JOIN helpdesk_request AS r
                    ON r.id=rl.request_id
            SET r.last_log_id=rl.id,
                ".($field == 'state_id' ? '' : "rl.after_state_id=r.state_id,")."
                rl.before_state_id=r.state_id
            WHERE rl.request_id IN (i:ids)
                AND rl.before_state_id=:fake_state_id",

            array(
                'ids' => $ids,
                'fake_state_id' => $fake_state_id,
            )
        );
        
        // Update helpdesk_request
        $rm = new helpdeskRequestModel();
        
        if ($new_workflow_id) {
            foreach ($ids as $id) {
                $request = $rm->getById($id);
                if ($request['workflow_id'] != $new_workflow_id) {
                    $rlm->exec(
                        "INSERT IGNORE INTO helpdesk_request_log_params (request_log_id, name, value)
                         SELECT r.last_log_id, 'old_workflow_id', r.workflow_id
                         FROM helpdesk_request AS r
                         WHERE r.id IN (:ids)",
                         array(
                             'ids' => $ids
                         )
                    );
                    $rlm->exec(
                        "INSERT IGNORE INTO helpdesk_request_log_params (request_log_id, name, value)
                         SELECT r.last_log_id, 'new_workflow_id', :wid
                         FROM helpdesk_request AS r
                         WHERE r.id IN (:ids)",
                         array(
                            'ids' => $ids,
                            'wid' => $new_workflow_id,
                         )
                    );
                }
            }
        }

        // save old request subject for history
        if ($field == 'summary') {
            $request = $rm->getById(reset($ids));
            if ($request['summary'] !== $value) {
                foreach (array(
                                array('old_summary', $request['summary']),
                                array('new_summary', $value)) as $item)
                {
                    $rlm->exec(
                        "INSERT IGNORE INTO helpdesk_request_log_params (request_log_id, name, value)
                     SELECT r.last_log_id, s:name, s:value
                     FROM helpdesk_request AS r
                     WHERE r.id IN (:ids)",
                        array(
                            'ids' => $ids,
                            'name' => $item[0],
                            'value' => $item[1],
                        )
                    );
                }
            }
        }

        $upd = array(
            $field => $value,
        );
        if ($new_workflow_id) {
            $upd['workflow_id'] = $new_workflow_id;
        }
        $upd['updated'] = date('Y-m-d H:i:s');
        $rm->updateById($ids, $upd);

        // Human readable success message
        if ($field == 'assigned_contact_id') {
            $str = waLocale::$adapter->ngettext('%1$s request changed assignment to &laquo;%2$s&raquo;', '%1$s requests changed assignment to &laquo;%2$s&raquo;', count($ids));
            if ($value > 0) {
                try {
                    $c = new waContact($value);
                    $this->response = sprintf($str, count($ids), htmlspecialchars($c->getName()));
                } catch(Exception $e) {
                }
            } else if ($value < 0) {
                $gm = new waGroupModel();
                $group = $gm->getById(-$value);
                if ($group && strlen($group['name'])) {
                    $this->response = sprintf($str, count($ids), htmlspecialchars($group['name']));
                }
            } else {
                $this->response = _w('%s request cleared assignment', '%s requests cleared assignment', count($ids));
            }
        } else if ($field == 'state_id') {
            if (!$wf) {
                $wfs = helpdeskWorkflow::getWorkflows();
                $wf = reset($wfs);
            }
            try {
                $state_name = $wf->getStateById($value)->getName();
                $str = waLocale::$adapter->ngettext('%1$s request changed state to &laquo;%2$s&raquo;', '%1$s requests changed state to &laquo;%2$s&raquo;', count($ids));
                $this->response = sprintf($str, count($ids), htmlspecialchars($state_name));
            } catch (Exception $e) {
            }
        }

        wao(new helpdeskUnreadModel())->markUnreadBulk($ids, $field == 'assigned_contact_id' ? $value : '');

        if (empty($this->response)) {
            $this->response = _w('Updated %s request', 'Updated %s requests', count($ids));
        }
    }
    
}

