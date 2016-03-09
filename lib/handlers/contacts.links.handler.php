<?php

class helpdeskContactsLinksHandler extends waEventHandler
{
    public function execute(&$params)
    {
        $result = array();
        $contacts = $params;
        if (is_array($contacts)) {
            $cs = array();
            foreach ($contacts as $v) {
                if ( ( $v = (int)$v)) {
                    $cs[] = $v;
                }
            }
            $contacts = $cs;
        } else {
            if ( ( $contacts = (int)$contacts)) {
                $contacts = array($contacts);
            }
        }

        if (!$contacts) {
            return null;
        }

        $m = new waModel();

        waLocale::loadByDomain('helpdesk');

        foreach(array(
            array('helpdesk_request', 'client_contact_id', 'Client'),
            array('helpdesk_request', 'creator_contact_id', 'Request author'),
            array('helpdesk_request', 'assigned_contact_id', 'Assigned to request'),
            //array('helpdesk_request_log', 'actor_contact_id', 'Actions with request'), // takes too long: no index
            //array('helpdesk_request_log', 'assigned_contact_id', 'Assigned to request'), // takes too long: no index
        ) as $data) {
            list($table, $field, $role) = $data;
            $role = _wd('helpdesk', $role);
            $sql = "SELECT $field AS id, count(*) AS n
                    FROM $table
                    WHERE $field IN (".implode(',', $contacts).")
                    GROUP BY $field";
            foreach ($m->query($sql) as $row) {
                $result[$row['id']][] = array(
                    'role' => $role,
                    'links_number' => $row['n'],
                );
            }
        }
        return $result ? $result : null;
    }
}

