<?php
/**
 * Represents a filter: persistent link in sidepar to perform frequent search operation.
 * ORM class for helpdesk_filter table.
 */
class helpdeskFilter extends waDbRecord
{
    public function __construct($id = null)
    {
        static $m = null;
        if ($m === null) {
            $m = new helpdeskFilterModel();
        }
        parent::__construct($m, $id);
    }

    protected function getDefaultValues()
    {
        return array(
            'name' => '',
            'hash' => '',
            'sort' => 0,
            'contact_id' => wa()->getUser()->getId(),
            'create_datetime' => date('Y-m-d H:i:s'),
            'shared' => 0,
            'icon' => null,
        ) + parent::getDefaultValues();
    }
}
