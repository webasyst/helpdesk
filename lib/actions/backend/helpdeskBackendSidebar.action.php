<?php
/**
 * Backend sidebar HTML to use in layout or request via XHR.
 */
class helpdeskBackendSidebarAction extends helpdeskViewAction
{
    // Data for 'sidebar' hook
    public $unread_count;
    public $wf_create;
    public $wf_view;
    public $history;
    public $assignments;
    public $workflows;
    public $states;

    public function execute()
    {
        // allowed workflows
        $rm = new helpdeskRightsModel();
        $this->wf_create = $wf_create = $rm->getWorkflowsCreationRights();
        $this->wf_view = $wf_view = $rm->getWorkflowsRequestsRights();

        // Assigning most of this stuff is probably overkill now.
        // It is there for historical reasons.
        $this->assignments = $this->getAssignments($wf_create, $wf_view);
        $this->source_data = $this->getSourceData($wf_create, $wf_view);
        $this->workflows = $this->getWorkflows($wf_create, $wf_view);

        // history
        $hm = new helpdeskHistoryModel();
        $this->history = $hm->get();

        // filters
        $fm = new helpdeskFilterModel();
        $this->personal_filters = $fm->getPersonal();
        $this->common_filters = $this->appSettings('disable_shared_filters') ? null : $fm->getCommon();

        // Number of unread requests
        $this->unread_count = null;
        $um = new helpdeskUnreadModel();
        if ($um->isEnabled()) {
            $this->unread_count = $um->countByContact();
        }

        $this->follow_count = null;
        $fm = new helpdeskFollowModel();
        $this->follow_count = $fm->countByContact();

        if (wa()->getUser()->isAdmin()) {
            $specials = helpdeskHelper::getSpecials();
            foreach ($this->personal_filters + $this->common_filters as $fltr) {
                if (isset($specials[$fltr['hash']])) {
                    unset($specials[$fltr['hash']]);
                }
            }
        }

        /**
         * @event sidebar
         * @return string custom HTML into sidebar
         */
        $this->view->assign('plugin_blocks', wa('helpdesk')->event('sidebar', $this));
        $this->expandSpecialFilters();

        $this->view->assign('admin', $this->getRights('backend') > 1);
        $this->view->assign('common_filters', $this->common_filters);
        $this->view->assign('personal_filters', $this->personal_filters);
        $this->view->assign('unread_count', $this->unread_count);
        $this->view->assign('follow_count', $this->follow_count);
        $this->view->assign('workflows', $this->workflows);
        $this->view->assign('history', $this->history);
        $this->view->assign('specials', ifset($specials));
        $this->view->assign('all_requests_hide', $this->appSettings('all_requests_hide'));
        $this->view->assign($this->source_data);

        $fcm = new helpdeskFaqCategoryModel();
        $this->view->assign('faq_categories', $fcm->getAll());

    }

    protected function expandSpecialFilters()
    {
        $specials = helpdeskHelper::getSpecials();
        $arrs = array(&$this->personal_filters, &$this->common_filters);
        foreach($arrs as &$arr) {
            foreach($arr as $id => &$f) {
                switch($f['hash']) {
                    case '@by_assignment':

                        $photos = array();
                        foreach(wao(new waContactsCollection('id/'.implode(',', array_keys($this->assignments))))->getContacts('id,photo') as $row) {
                            $photo[$row['id']] = $row['photo'];
                        }

                        $f['children'] = array();
                        $f['name'] = _w('Assignments');
                        foreach($this->assignments as $contact_id => $name) {
                            $ff = array(
                                'name' => $name,
                                'href' => "#/requests/assigned:".$contact_id,
                            );
                            if ($contact_id < 0) {
                                $ff['icon_class'] = 'contact';
                            } else if (!empty($photo[$contact_id])) {
                                $ff['icon_url'] = waContact::getPhotoUrl($contact_id, $photo[$contact_id], 20, 20);
                            } else {
                                $ff['icon_class'] = 'user';
                            }
                            $f['children'][] = $ff;
                        }
                        $f['children'][] = array(
                            'name' => '',
                            'href' => "#/requests/assigned:0",
                            'icon_class' => 'user-undefined',
                        );
                        break;
                    case '@by_sources':
                        $f['name'] = _w('Sources');
                        $f['children'] = array();
                        $deleted_sources = array();
                        foreach($this->source_data['allowed_sources'] as $source_id => $s) {
                            if ($s['status'] > 0) {
                                $f['children'][] = array(
                                    'name' => $s['name'],
                                    'href' => "#/requests/source:".$source_id,
                                    'icon_class' => 'source-'.$s['source_class'],
                                );
                            } else {
                                $deleted_sources[] = array(
                                    'name' => $s['name'],
                                    'href' => "#/requests/source:".$source_id,
                                    'icon_class' => 'source-'.$s['source_class'],
                                );
                            }
                        }
                        if ($deleted_sources) {
                            uasort($deleted_sources, array($this, 'nameCmp'));
                            $f['children'][] = array(
                                'id' => $f['id'].'-deleted',
                                'name' => _w(':: DELETED ::'),
                                'children' => $deleted_sources,
                            );
                        }
                        break;
                    case '@by_states':

                        $f['name'] = _w('Request states');
                        $f['children'] = array();
                        $workflow_states = helpdeskHelper::getAllWorkflowsWithStates();
                        foreach ($workflow_states as $workflow) {
                            if (!empty($workflow['states'])) {
                                $child = array(
                                    'name' => $workflow['name'],
                                    'children' => array()
                                );
                                foreach ($workflow['states'] as $state) {
                                    $child['children'][] = array(
                                        'name' => $state['name'] ? $state['name'] : _w('no status'),
                                        'href' => '#/requests/state:' . $workflow['id'] . '@' . $state['id'],
                                        'css' => !$state['deleted'] ? $state['css'] : 'font-style: italic;'
                                    );
                                }
                                $f['children'][] = $child;
                            }
                        }
                        if (count($workflow_states) == 1) {
                            $f['children'] = $f['children'][0]['children'];
                        }

                        break;

                    case '@by_tags':

                        $f['name'] = 'tags';
                        $f['hash'] = '@by_tags';
                        $tag_model = new helpdeskTagModel();
                        $f['cloud'] = $tag_model->getCloud();
                        break;

                    default:
                        $f['icon_class'] = $f['icon'] ? $f['icon'] : 'search';
                        break;
                }

                if ($f['hash'] && isset($specials[$f['hash']])) {
                    $f['system'] = true;
                }

            }
        }
        unset($arrs, $arr, $f);
    }

    protected function getAssignments($wf_create, $wf_view)
    {
        $sql = "SELECT DISTINCT r.assigned_contact_id AS id
                FROM helpdesk_request AS r";
        $cm = new waContactModel();

        $users = array();
        $groups = array();
        foreach($cm->query($sql) as $r) {
            if ($r['id'] > 0) {
                $users[] = $r['id'];
            } else if ($r['id'] < 0) {
                $groups[] = -$r['id'];
            }
        }

        $users = $cm->getName($users);
        //asort($users);

        $groups = wao(new waGroupModel())->getName($groups);
        //asort($groups);

        $assignments = $users;
        foreach($groups as $g_id => $g_name) {
            $assignments[-$g_id] = $g_name.' ('._w('group').')';
        }
        asort($assignments);

        return $assignments;
    }

    protected function getWorkflows($wf_create, $wf_view)
    {
        $workflows = array();
        foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
            $workflows[$wf_id] = $wf->getName();
        }
        asort($workflows);

        return $workflows;
    }

    protected function getSourceData($wf_create, $wf_view)
    {
        // form instances that can be used to add new request, id => name
        $forms_new_request = array();

        // sources for "additional view options"
        $allowed_sources = array();

        // fill $sources
        $sm = new helpdeskSourceModel();
        $all_sources = $sm->getAll(true); // all sources, including archived ones

        $wfs = helpdeskWorkflow::getWorkflows();
        $broken_ids = array();
        $workflow_source_errors = array(); // wf_id => bool
        foreach ($all_sources as $source_id => $source) {
            try {
                $s = helpdeskSource::get($source);
                $st = $s->getSourceType();
                $b = $s->describeBehaviour();
            } catch (Exception $e) {
                // Something is wrong, e.g. source type does not exist. Ignore this source.
                $broken_ids[] = $source_id;
                continue;
            }

            $source['source_class'] = helpdeskHelper::getSourceClass($s);

            // If user can see requests from this source then show filter
            if (!$b || array_intersect_key($wf_view, $b)) {
                $allowed_sources[$source_id] = $source;
            }

            if ($source['status'] >= 0) {
                // If user can use the backend form himself, show the link to add requests from this form
                if(count(array_intersect_key($wf_create, $b)) == count($b) && $st instanceof helpdeskFormSourceType && $st->isFormEnabled($s) && !empty($s->params['backend']))
                {
                    $forms_new_request[$source_id] = $source['name'];
                }

                if (!empty($s->params->error_datetime)) {
                    foreach(array_keys($b) as $wf_id) {
                        $workflow_source_errors[$wf_id] = true;
                    }
                }
            }
        }

        // Broken sources that still have links in helpdesk_request table
        $broken_ids = array_merge($broken_ids, $sm->getBrokenIds());
        foreach($broken_ids as $source_id) {
            $allowed_sources[$source_id] = array(
                'id' => $source_id,
                'type' => '',
                'name' => $source_id,
                'status' => -1,
                'params' => array(),
                'source_class' => 'unknown',
            );
        }

        uasort($allowed_sources, array($this, 'nameCmp'));

        return array(
            'workflow_source_errors', $workflow_source_errors,
            'forms_new_request' => $forms_new_request,
            'allowed_sources' => $allowed_sources,
            'backend_default_form' => helpdeskSourceHelper::isBackendSourceAvailable()
        );
    }

    // helper for getSourceData()
    public function nameCmp($a, $b)
    {
        return strcmp($a['name'], $b['name']);
    }
}

