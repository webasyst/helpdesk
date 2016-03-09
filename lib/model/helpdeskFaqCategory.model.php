<?php

class helpdeskFaqCategoryModel extends waModel
{
    protected $table = 'helpdesk_faq_category';

    const VIEW_TYPE_SEPARATE = 'separate';
    const VIEW_TYPE_COLLECTIVE = 'collective';

    public function add($data) {
        $sort = (int) $this->select('MAX(sort)')->fetchField();
        $data['sort'] = $sort += 1;
        if (empty($data['name'])) {
            $data['name'] = 'no-name';
        }
        if (isset($data['view_type'])) {
            if (!in_array($data['view_type'], array(
                    self::VIEW_TYPE_SEPARATE,
                    self::VIEW_TYPE_COLLECTIVE)))
            {
                unset($data['view_type']);
            }
        }
        if (empty($data['view_type'])) {
            $data['view_type'] = $this->getDefaultViewType();
        }

        if (empty($data['url'])) {
            $data['url'] = helpdeskHelper::transliterate($data['name']);
        }

        return $this->insert($data);
    }

    public function update($id, $data)
    {
        if (isset($data['view_type'])) {
            if (!in_array($data['view_type'], array(
                    self::VIEW_TYPE_SEPARATE,
                    self::VIEW_TYPE_COLLECTIVE)))
            {
                unset($data['view_type']);
            }
        }
        $this->updateById($id, $data);
    }

    public function get($id, $is_public = null, $is_backend = null) {
        $category = $this->getById($id);
        if ($category) {
            $fm = new helpdeskFaqModel();
            $category['questions'] = $fm->getByFaqCategory($category['id']);
            $count = count($category['questions']);
            if ($category['count'] !== $count) {
                $this->updateById($id, array(
                    'count' => $count
                ));
                $category['count'] = $count;
            }
            foreach ($category['questions'] as $k => $q) {
                if ($is_public && !$q['is_public']) {
                    unset($category['questions'][$k]);
                }
                if ($is_backend && !$q['is_backend']) {
                    unset($category['questions'][$k]);
                }
            }
            $this->setMarks($category);
        }
        return $category;
    }

    protected function setMarks(&$data, $multiple = false)
    {
        if (!$multiple) {
            $data['draft'] = empty($data['is_backend']) && empty($data['is_public']);
            $data['draft_html'] = $data['draft'] ? helpdeskHelper::getFaqMarkHtml('draft') : '';
            $data['backend_only'] = !empty($data['is_backend']) && empty($data['is_public']);
            $data['backend_only_html'] = $data['backend_only'] ? helpdeskHelper::getFaqMarkHtml('backend_only') : '';
            $data['site_only'] = empty($data['is_backend']) && !empty($data['is_public']);
            $data['site_only_html'] = $data['site_only'] ? helpdeskHelper::getFaqMarkHtml('site_only') : '';
        } else {
            foreach ($data as &$item) {
                $this->setMarks($item, false);
            }
            unset($item);
        }
    }


    public function getEmptyRow() {
        $category = parent::getEmptyRow();
        $category['id'] = null;
        $category['view_type'] = $this->getDefaultViewType();
        $category['questions'] = array();
        $category['icon'] = 'folder';
        $this->setMarks($category);
        return $category;
    }

    public function getNoneCategory($with_faqs = false)
    {
        $fm = new helpdeskFaqModel();
        $count = $fm->countByField(array('faq_category_id' => 0));
        $item = $this->getEmptyRow();
        $item['id'] = 0;
        $item['name'] = _w('<no category>');
        $item['icon'] = null;
        $item['count'] = $count;
        if ($with_faqs) {
            $item['questions'] = $fm->getByFaqCategory(0);
        }
        $item['none'] = true;
        return $item;;
    }

    public function getCounters()
    {
        $counters = $this->select('id, count')->fetchAll();
        $fm = new helpdeskFaqModel();
        $counters[] = array('id' => 0, 'count' => $fm->countByField('faq_category_id', 0));
        return $counters;
    }

    public function updateCounters($id = null)
    {
        $sql = "UPDATE
        `{$this->table}` t LEFT JOIN (
            SELECT faq_category_id, COUNT(*) AS count FROM `helpdesk_faq` GROUP BY faq_category_id
        ) r ON t.id = r.faq_category_id
        SET t.count = IF(r.count IS NULL, 0, r.count)";
        if ($id !== null) {
            $id = array_map('intval', (array) $id);
            $sql .= 'WHERE t.id IN('.  implode(',', $id) . ')';
        }
        $this->exec($sql);
    }

    public function getById($id) {
        $item = parent::getById($id);
        if (!$item) {
            return false;
        }
        if (is_array($id)) {
            foreach ($item as &$i) {
                $i['icon'] = $i['icon'] ? $i['icon'] : 'folder';
            }
            unset($i);
        } else {
            $item['icon'] = $item['icon'] ? $item['icon'] : 'folder';
        }
        $this->setMarks($item);
        return $item;
    }

    public function getList($query) {
        if ($query) {

            $where = array();
            foreach(preg_split('~\s+~su', $query) as $part) {
                $part = trim($part);
                if (strlen($part) > 0) {
                    $p = $this->escape($part, 'like');
                    $where[] = "(f.answer LIKE '%{$p}%' OR f.question LIKE '%{$p}%')";
                }
            }

            $sql = "SELECT * FROM `{$this->table}` f " . ($where ? " WHERE " . implode($where, ' AND ') : '');
            return $this->query($sql)->fetchAll();


        } else {
            return $this->getAll();
        }
    }

    public function getAllCategories()
    {
        $categories = $this->getAll();
        $categories[] = $this->getNoneCategory();
        return $categories;
    }

    public function getAll($key = null, $normalize = false, $is_public = false) {
        $condition = $is_public ? ' WHERE is_public=1' : '';
        $sql = "SELECT * FROM " . $this->table . $condition . " ORDER BY sort";
        $items = $this->query($sql)->fetchAll($key, $normalize);
        foreach ($items as &$el) {
            $el['icon'] = $el['icon'] ? $el['icon'] : 'folder';
        }
        unset($el);
        $this->setMarks($items, true);
        return $items;
    }

    public function move($id, $before_id = null)
    {
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        if (!$before_id) {
            $sort = $this->select('MAX(sort)')->fetchField() + 1;
        } else {
            $before = $this->getById($before_id);
            if (!$before) {
                return false;
            }
            $sort = $before['sort'];
            if (!$this->exec(
                "UPDATE `{$this->table}` SET sort = sort + 1 WHERE sort >= :0",
                array(
                    $sort
                )))
            {
                return false;
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

    public function checkUrlUniq($url, $id) {
        $condition = $id ? 'AND id<>?' : '';
        $sql = "SELECT * FROM " . $this->table . " WHERE `url`=? $condition LIMIT 1";
        return $this->query($sql, $url, $id)->fetchAssoc();
    }

    private function getDefaultViewType()
    {
        return self::VIEW_TYPE_COLLECTIVE;
    }
}

