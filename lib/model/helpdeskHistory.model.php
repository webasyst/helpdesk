<?php
/**
 * helpdesk_history table stores user search history
 */
class helpdeskHistoryModel extends waModel
{
    protected $table = 'helpdesk_history';
    const NUM_HISTORY = 20;

    /**
     * Get all history for current user, or a single history record
     *
     * When $id is given, returns null (when not found) or a DB row.
     * With no $id, returns an array of DB rows.
     *
     * @param int $id id of a record to fetch, or omit to get last history items
     * @return array
     */
    public function get($id=null)
    {
        if ($id) {
            $sql = "SELECT * FROM `{$this->table}` WHERE id=i:id";
            return $this->query($sql, array('id' => $id))->fetchRow();
        }

        $currentUserId = wa()->getUser()->getId();
        $sql = "SELECT *
                FROM {$this->table}
                WHERE contact_id=:uid
                ORDER BY id DESC";
        $history = $this->query($sql, array('uid' => $currentUserId))->fetchAll();

        // delete from DB if there's too many records
        if (count($history) > self::NUM_HISTORY) {
            $ids = array();
            foreach(array_slice($history, self::NUM_HISTORY-1) as $row) {
                $ids[] = $row['id'];
            }
            $sql = "DELETE FROM `{$this->table}` WHERE id IN (i:id)";
            $this->exec($sql, array('id' => $ids));
            $history = array_slice($history, 0, self::NUM_HISTORY);
        }

        return $history;
    }

    /**
      * Create a new record in history or update an existing one.
      * New record is created as temporary (not fixed). Existing record status does not change.
      * @param string $hash URL part after #, without #
      * @param string $name Human-readable title (null to do not update name)
      * @param string $type history record type
      * @param mixed $count number to show as a count; -1 (default) to show no number at all. If '--' is passed as $count then existing number is decreased by 1.
      * @return boolean true if new record created, false otherwise
      */
    public function save($hash, $name, $type, $count=null)
    {
        $sql = "SELECT id FROM `{$this->table}` WHERE contact_id=i:uid AND hash=:hash";
        $id = $this->query($sql, array('uid' => wa()->getUser()->getId(), 'hash' => $hash))->fetchAssoc();
        if ($id) {
            return false;
        }

        // Create history record
        $id = $this->insert(array(
            'type' => $type,
            'name' => $name,
            'hash' => $hash,
            'cnt' => $count ? $count : -1,
            'contact_id' => wa()->getUser()->getId(),
        ));

        return true;
    }

    /**
     * Remove history of given type, except last $limit records.
     * @param int $limit (default self::NUM_HISTORY) how many items to keep
     * @param mixed $type if specified, then only records of given type or types are affected (string or array).
     */
    public function prune($limit=null, $type=null) {
        $currentUserId = wa()->getUser()->getId();
        $typeSql = $type ? " AND type IN (:type) " : '';
        if ($limit === null) {
            $limit = self::NUM_HISTORY;
        }

        // How many records are there?
        $sql = "SELECT COUNT(*) FROM `{$this->table}` WHERE contact_id=i:uid".$typeSql;
        $total = $this->query($sql, array('uid' => $currentUserId, 'type' => $type))->fetchField();

        $limit = $total - $limit;
        if ($limit > 0) {
            $sql = "DELETE FROM `{$this->table}` WHERE contact_id=:uid$typeSql ORDER BY id LIMIT i:limit";
            $this->exec($sql, array('uid' => $currentUserId, 'limit' => $limit, 'type' => $type));
        }
    }
}
