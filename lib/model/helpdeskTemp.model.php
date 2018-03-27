<?php
/**
 * Storage for data from mailboxes and forms during email 
 * antispam validation period.
 */
class helpdeskTempModel extends waModel
{
    protected $table = 'helpdesk_temp';

    public function cleanOldTemp()
    {
        $date = date('Y-m-d H:i:s', strtotime('-3 days'));
        $sql = "DELETE FROM {$this->table} WHERE `created` < ?;";
        $this->exec($sql, $date);
    }
}
