<?php

class helpdeskFollowModel extends waModel
{
    protected $table = 'helpdesk_follow';
    
    public function add($ids)
    {
        $contact_id = wa()->getUser()->getId();
        
        $sql = "INSERT IGNORE `{$this->table}`(contact_id, request_id) VALUES ";
        $insert = array();
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id) {
                $insert[]= "({$contact_id}, {$id})";
            }
        }
        if ($insert) {
            $sql .= implode(',', $insert);
            $this->exec($sql);
        }
    }
    
    public function countByContact($contact_id = null)
    {
        $contact_id = ifempty($contact_id, wa()->getUser()->getId());
        $sql = "SELECT COUNT(*)
                FROM `{$this->table}` AS a
                    JOIN helpdesk_request AS r
                        ON r.id=a.request_id
                WHERE contact_id=?";
        $result = $this->query($sql, (int) $contact_id)->fetchField();
        return $result;
    }
    
    public function makeUnread($request_id)
    {
        $ignore_contacts = array(wa()->getUser()->getId());
        $sql = "INSERT IGNORE `helpdesk_unread` (contact_id, request_id)
        SELECT contact_id, request_id FROM `helpdesk_follow` 
        WHERE request_id = i:request_id";
        if ($ignore_contacts) {
            $sql .= " AND contact_id NOT IN (i:contact_id)";
        }
        $this->exec($sql,  array('request_id' => $request_id, 'contact_id' => $ignore_contacts));
    }
    
    public function getFollowingContacts($request_id)
    {
        $ignore_contacts = array(wa()->getUser()->getId());
        $ignore_contacts = array();
        $sql = "SELECT contact_id AS id 
            FROM `{$this->table}`
            WHERE request_id = i:request_id " . 
                ($ignore_contacts ? " AND contact_id NOT IN (i:contact_id)" : '');
        return $this->query($sql, array(
            'request_id' => $request_id, 
            'contact_id' => $ignore_contacts
        ))->fetchAll(null, true);
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
            $formatted .= waDateTime::format($format, $datetime, $timezone, $loc);
        }
        return $formatted;
    }
    
}

