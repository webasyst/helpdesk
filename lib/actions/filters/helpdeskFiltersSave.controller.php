<?php
/**
 * Create new or modify existing sidebar filter.
 */
class helpdeskFiltersSaveController extends helpdeskJsonController
{
    public function execute()
    {
        $admin = $this->getRights('backend') > 1;

        $filters = waRequest::request('filters');
        $name = trim(waRequest::request('name'));
        $copy_sort = waRequest::request('copy_sort', null, 'int');
        $id = waRequest::request('id', null, 'int');
        $shared = $admin ? waRequest::request('shared', 0, 'int') : 0;
        $icon = waRequest::request('icon', null, waRequest::TYPE_STRING_TRIM);

        if (!$id && (!$name || !$filters)) {
            throw new waException('Bad parameters');
        }

        $fm = new helpdeskFilterModel();
        $sort = null;
        if (!$id) {
            $sort = $fm->getMaxSort() + 1;
        }

        $f = new helpdeskFilter($id);

        if (!$admin && $f->contact_id != wa()->getUser()->getId()) {
            throw new waRightsException('Access denied');
        }

        if ($filters) {
            $f->hash = $filters;
        }
        if ($name) {
            $f->name = $name;
        }
        if ($sort) {
            $f->sort = $sort;
        }
        if ($icon) {
            $f->icon = $icon;
        }
        
        $f->shared = $shared ? 1 : 0;
        $f->contact_id = wa()->getUser()->getId();
        $this->response = $f->save();

        // remove search from recent searches
        if (!$id) {
            $hm = new helpdeskHistoryModel();
            $hm->deleteByField('hash', '/requests/search/'.$filters);
        }
    }
}

