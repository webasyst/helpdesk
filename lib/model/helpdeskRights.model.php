<?php
/**
 * helpdesk_rights table stores access control rights.
 */
class helpdeskRightsModel extends waModel
{
    /** @return boolean whether given action in given workflow is allowed to current user */
    public static function isAllowed($workflow_id, $action_id, $contact_id=null)
    {
        static $m = null;
        static $current_user_admin = null;
        static $current_user_contact_ids = null;

        if ($m === null) {
            $m = new self();
        }

        if ($contact_id === null || $contact_id == wa()->getUser()->getId()) {
            if ($current_user_admin === null) {
                $current_user_admin = wa()->getUser()->getRights('helpdesk', 'backend') > 1;
            }
            $admin = $current_user_admin;
            $contact_id = wa()->getUser()->getId();
        } else {
            $admin = wao(new waContact($contact_id))->getRights('helpdesk', 'backend') > 1;
        }
        if ($admin) {
            return true;
        }
        if(!$action_id && $action_id !== '0') {
            return false;
        }

        if ($action_id[0] != '!') {
            if (self::isAllowed($workflow_id, '!action.all', $contact_id)) {
                return true;
            }
        }

        if ($contact_id == wa()->getUser()->getId()) {
            if ($current_user_contact_ids === null) {
                $current_user_contact_ids = $m->getIdsByUser();
            }
            $contact_ids = $current_user_contact_ids;
        } else {
            $contact_ids = $m->getIdsByUser($contact_id);
        }

        return !!$m->getByField(array(
            'workflow_id' => $workflow_id,
            'contact_id' => $contact_ids,
            'action_id' => $action_id,
        ));
    }

    protected $table = 'helpdesk_rights';
    protected $instance = null;

    /**
     * For each workflow at least one of given contacts/groups has access to, resulting array contains a pair
     * wf_id => true if at least one of given contacts/groups can see all requests;
     *          false if only assigned requests are allowed.
     *
     * @param array $contact_ids list of contact ids (positive) or group ids (negative)
     * @return array wf_id => boolean
     */
    public function getWorkflowsRequestsRights()
    {
        return array_fill_keys(array_keys(helpdeskWorkflow::getWorkflows()), true);
    }

    /**
     * Rights to workflows
     * @param int|null $user_id
     * @param boolean $map
     * @return array If $map is true (wf_id1 => true,wf_id2 => true,..) else (wf_id1,wf_id2,..)
     */
    public function getWorkflowsRights($user_id = null, $map = true)
    {
        $user = $user_id === null ? wa()->getUser() : new waUser($user_id);
        $user_id = $user->getId();
        if ($user->getRights('helpdesk', 'backend') > 1) {
            $res = helpdeskWorkflow::getWorkflows();
        } else {
            $res = $this->getWorkflowStatesRights($user_id);
        }
        if ($map) {
            return array_fill_keys(array_keys($res), true);
        }
        return array_keys($res);
    }

    /**
     * @return array (wf_id => 1) for each workflow current user can create requests in
     */
    public function getWorkflowsCreationRights()
    {
        return $this->getWorkflowsSpecialRights('!create');
    }

    /**
     * @return array (wf_id => 1) for each workflow current user can create tag in
     */
    public function getWorkflowsCreateTagRights()
    {
        return $this->getWorkflowsSpecialRights('!create_tag');
    }

    /**
     *
     * @param int|null|array[int] $contact_id
     * @return array(contact_id => array(wf_id => 1)) for each contact and workflow this contact can be assigned to requests
     */
    public function getWorkflowsAssignedToRequestsRights($contact_id = null)
    {
        $rights = $this->getWorkflowsSpecialRights('!assigned_to_request', $contact_id);
        if (!empty($rights)) {
            if (is_numeric($contact_id)) {
                $rights = array(
                    $contact_id => $rights
                );
            }

            // plus default rights
            $crm = new waContactRightsModel();
            if ($contact_id === null) {
                $contact_id = wa()->getUser()->getId();
            }

            $contact_ids = array_map('intval', (array) $contact_id);
            $group_ids = array();
            foreach ($contact_ids as $c_id) {
                $group_ids[] = -$c_id;
            }
            $default_rights = $crm->select('*')->where("app_id = 'helpdesk' AND name LIKE 'wf.%' AND group_id IN(:group_id)", array(
                'group_id' => $group_ids
            ))->fetchAll('group_id');
            foreach ($default_rights as $dr) {
                $rn = $dr['name'];
                $name = explode('.', $rn);
                $wf_id = (int) $name[1];
                $rights[-$dr['group_id']][$wf_id] = 1;
            }
        }
        return $rights;
    }

    public function getWorkflowsSpecialRights($right_name, $user_id = null)
    {
        if ($user_id === null) {
            $user_id = wa()->getUser()->getId();
        }

        $user_ids = array_map('intval', (array) $user_id);

        $rights = array();
        $right_model = new waContactRightsModel();
        foreach ($user_ids as $u_id) {
            $data = $right_model->get($u_id, 'helpdesk');
            $is_admin = ifset($data['backend'], 0) > 1;
            if ($is_admin) {
                $rights[$u_id] = array_fill_keys(array_keys(helpdeskWorkflow::getWorkflows()), true);
            } else {
                $sql = "SELECT workflow_id, 1
                        FROM {$this->table}
                        WHERE contact_id IN (i:cids)
                            AND action_id=:right_name
                        GROUP BY workflow_id";
                $rights[$u_id] = $this->query($sql, array('cids' => $this->getIdsByUser($u_id), 'right_name' => $right_name))->fetchAll('workflow_id', true);
            }
        }

        return is_numeric($user_id) ? ifset($rights[$user_id], array()) : $rights;
    }


    /**
     * @param int contact_id defaults to current user id
     * @return array list of group_ids (negative) that contact belongs to, plus $contact_id itself (positive)
     */
    public function getIdsByUser($contact_id = null)
    {
        if (!$contact_id) {
            $contact_id = wa()->getUser()->getId();
        }
        $groups = array($contact_id);
        if ($contact_id > 0) {
            $ugm = new waUserGroupsModel();
            foreach($ugm->getGroupIds($groups[0]) as $gid) {
                $groups[] = -$gid;
            }
        }
        return $groups;
    }

    /**
     * @param array $contact_ids list of contact ids (positive) or group ids (negative)
     * @param int $workflow_id
     * @return array (action_id => boolean) for each action (inclusing non-workflow actions) allowed for at least one of given users/groups
     */
    public function getAllowedActions($contact_ids, $workflow_id)
    {
        $sql = "SELECT action_id, 1
                FROM {$this->table}
                WHERE contact_id IN (i:cids)
                    AND workflow_id=i:wid
                GROUP BY action_id";
        return $this->query($sql, array('cids' => $contact_ids, 'wid' => $workflow_id))->fetchAll('action_id', true);
    }

    public function getUserAndGroupsCanBeAssigned($workflow_id)
    {
        $crm = new waContactRightsModel();

        $ids = array_map('intval', $crm->getUsers('helpdesk'));

        if ($ids) {
            $cm = new waContactModel();
            $ids = array_map('intval',
                    $cm->select('id')->where("id IN(".implode(',', $ids).") AND is_user > 0")->
                        fetchAll(null, true));
        }

        $rights = $this->getWorkflowsAssignedToRequestsRights($ids);
        foreach ($ids as $k => $id) {
            if (empty($rights[$id][$workflow_id])) {
                unset($ids[$k]);
            }
        }

        $groups = array();
        foreach ($crm->getAllowedGroups('helpdesk', 'backend') as $group_id => $true) {
            $groups[] = -$group_id;
        }

        $rights = $this->getWorkflowsAssignedToRequestsRights($groups);
        foreach ($groups as $k => $id) {
            if (empty($rights[$id][$workflow_id])) {
                unset($groups[$k]);
            }
        }
        return array_unique(array_merge($ids, $groups));
    }


    /**
     * Get workflow states rights of user with limited access
     * @param int|array[int] $contact_ids
     * @return array[contact_id][workflow_id][state_id] => true or array[workflow_id][state_id] => true if $contact_ids is integer
     */
    public function getWorkflowStatesRights($contact_ids)
    {
        $ugm = new waUserGroupsModel();

        $all_rights = array();
        foreach (array_map('intval', (array) $contact_ids) as $contact_id) {
            $groups = $ugm->getGroupIds($contact_id);
            $all_contact_ids = array($contact_id);
            foreach ($groups as $gid) {
                $all_contact_ids[] = -$gid;
            }

            $rights = array();
            foreach ($this->select('contact_id, workflow_id, state_id')
                    ->where('state_id IS NOT NULL AND contact_id IN (i:0)', array($all_contact_ids))
                    ->fetchAll() as $right)
            {
                $rights[$right['contact_id']][$right['workflow_id']][$right['state_id']] = true;
            }

            $full_rights = array();
            foreach ($groups as $gid) {
                if (isset($rights[-$gid])) {
                    foreach ($rights[-$gid] as $wid => $states) {
                        $full_rights[$wid] = ifempty($full_rights[$wid], array());
                        $full_rights[$wid] = $states + $full_rights[$wid];
                    }
                }
            }

            if (isset($rights[$contact_id])) {
                foreach ($rights[$contact_id] as $wid => $states) {
                    $full_rights[$wid] = ifempty($full_rights[$wid], array());
                    $full_rights[$wid] = $states + $full_rights[$wid];
                }
            }

            $all_rights[$contact_id] = $full_rights;

        }

        if (is_numeric($contact_ids)) {
            return $all_rights[$contact_ids];
        } else {
            return $all_rights;
        }
    }

    /**
     * Get workflow actions rights of user with limited access
     * @param int|array[int] $contact_ids
     * @return array[contact_id][workflow_id][action_id] => true or array[workflow_id][action_id] => true if $contact_ids is integer
     */
    public function getWorkflowActionsRights($contact_ids)
    {
        $ugm = new waUserGroupsModel();

        $all_rights = array();
        foreach (array_map('intval', (array) $contact_ids) as $contact_id) {
            $groups = $ugm->getGroupIds($contact_id);
            $all_contact_ids = array($contact_id);
            foreach ($groups as $gid) {
                $all_contact_ids[] = -$gid;
            }

            $rights = array();
            foreach ($this->select('contact_id, workflow_id, action_id')
                    ->where('action_id IS NOT NULL AND contact_id IN (i:0)', array($all_contact_ids))
                    ->fetchAll() as $right)
            {
                $rights[$right['contact_id']][$right['workflow_id']][$right['action_id']] = true;
            }

            $full_rights = array();
            foreach ($groups as $gid) {
                if (isset($rights[-$gid])) {
                    foreach ($rights[-$gid] as $wid => $actions) {
                        $full_rights[$wid] = ifempty($full_rights[$wid], array());
                        $full_rights[$wid] = $actions + $full_rights[$wid];
                    }
                }
            }

            if (isset($rights[$contact_id])) {
                foreach ($rights[$contact_id] as $wid => $actions) {
                    $full_rights[$wid] = ifempty($full_rights[$wid], array());
                    $full_rights[$wid] = $actions + $full_rights[$wid];
                }
            }

            $all_rights[$contact_id] = $full_rights;

        }

        if (is_numeric($contact_ids)) {
            return $all_rights[$contact_ids];
        } else {
            return $all_rights;
        }
    }

}

