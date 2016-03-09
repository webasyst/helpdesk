<?php

class helpdeskContactsMergeHandler extends waEventHandler
{
    public function execute(&$params)
    {
        $master_id = $params['id'];
        $merge_ids = $params['contacts'];

        $m = new waModel();

        foreach(array(
            array('helpdesk_request', 'client_contact_id'),
            array('helpdesk_request', 'creator_contact_id'),
            array('helpdesk_request_log', 'actor_contact_id'),

            // No need to do this since users are not merged into other contacts
            //array('helpdesk_request', 'assigned_contact_id'),
            //array('helpdesk_request_log', 'assigned_contact_id'),
        ) as $pair)
        {
            list($table, $field) = $pair;
            $sql = "UPDATE $table SET $field = :master WHERE $field in (:ids)";
            $m->exec($sql, array('master' => $master_id, 'ids' => $merge_ids));
        }

        return null;
    }
}

