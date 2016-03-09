<?php
/**
 * helpdesk_error table stores error messages from sources
 */
class helpdeskErrorModel extends waModel
{
    protected $table = 'helpdesk_error';

    public function getList($p)
    {
        // Sanitize params
        foreach(array('source_id', 'start', 'limit') as $k) {
            if (empty($p[$k]) || !wa_is_int($p[$k]) || $p[$k] < 0) {
                unset($p[$k]);
            }
        }

        $sql = "SELECT SQL_CALC_FOUND_ROWS e.*, s.name
                FROM {$this->table} AS e
                    LEFT JOIN helpdesk_source AS s
                        ON s.id=e.source_id
                WHERE (e.source_id IS NULL OR s.status > 0)
                    ".(empty($p['source_id']) ? '' : "AND e.source_id={$p['source_id']}")."
                ORDER BY e.id DESC
                LIMIT ".ifempty($p['start'], 0).', '.ifempty($p['limit'], 50);
        $list = $this->query($sql)->fetchAll();
        $total = (int) $this->query('SELECT FOUND_ROWS()')->fetchField();

        // Group consecutive errors when they have same text
        $last_error = null;
        foreach($list as $i => &$e) {
            if ($last_error && $last_error['message'] == $e['message']) {
                $last_error['start_datetime'] = $e['datetime'];
                $last_error['count'] = ifset($last_error['count'], 0) + 1;
                unset($list[$i]);
                continue;
            }
            $last_error =& $e;
        }
        unset($last_error, $e);

        return array($list, $total);
    }

    public function getLastBySource($source_id)
    {
        $sql = "SELECT message
                FROM {$this->table}
                WHERE source_id=?
                ORDER BY id DESC
                LIMIT 1";
        return $this->query($sql, array($source_id))->fetchField();
    }
}

