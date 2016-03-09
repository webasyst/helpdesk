<?php
/**
 * List of requests for backend with given filters applied.
 *
 * Uses helpdeskRequestsCollection to retrieve records from DB.
 */
class helpdeskRequestsListController extends helpdeskJsonController
{
    public $filter;
    public $filters_hash;
    public $filter_mark;

    public function execute()
    {
        $this->filter = null;
        $this->filters_hash = '@all';

        $order = waRequest::post('order');
        $limit = waRequest::post('limit', 50, 'int');
        $offset = waRequest::post('offset', 0, 'int');
        $filters = $this->getFiltersFromPost();

        $offset > 0 || $offset = 0;
        $limit > 0 || $limit = 50;

        if (empty($filters) && $this->appSettings('all_requests_hide')) {
            throw new waRightsException(_w('Access denied.'));
        }

        $c = helpdeskRequestsCollection::create($filters);

        if (empty($this->filter_mark)) {
            // We fetch more requests than we've been asked for to send back
            // 50 more request_ids in either direction of the list.
            // They are used for prev request / next request traversal on request pages.
            $offset_bonus = max(0, $offset - 50);
            $limit_bonus = $limit + 50 + $offset - $offset_bonus;
        } else {
            $offset_bonus = $offset;
            $limit_bonus = $limit;
        }

        $c->limit($offset_bonus, $limit_bonus);

        if (!in_array($order, array('id', '!id', 'updated', '!updated'))) {
            $order = '!updated';
        }
        if ($order{0} == '!') {
            $c->orderBy('r.'.substr($order, 1), 'DESC');
        } else {
            $c->orderBy('r.'.$order);
        }

        $requests_bonus = $c->getRequests();
        $requests = helpdeskRequest::prepareRequests(array_slice($requests_bonus, $offset - $offset_bonus, $limit));
        $count = $c->count();
        $header = $c->getHeader();

        // Request_ids to use for prev request / next request traversal on request pages.
        $request_ids = array();
        foreach($requests_bonus as $i => $r) {
            $request_ids[] = $r['id'];
        }

        $this->response = array(
            'mark' => $c->getMark(),
            'count' => $count,
            'requests' => $requests,
            'header' => $header,
            'collection_header' => $c->getHeader(),
            'unread_count' => wao(new helpdeskUnreadModel())->countByContact(),
            'request_ids' => $request_ids,
            'ids_offset' => $offset_bonus,
            'is_update' => !empty($this->filter_mark),
        );

        if (waSystemConfig::isDebug()) {
            $this->response['sql'] = ifset($c->last_sql);
        }

        $this->response['filters_hash'] = $this->filters_hash;
        if ($this->filter) {
            $this->response['filters'] = $this->filter->hash;
            $this->response['own_filter'] = $this->filter->contact_id == wa()->getUser()->getId() && !$this->filter->shared;
            $this->response['shared'] = (int) $this->filter->shared;
            $this->response['header'] = $this->filter->name;
        }

        if ($this->filters_hash === 'unread') {
            $this->response['settings'] = $this->getSettings();
        }

        $this->saveHistory($header, $count);
        $this->getErrorsFromSources();
        $this->getRemovedRequestsByMark();

        $this->response['f'] = array(
            'id' => $this->filter ? $this->filter['id'] : null,
            'shared' => $this->filter ? $this->filter['shared'] : 1,
            'contact_id' => $this->filter ? $this->filter['contact_id'] : wa()->getUser()->getId(),
            'hash' => $this->filter ? $this->filter['hash'] : $this->filters_hash,
            'name' => $this->filter ? $this->filter['name'] : trim($header),
            'icon' => $this->filter && $this->filter['icon'] ? $this->filter['icon'] : 'search'
        );
        if ($this->response['f']['contact_id']) {
            $filter_creator_name = '';
            $filter_creator = new waContact($this->response['f']['contact_id']);
            try {
                $filter_creator_name = $filter_creator->getName();
            } catch (waException $e) {
            }
            $this->response['f']['creator'] = array(
                'id' => $this->response['f']['contact_id'],
                'name' => $filter_creator_name
            );
        }
        if ($this->filter) {
            $this->response['f']['create_datetime_str'] = waDateTime::format('humandatetime', $this->filter['create_datetime']);
        }
        $this->response['admin'] = $this->getRights('backend') > 1;
        $this->response['uniqid'] = uniqid('i');
        $this->response['icons'] = helpdeskHelper::getIcons();

//        $admin = $this->getRights('backend') > 1;
//
//        $filters = waRequest::request('filters');
//        $name = trim(waRequest::request('name'));
//        $id = waRequest::request('id', null, 'int');
//        $shared = $admin ? waRequest::request('shared', 0, 'int') : 0;
//
//        $fm = new helpdeskFilterModel();
//        $sort = $id ? null : ($fm->getMaxSort() + 1);
//
//        $f = new helpdeskFilter($id);
//        if (!$admin && $f->contact_id != wa()->getUser()->getId()) {
//            throw new waRightsException('Access denied');
//        }
//
//        $filters && $f->hash = $filters;
//        $name && $f->name = $name;
//        $sort && $f->sort = $sort;
//        $f->shared = $shared ? 1 : 0;
//        $f->contact_id = wa()->getUser()->getId();
//
//        $this->view->assign('f', $f);
//        $this->view->assign('admin', $admin);
//        $this->view->assign('uniqid', uniqid('i'));

    }


    /**
     * Error messages from sources to show in sidebar and above the list.
     */
    protected function getErrorsFromSources()
    {
        list($this->response['workflows_errors'], $this->response['sources_errors']) = helpdeskHelper::getWorkflowsErrors();
    }

    /**
     * Save search into search history in sidebar
     */
    protected function saveHistory($header, $count)
    {
        if (!$this->filter && waRequest::request('is_search')) {
            $hm = new helpdeskHistoryModel();
            $changed = $hm->save(
                '/requests/search/'.waRequest::post('filters'),
                $header,
                'search',
                $count
            );
            if ($changed) {
                $this->response['history'] = $hm->get();
            }
        }
    }

    /**
     * This one requires some background info...
     *
     * When user is in list view, browser issues XHRs in background to keep the list up to date.
     * There's a special filter for that: mark filter. A mark specifies a moment in time. For update XHRs
     * browser adds its last mark, so this controller only sends back requests updated since the mark.
     *
     * This allows to quickly fetch all updated requests that still satisfy filtering conditions. The main collection
     * does that in execute().
     *
     * The tricky part is to fetch requests that no longer satisfy filtering conditions after update.
     * That's exactly what this method does: sends to browser all requests that has to be removed from the list.
     */
    protected function getRemovedRequestsByMark()
    {
        if (empty($this->filter_mark) || waRequest::post('no_removed')) {
            return;
        }
        $filters = array($this->filter_mark);
//        if ($this->filter_rights) {
//            $filters[] = $this->filter_rights;
//        }

        $c = helpdeskRequestsCollection::create($filters);
        $remove = array();
        foreach($c->limit(0, 500)->getRequests() as $r) {
            $remove[$r['id']] = true;
        }

        foreach($this->response['requests'] as $r) {
            unset($remove[$r['id']]);
        }

        foreach(array_keys($remove) as $id) {
            $this->response['requests'][] = array(
                'id' => $id,
                'remove' => 1,
            );
        }
    }

    /** waRequest::post('filters') == filter1:param:param&filter2:1,2,3,4:param
      * Literal specials &, : and ` in params are escaped by backtick: `&, `:, ``. */
    public function getFiltersFromPost()
    {
        if (! ( $filters = waRequest::post('filters'))) {
            return array();
        }

        if (wa_is_int($filters)) {
            $this->filter = $f = new helpdeskFilter($filters);

            // check if user has access to this filter
            if (!$f->shared && $f->contact_id != wa()->getUser()->getId() && $this->getRights('backend') <= 1) {
                throw new waRightsException(_w('Access denied.'));
            }

            $filters = $f->hash;
        }

        $this->filters_hash = $filters;

        // Strings to temporary repace quoted literals with
        foreach(array('q_amp', 'q_colon', 'q_backtick', 'q_ge', 'q_le') as $var) {
            do {
                $$var = $var.rand();
            } while(FALSE !== strpos($filters, $$var));
        }

        // replace quoted literals with temporary values to simplify parsing
        $filters = str_replace(
            array('`&', '`:', '``', '`>=', '`<='),
            array($q_amp, $q_colon, $q_backtick, $q_ge, $q_le),
            $filters
        );

        // parse filters
        $result = array();
        $human_readable = array();
        $this->filter_mark = null;
        foreach(explode('&', $filters) as $filter) {
            if (!$filter) {
                throw new waException('Bad filters parameter.');
            }

            $filter = preg_split('/(:|>=|<=)/', $filter, 3, PREG_SPLIT_DELIM_CAPTURE);
            $name = array_shift($filter);
            $params = array();
            $op = array_shift($filter);
            foreach($filter as $param) {
                $params[] = str_replace(
                    array($q_amp, $q_colon, $q_backtick, $q_ge, $q_le),
                    array('&', ':', '`', '>=', '<='),
                    $param
                );
            }
            $result[] = array(
                'name' => $name,
                'op' => $op,
                'params' => $params,
            );
            if ($name == 'mark') {
                $this->filter_mark = end($result);
            }
        }

        return $result;
    }

    public function getSettings()
    {
        $csm = new waContactSettingsModel();
        $default_settings = array(
            'count_all_new' => false,
            'count_assigned' => false,
            'count_assigned_logs' => false,
            'count_assigned_group' => false,
            'count_assigned_group_logs' => false,
            'mark_read_when_open' => false,
            'display_oncount' => true,
        );
        return $csm->get(wa()->getUser()->getId(), 'helpdesk') + $default_settings;
    }

}

