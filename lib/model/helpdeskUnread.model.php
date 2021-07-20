<?php
/**
 * helpdesk_unread table stores pairs (request_id, contact_id)
 * representing unread requests for each backend user.
 */
class helpdeskUnreadModel extends waModel
{
    protected $table = 'helpdesk_unread';

    public function __construct($type = null, $writable = false)
    {
        parent::__construct($type, $writable);
        if (wa()->getUser()->get('is_user')) {
            $this->updateUnread();
        }
    }

    /**
     * This marks new requests as unread for given user.
     * We do this in constructor and not in markUnread()
     * so that having inactive users in the system does not fill helpdesk_unread table
     * with too much garbage.
     */
    public function updateUnread($contact_id = null)
    {
        $contact_id || $contact_id = wa()->getUser()->getId();

        $csm = new waContactSettingsModel();
        $settings = $csm->get($contact_id, 'helpdesk');
        $last_unread_request_id = ifset($settings['last_unread_request_id']);
        $max_request_id = $this->query("SELECT MAX(id) FROM helpdesk_request")->fetchField();

        if ($last_unread_request_id && !empty($settings['count_all_new'])) {
            if ($last_unread_request_id < $max_request_id) {
                $sql = "INSERT IGNORE INTO {$this->table} (contact_id, request_id)
                        SELECT i:contact_id, id
                        FROM helpdesk_request
                        WHERE id > i:request_id
                            AND id <= i:max_request_id";
                $this->exec($sql, array(
                    'contact_id' => $contact_id,
                    'request_id' => $last_unread_request_id,
                    'max_request_id' => $max_request_id,
                ));
            }
        } else if (!$last_unread_request_id) {
            // This seems to be the first time user ever entered helpdesk app.
            // Set default settings for him.
            if (!isset($settings['count_assigned'])) {
                $csm->set($contact_id, 'helpdesk', 'count_assigned', 1);
                $csm->set($contact_id, 'helpdesk', 'count_assigned_logs', 1);
            }
        }

        $csm->set($contact_id, 'helpdesk', 'last_unread_request_id', ifempty($max_request_id, -1));
    }

    /**
     * When a new request is created, or an existing one is updated,
     * this marks the request as unread for appropriate users.
     * @param helpdeskRequest
     */
    public function markUnread(helpdeskRequest $r, helpdeskRequestLog $l = null)
    {
        $this->markUnreadBulk(array($r->id), $l ? $l->assigned_contact_id : $r->assigned_contact_id, true);
    }

    protected function getAllUsersSettings()
    {
        $settings = array(); // setting => list of contact_ids
        foreach(wao(new waContactSettingsModel())->getByField(array('app_id' => 'helpdesk'), true) as $row) {
            isset($settings[$row['name']]) || $settings[$row['name']] = array();
            $settings[$row['name']][$row['contact_id']] = (boolean) $row['value'];
        }
        return $settings;
    }

    /** Mark requests as unread for appropriate users. Used for bulk operations. */
    public function markUnreadBulk($request_ids, $assigned_id, $check_rights = false)
    {
        if (!$request_ids) {
            return;
        };

        $ugm = new waUserGroupsModel();

        // Prepare $unfiltered_pairs:
        $unfiltered_pairs = array(); // (request_id, +contact_id or -group_id)
        if ($assigned_id) {
            $assignment_changed = true;
            foreach($request_ids as $rid) {
                $unfiltered_pairs[$assigned_id.'/'.$rid] = array($assigned_id, $rid);
            }
        } else if (!strlen($assigned_id)) {
            $assignment_changed = false;
            $sql = "SELECT id, assigned_contact_id FROM helpdesk_request WHERE id IN (i:ids)";
            $previously_assigned = $this->query($sql, array('ids' => $request_ids))->fetchAll('id', true);
            foreach($previously_assigned as $rid => $assigned_id) {
                $unfiltered_pairs[$assigned_id.'/'.$rid] = array($assigned_id, $rid);
            }
        }
        if (!$unfiltered_pairs) {
            return;
        }

        // Expand group_ids in $unfiltered_pairs
        $expanded_pairs = array(); // (request_id, +contact_id, true if personal assignment or false for group)
        foreach($unfiltered_pairs as $i => $p) {
            list($assigned_id, $rid) = $p;
            if ($assigned_id < 0) {
                foreach($ugm->getContactIds(-$assigned_id) as $cid) {
                    if (empty($expanded_pairs[$cid.'/'.$rid][2])) {
                        $expanded_pairs[$cid.'/'.$rid] = array($cid, $rid, false);
                    }
                }
            } else if ($assigned_id > 0) {
                $expanded_pairs[$i] = array($assigned_id, $rid, true);
            }
        }
        unset($unfiltered_pairs);
        if (!$expanded_pairs) {
            return;
        }

        if ($check_rights) {
            $expanded_pairs = $this->filterByRights($expanded_pairs);
        }

        // Only keep contacts that opted for notifications
        $rows = array();
        $settings = $this->getAllUsersSettings();
        foreach($expanded_pairs as $p) {
            list($cid, $rid, $personal) = $p;
            if ($assignment_changed) {
                if ($personal && !empty($settings['count_assigned'][$cid])) {
                    $rows[] = "({$cid}, {$rid})";
                } else if (!$personal && !empty($settings['count_assigned_group'][$cid])) {
                    $rows[] = "({$cid}, {$rid})";
                }
            } else {
                if ($personal && !empty($settings['count_assigned_logs'][$cid])) {
                    $rows[] = "({$cid}, {$rid})";
                } else if (!$personal && !empty($settings['count_assigned_group_logs'][$cid])) {
                    $rows[] = "({$cid}, {$rid})";
                }
            }
        }
        unset($expanded_pairs);
        if (!$rows) {
            return;
        }

        return $this->exec("INSERT IGNORE INTO {$this->table} (contact_id, request_id) VALUES ".join(', ', $rows));
    }

    public function filterByRights($expanded_pairs)
    {
        $request_ids = array();
        $contact_ids = array();
        foreach ($expanded_pairs as $p) {
            list($cid, $rid, $personal) = $p;
            $contact_ids[] = $cid;
            $request_ids[] = $rid;
        }
        $contact_ids = array_unique($contact_ids);
        $request_ids = array_unique($request_ids);

        $ugm = new waUserGroupsModel();
        foreach ($ugm->getGroupIds($contact_ids) as $gid) {
            $contact_ids[] = -$gid;
        }
        $contact_ids = array_unique($contact_ids);

        $rm = new helpdeskRequestModel();
        $request_states = $rm->select('id, state_id, workflow_id')->where('id IN(i:ids)', array('ids' => $request_ids))->fetchAll('id', true);
        $rights = $this->getRights($contact_ids);

        foreach($expanded_pairs as $k => $p) {
            list($cid, $rid, $personal) = $p;

            $contact = new waContact($cid);
            $helpdesk_backend_rights = $contact->getRights('helpdesk', 'backend');
            if (!$helpdesk_backend_rights) {
                unset($expanded_pairs[$k]);
            } else if ($helpdesk_backend_rights <= 1) {
                $workflow_id = $request_states[$rid]['workflow_id'];
                $state_id = $request_states[$rid]['state_id'];
                $group_ids = $ugm->getGroupIds($cid);

                $full_rights = array();
                foreach ($group_ids as $group_id) {
                    if (isset($rights[-$group_id])) {
                        $full_rights = $rights[-$group_id] + $full_rights;
                    }
                }
                if (isset($rights[$cid])) {
                    $full_rights = $rights[$cid] + $full_rights;
                }

                if (!isset($full_rights[$workflow_id]) ||
                        (empty($full_rights[$workflow_id]['!state.all']) && empty($full_rights[$workflow_id][$state_id])))
                {
                    unset($expanded_pairs[$k]);
                }
            }
        }

        return $expanded_pairs;
    }

    /**
     *
     * @param array of int $contact_ids
     * @return array [contact_id][workflow_id][state_id] => true
     */
    public function getRights($contact_ids)
    {
        $rm = new helpdeskRightsModel();
        $rights = array();
        $items = $rm->select('contact_id, workflow_id, state_id')
                ->where('state_id IS NOT NULL AND contact_id IN(i:0)', array(
                    $contact_ids
                ))->fetchAll();
        foreach ($items as $item){
            $cid = $item['contact_id'];
            $rights[$cid][$item['workflow_id']][$item['state_id']] = true;
        }

        return $rights;
    }

    public function read($request_id, $contact_id = null)
    {
        $this->deleteByField(array(
            'request_id' => $request_id,
            'contact_id' => ifempty($contact_id, wa()->getUser()->getId()),
        ));
    }

    public function readAll($contact_id = null)
    {
        $contact_id = (array) $contact_id;
        $this->deleteByField(array(
            'contact_id' => ifempty($contact_id, wa()->getUser()->getId()),
        ));
    }

    public function countByContact($contact_id = null)
    {
        $contact_id = ifempty($contact_id, wa()->getUser()->getId());

//        $sql = "SELECT COUNT(*)
//                FROM {$this->table} AS u
//                    JOIN helpdesk_request AS r
//                        ON r.id=u.request_id
//                WHERE contact_id=?";
//        $result = $this->query($sql, $contact_id)->fetchField();

        $filters = array(
            array('name' => 'unread', 'op' => null, 'params' => array())
        );
        $c = helpdeskRequestsCollection::create($filters);
        $result = $c->count();

        if ($contact_id == wa()->getUser()->getId()) {
            wa('helpdesk')->getConfig()->setCount($result);
        }
        return $result;
    }

    public function isUnread($request_id, $contact_id = null)
    {
        return !!$this->getByField(array(
            'request_id' => $request_id,
            'contact_id' => ifempty($contact_id, wa()->getUser()->getId()),
        ));
    }

    public function isEnabled($contact_id = null)
    {
        $settings = wao(new waContactSettingsModel())->get(ifempty($contact_id, wa()->getUser()->getId()), 'helpdesk');
        foreach ($settings as $k => $v) {
            if ($v && substr($k, 0, 6) == 'count_') {
                return true;
            }
        }
        return false;
    }

    public function markUnreadForContact($request_ids, $contact_id = null)
    {
        is_array($request_ids) || $request_ids = array($request_ids);
        $contact_id || $contact_id = wa()->getUser()->getId();

        $rows = array();
        foreach($request_ids as $rid) {
            if (wa_is_int($rid)) {
                $rows[] = "({$contact_id}, {$rid})";
            }
        }

        return $this->exec("INSERT IGNORE INTO {$this->table} (contact_id, request_id) VALUES ".join(', ', $rows));
    }

    public function formatDatetime($datetime, $timezone, $loc, $format = 'humandate')
    {
        $formatted = '';
        if ($format === 'humandate') {
            if (date('Y-m-d', strtotime($datetime)) === date('Y-m-d')) {
                $formatted .= _ws('Today') . ', ';
            } else if (date('Y-m-d', strtotime($datetime)) === date('Y-m-d', strtotime('+1 days'))) {
                $formatted .= _ws('Tomorrow') . ', ';
            }
            $formatted .= waDateTime::date(waDateTime::getFormat($format, $loc), strtotime($datetime), $timezone, $loc);
        } else {
            $formatted .= waDateTime::date(waDateTime::getFormat($format, $loc), $datetime, $timezone, $loc);
        }
        return $formatted;
    }
}

