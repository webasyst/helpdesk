<?php
/**
 * helpdesk_request table
 *
 * Note that there is an ORM class: helpdeskRequest,
 * and a collection class: helpdeskRequestsCollection.
 * Most likely you should use those instead of a model.
 */
class helpdeskRequestModel extends waModel
{
    protected $table = 'helpdesk_request';
    protected $table_params = 'helpdesk_request_params';
    protected $table_log = 'helpdesk_request_log';
    protected $table_log_params = 'helpdesk_request_log_params';
    protected $table_unread = 'helpdesk_unread';

    /**
     * Delete request from database, including _request, _request_log, _request_log_params and _unread tables.
     * Does not by itself remove files associated with requests.
     * @param int $id request id
     */
    public function delete($id)
    {
        if (!$id) {
            return;
        }
        $sql = "DELETE r, rp, rl, rlp, u
                FROM {$this->table} AS r
                    LEFT JOIN {$this->table_params} AS rp
                        ON r.id = rp.request_id
                    LEFT JOIN {$this->table_log} AS rl
                        ON r.id = rl.request_id
                    LEFT JOIN {$this->table_log_params} AS rlp
                        ON rl.id=rlp.request_log_id
                    LEFT JOIN {$this->table_unread} AS u
                        ON r.id=u.request_id
                WHERE r.id IN (i:id)";
        $this->exec($sql, array('id' => $id));

        $rtm = new helpdeskRequestTagsModel();
        $rtm->delete($id);

        /**
         * @event requests_delete
         * Notifies plugins that one or more requests are deleted.
         * @param list of request_ids
         * @return void
         */
        $params = is_array($id) ? $id : array($id);
        wa('helpdesk')->event('requests_delete', $params);
    }

    /** Largest request_id in database */
    public function getLastId()
    {
        $sql = "SELECT id FROM {$this->table}
                ORDER BY id DESC
                LIMIT 0, 1";
        return $this->query($sql)->fetchField('id');
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

        return $group_names + $user_names;

    }

}

