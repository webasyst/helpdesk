<?php
/**
 * Sidebar editor.
 */
class helpdeskSettingsSidebarAction extends helpdeskViewAction
{
    public function execute()
    {

        $fm = new helpdeskFilterModel();

        // Save filters and settings if POST came
        if (waRequest::post()) {

            // Save filters
            $common_ids = waRequest::post('common', array(), 'array');
            $my_ids = waRequest::post('my', array(), 'array');

            if ($this->getRights('backend') <= 1) {
                $my_ids = array_merge($my_ids, $common_ids);
                $common_ids = array_keys($fm->getCommon());
                $my_ids = array_diff($my_ids, $common_ids);
            }

            $arrs = array(&$common_ids, &$my_ids);
            foreach($arrs as &$arr) {
                foreach($arr as $i => &$id) {
                    if (!wa_is_int($id)) {
                        $id = $this->getSpecialId($fm, $id);
                        if (!$id) {
                            unset($arr[$i]);
                        }
                    }
                }
            }
            unset($arrs, $arr, $id);

            $fm->saveCommonAndMy($common_ids, $my_ids);

            // Filter names
            $filter_names = waRequest::post('filter_names', array(), 'array');
            foreach($filter_names as $id => $name) {
                $fm->updateById($id, array(
                    'name' => ifempty($name, $id),
                ));
            }
        }

        // Common and my
        $my_filters = $fm->getPersonal();
        $common_filters = $fm->getCommon();

        // Unused filters
        $specials_id_by_hash = array();
        $specials_hash_by_id = array();

        $specials_hash_by_id = $specials = self::getSpecials();
        array_walk($specials_hash_by_id, wa_lambda('&$v, $k', '$v = $k;'));

        foreach($my_filters + $common_filters as $row) {
            if (isset($specials[$row['hash']])) {
                $specials_id_by_hash[$row['hash']] = $row['id'];
                $specials_hash_by_id[$row['id']] = $row['hash'];
                unset($specials[$row['hash']]);
            }
        }

        $all_specials = array();
        foreach(self::getSpecials() as $hash => $name) {
            $all_specials[ifset($specials_id_by_hash[$hash], $hash)] = $name;
        }

        // access control
        $admin = $this->getRights('backend') > 1;
        $this->view->assign('admin', $admin);
        $this->view->assign('my_filters', $my_filters);
        $this->view->assign('common_filters', $common_filters);
        $this->view->assign('all_specials', $all_specials);
        $this->view->assign('specials_hash_by_id', $specials_hash_by_id);
        $this->view->assign('specials', $specials);
        $this->view->assign('uniqid', uniqid('f'));
    }

    protected function getSpecialId($fm, $id)
    {
        $names = self::getSpecials();
        return $fm->insert(array(
            'name' => ifset($names[$id], $id),
            'hash' => $id,
            'sort' => $fm->getMaxSort() + 1,
            'contact_id' => wa()->getUser()->getId(),
            'create_datetime' => date('Y-m-d H:i:s'),
            'shared' => 0,
        ));
    }

    protected static function getSpecials()
    {
        static $names = null;
        $names || $names = array(
            '@all' => _w('All requests'),
            '@by_sources' => _w('Sources'),
            '@by_assignment' => _w('Assignments'),
            '@by_states' => _w('Request states'),
        );
        return $names;
    }
}

