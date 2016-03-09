<?php

class helpdeskRequestTagsModel extends waModel
{
    protected $table = 'helpdesk_request_tags';

    /**
     *
     * Assign tags to request. Tags just assign to request (without removing if exist for concrete request)
     * @param array|int $request_id
     * @param array|int $tag_id
     */
    public function assign($request_id, $tag_id)
    {
        // define existing tags
        $sql = "SELECT * FROM {$this->table} ";
        $where = $this->getWhereByField('request_id', $request_id);
        if ($where) {
            $sql .= " WHERE $where";
        }
        $existed_tags = array();
        foreach ($this->query($sql) as $item) {
            $existed_tags[$item['request_id']][$item['tag_id']] = true;
        }

        // accumulate candidate for adding
        $add = array();
        foreach ((array)$tag_id as $t_id) {
            foreach ((array)$request_id as $p_id) {
                if (!isset($existed_tags[$p_id][$t_id])) {
                    $add[] = array('request_id' => $p_id, 'tag_id' => $t_id);
                }
            }
        }

        // adding itself
        if ($add) {
            $this->multipleInsert($add);
        }

        // recounting counters for this tags
        $tag_model = new helpdeskTagModel();
        $tag_model->recount($tag_id);
    }

    public function set($request_id, $tag_id)
    {
        $this->deleteByField(array('request_id' => $request_id));
        $this->assign($request_id, $tag_id);
    }

    /**
     * @param int|array $request_id
     * @param int|array $tag_id
     */
    public function delete($request_id, $tag_id = null)
    {
        if (!$request_id) {
            return false;
        }
        $request_id = (array)$request_id;

        if ($tag_id !== null) {
            $this->deleteByField(array('request_id' => $request_id, 'tag_id' => $tag_id));
        } else {
            $this->deleteByField(array('request_id' => $request_id));
        }
        // decrease count for tags
        $tag_model = new helpdeskTagModel();
        $tag_model->recount($tag_id);

    }


    /**
     * Tag tag of request(s)
     * @param int|array $request_id
     * @return array()
     */
    public function getTags($request_id)
    {
        if (!$request_id) {
            return array();
        }

        $sql = "
            SELECT t.id, t.name
            FROM ".$this->table." rt
            JOIN helpdesk_tag t ON rt.tag_id = t.id
            WHERE rt.request_id IN (i:id)
        ";
        return $this->query($sql, array('id' => $request_id))->fetchAll('id', true);
    }

}

