<?php

class helpdeskFaqModel extends waModel
{
    protected $table = 'helpdesk_faq';

    public function add($data)
    {
        $data['create_datetime'] = date('Y-m-d H:i:s');
        $data['contact_id'] = wa()->getUser()->getId();

        if (empty($data['faq_category_id'])) {
            $data['faq_category_id'] = 0;
        }
        $this->query("UPDATE `{$this->table}`
                    SET sort = sort + 1
                    WHERE faq_category_id = i:0",
                    array($data['faq_category_id']));
        $data['sort'] = max(
            $this->select('MIN(sort)')->
                where('faq_category_id = i:0', array($data['faq_category_id']))->
                fetchField() - 1,
            0);

        if (empty($data['comment'])) {
            $data['comment'] = null;
        }

        $id = $this->insert($data);

        if ($data['faq_category_id']) {
            $fcm = new helpdeskFaqCategoryModel();
            $fcm->updateCounters($data['faq_category_id']);
        }

        if (!$id) {
            return false;
        }

        $this->copyAnswerFiles($id);

        return $id;

    }

    public function update($id, $data) {
        if (isset($data['comment']) && empty($data['comment'])) {
            $data['comment'] = null;
        }
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        $this->updateById($id, $data);
        $this->copyAnswerFiles($id);
        if ($item['faq_category_id'] != $data['faq_category_id']) {
            $fcm = new helpdeskFaqCategoryModel();
            $fcm->updateCounters(array($item['faq_category_id'], $data['faq_category_id']));
        }
    }

    public function copyAnswerFiles($faq_id)
    {
        $faq = $this->getById($faq_id);
        if (!$faq) {
            return;
        }
        // Copy images mentioned in html code into new log's directory
        $url = wa()->getDataUrl('files/', true, 'helpdesk');
        $path = wa()->getDataPath('files/', true, 'helpdesk');

        $id = str_pad($faq_id, 4, '0', STR_PAD_LEFT);
        $dir = wa()->getDataPath('files/faq', true, 'helpdesk').'/'.substr($id, -2).'/'.substr($id, -4, 2).'/'.$faq_id;

        $new_url = wa()->getDataUrl('files/faq', true, 'helpdesk').'/'.substr($id, -2).'/'.substr($id, -4, 2).'/'.$faq_id;

        if (preg_match_all('~'.$url.'([^\)"\']+)~is', $faq['answer'], $m)) {
            waFiles::create($dir);
            foreach(array_flip(array_flip($m[1])) as $old_file) {
                $new_file = basename($old_file);
                $old_path = $path.$old_file;
                $new_path = $dir.'/'.$new_file;
                if ($old_path == $new_path) {
                    continue;
                }
                while(file_exists($new_path)) {
                    $new_file = rand(0, 9).$new_file;
                    $new_path = $new_path = $dir.'/'.$new_file;
                }
                if (file_exists($old_path)) {
                    waFiles::copy($old_path, $new_path);
                }
                $faq['answer'] = str_replace($url.$old_file, $new_url.'/'.$new_file, $faq['answer']);
            }

            $this->updateById($faq_id, array('answer' => $faq['answer']));

        }
    }

    public function getByFaqCategory($category_id, $is_public = false)
    {
        $condition = $is_public ? ' AND is_public=1' : '';
        $faqs = $this->select('*')->where('faq_category_id = i:0' . $condition, array(
            $category_id
        ))->order('sort')->fetchAll();
        $this->setMarks($faqs, true);
        return $faqs;
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
            $data['backend_and_site'] = !empty($data['is_backend']) && !empty($data['is_public']);
            $data['backend_and_site_html'] = $data['backend_and_site'] ?  helpdeskHelper::getFaqMarkHtml('backend_and_site') : '';
        } else {
            foreach ($data as &$item) {
                $this->setMarks($item, false);
            }
            unset($item);
        }
    }

    public function moveToCategory($id, $category_id)
    {
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        $category_id = (int) $category_id;
        $fcm = new helpdeskFaqCategoryModel();
        if ($category_id > 0) {
            $category = $fcm->getById($category_id);
            if (!$category) {
                return false;
            }
        } else {
            $category = $fcm->getNoneCategory();
        }
        $sort = $this->select('MAX(sort)')->where("faq_category_id = {$category_id}")->fetchField() + 1;
        if (!$this->exec(
                "UPDATE `{$this->table}` SET sort = :sort, faq_category_id = :faq_category_id WHERE id = :id",
                array(
                    'sort' => $sort,
                    'faq_category_id' => $category['id'],
                    'id' => $item['id']
                )
        )) {
            return false;
        }

        $fcm->updateCounters(array($category['id'], $item['faq_category_id']));

        return true;
    }

    public function move($id, $before_id = null)
    {
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        $category_id = $item['faq_category_id'];
        if (!$before_id) {
            $sort = $this->select('MAX(sort)')->where("faq_category_id = {$category_id}")->fetchField() + 1;
        } else {
            $before = $this->getById($before_id);
            if (!$before || $category_id != $before['faq_category_id']) {
                return false;
            }
            $sort = $before['sort'];
            if (!$this->exec(
                "UPDATE `{$this->table}` SET sort = sort + 1 WHERE sort >= :0 AND faq_category_id = {$category_id}",
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

    public function getList($query, $is_public = null, $is_backend = null) {
        if ($query) {
            $where = array();
            $search_parts = array();
            foreach(preg_split('~\s+~su', $query) as $part) {
                $part = trim($part);
                if (strlen($part) > 0) {
                    $p = $this->escape($part, 'like');
                    $where[] = "(f.answer LIKE '%{$p}%' OR f.question LIKE '%{$p}%')";
                    $search_parts[] = $part;
                }
            }

            $fields = array('f.*');
            $joins = array();

            if ($is_public) {
                $fields[] = 'c.url category_url';
                $joins[] = "LEFT JOIN helpdesk_faq_category c ON c.id=f.faq_category_id";
                $where[] = "f.is_public = 1";
            }

            if ($is_backend) {
                $where[] = "f.is_backend = 1";
            }

            $sql = "SELECT " . implode(', ', $fields) . ' ' .
                        "FROM `{$this->table}` f " .
                        implode(' ', $joins) . ' ' .
                        ($where ? "WHERE " . implode(' AND ', $where) : '');

            $list = $this->query($sql)->fetchAll();

            // Helper to highlight search words
            $replace = array();
            foreach($search_parts as $part) {
                $replace['~('.preg_quote($part, '~').')~iu'] = '<span class="h-faq-highlighted">\1</span>';
            }

            if ($search_parts) {

                foreach ($list as $id => &$item) {

                    foreach ($replace as $regex => $replacement) {
                        $item['question_highlighted'] = preg_replace($regex, $replacement, htmlspecialchars($item['question']));
                        $item['answer_highlighted'] = preg_replace($regex, $replacement, $item['answer']);
                    }

                    // Strip tags from text
                    $text = preg_replace('~<(title|style[^>]*)>[^<]*</(title|style)>~', ' ', $item['answer']);
                    $text = str_replace('<', ' <', $text);
                    $text = trim(strip_tags($text));

                    // Highlight found words in text and subject, and ensure that every word is present.
                    // Have to check it in PHP because MySQL search may erroneously find text in HTML markup (e.g. 'center').
                    $count = 0;
                    foreach($replace as $regex => $replacement) {
                        $text = preg_replace($regex, $replacement, $text, -1, $cnt);
                        $count = $count += $cnt;
                    }

                    if ($count <= 0) {
                        unset($item[$id]);
                        continue;
                    }

                    $offset = 0;
                    $fragments = array();
                    $len = floor(300 / $count);
                    $shift = floor($len / 6);
                    for ($i = 0; $i < $count; $i += 1) {
                        // Only show part of the text, preferably containing highlights.
                        $pos = mb_strpos($text, '<span class="h-faq-highlighted">', $offset);
                        $offset = $pos + 1;
                        $pos = max(0, $pos - $shift);
                        $max_pos = mb_strlen($text);
                        $fragment = mb_substr($text, $pos, $len);
                        if ($pos > $shift && $i === 0) {
                            $fragment = '...' . $fragment;
                        }
                        if ($pos + $len < $max_pos) {
                            $fragment .= '...';
                        }
                        $fragments[] = $fragment;
                    }
                    $item['fragments'] = $fragments;

                }
                unset($item);

            }

            $this->setMarks($list, true);

            return $list;


        } else {
            return $this->getAll();
        }
    }

    public function checkUrlUniq($url, $category_id, $id) {
        $condition = $id ? ' AND id<>?' : '';
        $sql = "SELECT * FROM " . $this->table . " WHERE `url` = ? AND faq_category_id = ? $condition LIMIT 1";
        return $this->query($sql, $url, $category_id, $id)->fetchAssoc();
    }

    public function delete($id)
    {
        $item = $this->getById($id);
        if (!$item) {
            return false;
        }
        if (!$this->deleteById($id)) {
            return false;
        }
        if ($item['faq_category_id']) {
            $fcm = new helpdeskFaqCategoryModel();
            $fcm->updateById($item['faq_category_id'], array(
                'count' => $this->countByField(array('faq_category_id' => $item['faq_category_id']))
            ));
        }
        return true;
    }

    public function getById($value) {
        $item = parent::getById($value);
        if ($item) {
            $item['faq_category_id'] = (int)$item['faq_category_id'];
        }
        return $item;
    }
}
