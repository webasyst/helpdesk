<?php

class helpdeskBackendSidebarSaveController extends helpdeskJsonController
{
    public function execute()
    {
        $fm = new helpdeskFilterModel();
        
        $del_ids = waRequest::post('del', array(), waRequest::TYPE_ARRAY);
        $fm->deleteById($del_ids);
        
        $common_ids = waRequest::post('add_common', array(), waRequest::TYPE_ARRAY);
        foreach ($common_ids as $id) {
            if (!wa_is_int($id)) {
                $names = helpdeskHelper::getSpecials();
                $fm->add(array(
                    'name' => ifset($names[$id], $id),
                    'hash' => $id,
                    'shared' => 1
                ));
            }
        }
        
        $my_ids = waRequest::post('add_my', array(), waRequest::TYPE_ARRAY);
        foreach ($my_ids as $id) {
            if (!wa_is_int($id)) {
                $names = helpdeskHelper::getSpecials();
                $fm->add(array(
                    'name' => ifset($names[$id], $id),
                    'hash' => $id,
                    'shared' => 0
                ));
            }
        }
        
        $asm = new waAppSettingsModel();
        $ids = waRequest::post('add', array(), waRequest::TYPE_ARRAY);
        foreach ($ids as $id) {
            if ($id === '@all') {
                $asm->del('helpdesk', 'all_requests_hide');
            }
        }
        
    }
    
    protected function getSpecialId($fm, $id, $shared = 0)
    {
        $names = helpdeskHelper::getSpecials();
        return $fm->insert(array(
            'name' => ifset($names[$id], $id),
            'hash' => $id,
            'sort' => $fm->getMaxSort() + 1,
            'contact_id' => wa()->getUser()->getId(),
            'create_datetime' => date('Y-m-d H:i:s'),
            'shared' => $shared,
        ));
    }
    
}

