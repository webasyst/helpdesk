<?php
class helpdeskSourceParamsModel extends waModel
{
    protected $table = 'helpdesk_source_params';

    public function updateLastDatetime($source_id)
    {
        $sql = "REPLACE INTO {$this->table} SET source_id=:id, name='last_timestamp', value=:time";
        return $this->exec($sql, array(
            'time' => time(),
            'id' => $source_id,
        ));
    }

    public function sourceIdByEmail($email)
    {
        $sql = "SELECT source_id
                FROM {$this->table}
                WHERE name='email'
                    AND value LIKE ?";
        return $this->query($sql, array($email))->fetchField();
    }
}

