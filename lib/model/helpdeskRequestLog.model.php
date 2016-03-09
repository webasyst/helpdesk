<?php
/**
 * helpdesk_request_log table stores history of actions with requests.
 *
 * Note that there is an ORM class for single log record: helpdeskRequestLog.
 * For list of logs, see helpdeskRequest->getLogs().
 * Most likely you should not use this model.
 */
class helpdeskRequestLogModel extends waModel
{
    protected $id = 'id';
    protected $table = 'helpdesk_request_log';
    protected $params_table = 'helpdesk_request_log_params';

    /** Return log records for given request_id
      * as log_id => db row with additional key=>value pairs from helpdesk_request_log_params */
    public function getByRequestWithParams($request_id)
    {
        $sql = "SELECT * FROM {$this->table} WHERE request_id=:rid ORDER BY id";
        $result = $this->query($sql, array('rid' => $request_id))->fetchAll('id');
        foreach($result as &$row) {
            $row['params'] = array();
        }

        $rlp = new helpdeskRequestLogParamsModel();
        $params = $rlp->getByField('request_log_id', array_keys($result), true);
        foreach($params as $p) {
            $result[$p['request_log_id']]['params'][$p['name']] = $p['value'];
        }

        return $result;
    }

    public function getLastId()
    {
        $sql = "SELECT id FROM {$this->table}
                ORDER BY id DESC
                LIMIT 0, 1";
        return $this->query($sql)->fetchField('id');
    }

    public function getActorsByWorkflows($workflow_ids = array(), $contacts_filter = null)
    {
        $workflow_ids = array_map('intval', (array) $workflow_ids);
        if (!$workflow_ids) {
            return array();
        }

        $ext_where = '';
        if ($contacts_filter) {
            $contacts_filter = array_map('intval', (array) $contacts_filter);
            $ext_where = "AND actor_contact_id IN('".  implode("','", $contacts_filter)."')";
        }

        $actor_ids = $this->select('DISTINCT actor_contact_id')->
                                where("workflow_id IN (".  implode(',', $workflow_ids) . ") AND
                            actor_contact_id != 0 AND actor_contact_id IS NOT NULL {$ext_where}")->fetchAll(null, true);

        $cm = new waContactModel();
        $actor_names = $cm->getName($actor_ids);
        asort($actor_names);

        return $actor_names;

    }

    public function getAssignedByWorkflows($workflow_ids = array(), $contacts_filter = null)
    {
        $workflow_ids = array_map('intval', (array) $workflow_ids);
        if (!$workflow_ids) {
            return array();
        }
        $user_ids = array();
        $group_ids = array();

        $ext_where = '';
        if ($contacts_filter) {
            $contacts_filter = array_map('intval', (array) $contacts_filter);
            $ext_where = "AND assigned_contact_id IN('".  implode("','", $contacts_filter)."')";
        }

        foreach ($this->select('DISTINCT assigned_contact_id')->
                where("workflow_id IN (".  implode(',', $workflow_ids) . ") AND
                    assigned_contact_id != 0 AND assigned_contact_id IS NOT NULL {$ext_where}")->fetchAll(null, true)
                as $assigned_contact_id)
        {
            if ($assigned_contact_id > 0) {
                $user_ids[] = $assigned_contact_id;
            } else {
                $group_ids[] = -$assigned_contact_id;
            }
        }

        $cm = new waContactModel();
        $user_names = $cm->getName($user_ids);
        asort($user_names);

        // Group id => group name
        $gm = new waGroupModel();
        $group_names = $gm->getName($group_ids);
        asort($group_names);
        foreach($group_names as $gid => $gname) {
            $group_names[-$gid] = _w('Group:').' '.$gname;
            unset($group_names[$gid]);
        }

        $hrm = new helpdeskRequestModel();
        foreach ($hrm->getAssignedByWorkflows($workflow_ids, $contacts_filter) as $assigned_contact_id => $name) {
            if ($assigned_contact_id > 0) {
                $user_names[$assigned_contact_id] = $name;
            } else {
                $group_names[$assigned_contact_id] = $name;
            }
        }

        return $group_names + $user_names;
    }

}

