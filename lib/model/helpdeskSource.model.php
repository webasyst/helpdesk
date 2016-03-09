<?php
/**
 * Note that for sources there is an ORM class: helpdeskSource.
 * To work with  single sources, use that.
 * This model is mostly used to fetch several sources at once.
 */
class helpdeskSourceModel extends waModel
{

    protected $table = 'helpdesk_source';
    protected $table_param = 'helpdesk_source_params';

    /**
      * All sources ordered by name.
      * @param boolean $key true to return params from source_params table in 'params' key (defaults to false)
      * @param mixed $normalize not used
      * @return array id => db row
      */
    public function getAll($key = null, $normalize = false)
    {
        $sql = "SELECT * FROM ".$this->table." ORDER BY name";
        $sources = $this->query($sql)->fetchAll('id');
        $sources || $sources = array();
        if ($key) {
            $sources = $this->addParams($sources);
        }
        return $sources;
    }

    public function getAllWithWorkflow()
    {
        $sql = "SELECT s.*, p.value workflow_id FROM ".$this->table." s
            LEFT JOIN helpdesk_source_params p ON p.source_id = s.id AND p.name = 'workflow'
            ORDER BY s.name";
        return $this->query($sql)->fetchAll('id');
    }

    public function getAllWithWorkflowNotDeleted()
    {
        $sql = "SELECT s.*, p.value workflow_id FROM ".$this->table." s
            LEFT JOIN helpdesk_source_params p ON p.source_id = s.id AND p.name = 'workflow'
            WHERE s.status != -1
            ORDER BY s.name";
        return $this->query($sql)->fetchAll('id');
    }

    public function addParams($sources)
    {
        $ids = array();
        foreach($sources as &$s) {
            $s['params'] = array();
            $ids[] = $s['id'];
        }
        unset($s);

        if ($ids) {
            $sql = "SELECT * FROM ".$this->table_param." WHERE source_id IN (i:id)";
            $params = $this->query($sql, array('id' => $ids));
            foreach ($params as $row) {
                $sources[$row['source_id']]['params'][$row['name']] = $row['value'];
            }
        }

        return $sources;
    }

    public function getByWorkflowId($workflow_id)
    {
        $sql = "SELECT s.* FROM `{$this->table}` s
                JOIN `helpdesk_source_params` sp ON sp.source_id = s.id
                WHERE sp.name = 'workflow' AND sp.value = i:workflow_id";
        return $this->query($sql, array(
            'workflow_id' => $workflow_id
        ))->fetchAll('id');
    }

    /** Remove params of a single source.
      * @param int $id source id
      * @param array $params list of param names to remove. Defaults to remove all. */
    public function delParams($id, $params = false)
    {
        $sql = "DELETE FROM ".$this->table_param." WHERE source_id = ".(int)$id;
        if ($params && is_array($params)) {
            $sql .= " AND name IN ('".implode("', '", $this->escape($params))."')";
        }
        return $this->exec($sql);
    }

    /** Remove source with given $id */
    public function delete($id)
    {
        if ($this->delParams($id)) {
            $sql = "DELETE FROM ".$this->table."
                    WHERE `id` = i:id";
            return $this->prepare($sql)->exec(array('id' => $id));
        } else {
            return false;
        }
    }

    /**
     * List of all source_ids that exist in helpdesk_request.source_id,
     * but do not exist in helpdesk_source.id
     */
    public function getBrokenIds()
    {
        $sql = "SELECT DISTINCT r.source_id FROM helpdesk_request AS r";
        $res = $this->query($sql)->fetchAll('source_id');
        $res || $res = array();

        $existing = $this->query("SELECT id FROM {$this->table}")->fetchAll('id');
        foreach(array_keys($existing) as $id) {
            unset($res[$id]);
        }

        return array_keys($res);
    }

    /**
     * All data (with params) for sources that currently have
     * unresolved errors.
     */
    public function getWithError()
    {
        $sql = "SELECT s.*
                FROM {$this->table} AS s
                    JOIN {$this->table_param} as sp
                        ON sp.source_id=s.id
                WHERE sp.name=?
                    AND s.status > 0
                ORDER BY name";
        $s_arr = $this->query($sql, 'error_datetime')->fetchAll('id');
        $s_arr = $this->addParams(ifempty($s_arr, array()));

        $sources = array();
        foreach($s_arr as $s) {
            $sources[$s['id']] = helpdeskSource::get($s);
        }
        return $sources;
    }
}

