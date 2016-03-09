<?php
/**
 * helpdesk_filter table stores sidebar links for list views
 */
class helpdeskFilterModel extends waModel
{
    protected $table = 'helpdesk_filter';

    public function add($data)
    {        
        if (!isset($data['shared'])) {
            $data['shared'] = 0;
        }
        
        $data['contact_id'] = wa()->getUser()->getId();
        $data['create_datetime'] = date('Y-m-d H:i:s');
        
        if (!isset($data['sort'])) {
            $data['sort'] = $this->query("SELECT MAX(sort) FROM `{$this->table}` WHERE shared = i:0", array(
                $data['shared']
            ))->fetchField() + 1;
        }
        if (!isset($data['name'])) {
            $data['name'] = '';
        }
        if (!isset($data['hash'])) {
            $data['hash'] = '@all';
        }
        return $this->insert($data);
        
    }
    
    /** @return array id => name */
    public function getPersonal()
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE contact_id=:cid
                    AND shared=0
                ORDER BY sort, name";
        $result = $this->query($sql, array('cid' => wa()->getUser()->getId()))->fetchAll('id');
        $result || $result = array();
        return $result;
    }

    /** @return array id => name */
    public function getCommon()
    {
        $sql = "SELECT *
                FROM {$this->table}
                WHERE shared=1
                ORDER BY sort, name";
        $result = $this->query($sql)->fetchAll('id');
        $result || $result = array();
        return $result;
    }
    
    public function saveCommonAndMy($common_ids, $my_ids)
    {
        if (!$common_ids && !$my_ids) {
            $sql = "DELETE FROM {$this->table} WHERE shared OR contact_id=?";
            $this->exec($sql, wa()->getUser()->getId());
            return;
        }

        $sql = "DELETE FROM {$this->table}
                WHERE (shared OR contact_id=?)
                    AND id NOT IN (?)";
        $this->exec($sql, wa()->getUser()->getId(), array_merge($common_ids, $my_ids));

        $sql = "UPDATE {$this->table} SET shared=?, sort=FIELD(id, ?) WHERE id IN (?)";
        $common_ids && $this->exec($sql, 1, $common_ids, $common_ids);
        $my_ids && $this->exec($sql, 0, $my_ids, $my_ids);
    }

    public function increaseSort($sort)
    {
        $this->exec("UPDATE {$this->table} SET sort = sort + 1 WHERE sort >= :sort", array('sort' => $sort));
    }

    public function getMaxSort()
    {
        return $this->query("SELECT MAX(sort) FROM {$this->table}")->fetchField();
    }
    
    public function move($id, $before_id = null)
    {
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        if (!$before_id) {
            $sort = $this->select('MAX(sort)')->
                where('shared = :0', 
                    array(
                        $item['shared']
                    )
                )->fetchField() + 1;
        } else {
            $before = $this->getById($before_id);
            if (!$before) {
                return false;
            }
            $sort = $before['sort'];
            if ($item['shared']) {
                if (!$this->exec(
                    "UPDATE `{$this->table}` SET sort = sort + 1 WHERE sort >= :0 AND shared = 1", 
                    array(
                        $sort
                    )))
                {
                    return false;
                }
            } else {
                $contact_id = array(
                    wa()->getUser()->getId()
                );
                if (wa()->getUser()->isAdmin()) {
                    $contact_id[] = 0;
                }
                if (!$this->exec(
                    "UPDATE `{$this->table}` SET sort = sort + 1 WHERE sort >= :0 AND shared = 0 AND contact_id IN (:1)", 
                    array(
                        $sort,
                        $contact_id
                    )))
                {
                    return false;
                }
            }
        }
        if (!$this->exec(
            "UPDATE `{$this->table}` SET sort = :0 WHERE id = :1",
            array(
                $sort,
                $item['id']
            )
        ))
        {  
            return false;
        }
        
        return true;
    }
    
}
