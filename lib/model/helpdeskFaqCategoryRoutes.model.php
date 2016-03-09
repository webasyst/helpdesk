<?php

class helpdeskFaqCategoryRoutesModel extends waModel
{
    protected $table = 'helpdesk_faq_category_routes';
    protected $id = array('category_id', 'route');

    /**
     * @param int|array[int] $category_id
     * @return array
     */
    public function get($category_id)
    {
        if (!$category_id) {
            return array();
        }
        $category_ids = array_map('intval', (array) $category_id);

        $data = array_fill_keys($category_ids, array());
        foreach ($this->getByField('category_id', $category_ids, true) as $item) {
            $item['route'] = trim(trim($item['route'], '/*'));
            $data[$item['category_id']][] = $item;
        }

        return wa_is_int($category_id) ? $data[(int) $category_id] : $data;
    }

    /**
     * @param array $data
     *      [
     *          int category_id =>
     *              [
     *                  [
     *                      string 'route' => route
     *                      ...
     *                  ]
     *              ]
     *      ]
     */
    public function set($data)
    {
        $insert = array();
        foreach ((array) $data as $category_id => $category) {
            $category_id = (int) $category_id;
            if (!$category_id) {
                continue;
            }
            $this->deleteByField('category_id', $category_id);
            foreach ($category as $item) {
                $insert[] = array(
                    'category_id' => $category_id,
                    'route' => $item['route']
                );
            }
        }

        if ($insert) {
            $this->multipleInsert($insert);
        }
    }

}