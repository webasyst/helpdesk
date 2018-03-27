<?php
/**
 * Single request page in backend.
 */
class helpdeskRequestsInfoAction extends helpdeskViewAction
{
    public $request_id;
    public $log_id = null;
    public $client = null;
    public $creator = null;
    public $is_admin = null;
    public $allowed_actions = array();
    public $request_info = array();
    public $is_unread = null;

    public $source = null;
    public $source_name = '';
    public $source_class = '';

    /** @var helpdeskRequest */
    public $request;

    public function execute()
    {
        $this->request_id = waRequest::request('id', 0, 'int');
        if (!$this->request_id) {

            // got log_id instead of rquest_id?
            $this->log_id = waRequest::request('log_id', 0, 'int');
            if(!$this->log_id) {
                throw new waException('No id given.');
            }

            $rlm = new helpdeskRequestLogModel();
            if (! ( $log = $rlm->getById($this->log_id))) {
                throw new waException('No such log record exists.', 404);
            }

            $this->request_id = $log['request_id'];
        }

        $this->request = new helpdeskRequest($this->request_id);

        // check access rights
        if (!$this->request->isVisibleForUser()) {
            throw new waRightsException(_w('Access denied.'));
        }

        $rm = new helpdeskRightsModel();

        // allowed workflow actions
        $allowed = null;
        if (!helpdeskRightsModel::isAllowed($this->request->workflow_id, '!action.all')) {
            $allowed = $rm->getAllowedActions($rm->getIdsByUser(), $this->request->workflow_id);
        }

        // Buttons to perform available actions
        $buttons = array();
        try {
            foreach ($this->request->getWorkflow()->getActions($this->request->state_id) as $action) {
                if (!$action->getOption('user_triggerable')) {
                    continue;
                }
                if ($allowed !== null && !isset($allowed[$action->getId()])) {
                    continue;
                }
                if ( ( $html = $action->getButton())) {
                    $this->allowed_actions[$action->getId()] = $action;
                    $buttons[] = $html;
                }
            }
        } catch (Exception $e) {
        }


        $client_contact_tabs_html = '';

        // client and creator contact info
        try {
            $this->client = $this->request->getClient();
            $contact_id = $this->client->getId();
            $client_contact_tabs_html = helpdeskHelper::getContactTabsHtml($contact_id);
        } catch (Exception $e) {
        }

        try {
            $this->creator = $this->request->getCreator();
            $size = 50;
            $creator_photo = $this->creator['photo'];
            if (!$creator_photo) {
                $creator_email = $this->creator->get('email', 'default');
                if ($creator_email) {
                    $creator_photo = helpdeskHelper::getGravatar($creator_email, $size);
                } else {
                    $creator_photo = helpdeskHelper::getGravatar('', $size);
                }
            } else {
                $creator_photo = $this->creator->getPhoto($size);
            }

            $this->creator["photo_url_50"] = $creator_photo;
        } catch (Exception $e) {
        }

        // Source name and class
        $this->source_name = $this->request->params->ifset('source_description');
        try {
            $this->source = $this->request->getSource();
            if (!$this->source->exists()) {
                $this->source = null;
            }
        } catch (Exception $e) {
        }
        if (empty($this->source_name)) {
            if ($this->source) {
                $this->source_name = $this->source['name'];
            } else {
                $this->source_name = _w('<unknown>');
            }
        }
        $this->request_info = $this->request->getInfo();

        if ($this->source) {
            $this->source_class = helpdeskHelper::getSourceClass($this->source, $this->request_info);
        }

        $this->is_admin = wa()->getUser()->getRights('helpdesk', 'backend') > 1;
        $this->request_log = $this->getLogs($this->request);

        // Mark request as read by this user
        $um = new helpdeskUnreadModel();
        $this->is_unread = $um->isUnread($this->request->id);
        if ($this->is_unread && wa()->getUser()->getSettings('helpdesk', 'mark_read_when_open')) {
            $um->read($this->request->id);
            $this->is_unread = false;
        }
        $this->unread_count = $um->countByContact();

        $fm = new helpdeskFollowModel();
        $has_follow = !!$fm->getByField(array(
            'contact_id' => wa()->getUser()->getId(),
            'request_id' => $this->request_id
        ));

        $rtm = new helpdeskRequestTagsModel();
        $tags = $rtm->getTags($this->request_id);

        $tm = new helpdeskTagModel();
        $all_tags = $tm->getAll('id');
        foreach ($tags as $t_id => $t) {
            $all_tags[$t_id]['checked'] = true;
        }

        $request_page_constructor = helpdeskRequestPageConstructor::getInstance();

        $this->view->assign('buttons', $buttons);
        $this->view->assign('client', $this->client);
        $this->view->assign('creator', $this->creator);
        $this->view->assign('is_admin', $this->is_admin);
        $this->view->assign('unread_count', $this->unread_count);
        $this->view->assign('source_name', $this->source_name);
        $this->view->assign('source_class', $this->source_class);
        $this->view->assign('is_unread', $this->is_unread);
        $this->view->assign('request', $this->request_info);
        $this->view->assign('log', $this->request_log);
        $this->view->assign('original_fields', $this->getFields($this->request, array(0, -1)));
        $this->view->assign('fields', $this->getFields($this->request, array(0, 1)));
        $this->view->assign('has_follow', $has_follow);
        $this->view->assign('tags', $tags);
        $this->view->assign('name_from_data', $this->request->getContactNameFromData());
        $this->view->assign('all_tags', $all_tags);
        $this->view->assign('client_contact_tabs_html', $client_contact_tabs_html);
        $this->view->assign('allowed_actions', $this->allowed_actions);
        $this->view->assign('left_fields', $request_page_constructor->getLeftFields());
        $this->view->assign('right_fields', $request_page_constructor->getRightFields());
        $create_tag_rights = $rm->getWorkflowsCreateTagRights();
        $this->view->assign('can_create_tag', !empty($create_tag_rights[$this->request->workflow_id]));

        $this->view->assign('can_load_contact_info', wa()->appExists('contacts'));
    }

    public function getFields(helpdeskRequest $request, $status)
    {
        $rdm = new helpdeskRequestDataModel();
        $fields = $rdm->getByRequest($request['id'], $status);
        return helpdeskRequestDataModel::formatFields($fields);
    }

    public function getLogs(helpdeskRequest $request)
    {
        $logs = $request->getLogs();

        foreach ($logs as &$log) {
            $log['fields'] = array();
            if (!empty($log['params'])) {
                $log['fields'] = helpdeskRequestLogParamsModel::formatFields(
                    helpdeskRequestLogParamsModel::filterByType(
                        $log['params'],
                        helpdeskRequestLogParamsModel::TYPE_REQUEST
                    )
                );
                // extract old_worflow and new_workflow names
                foreach (array('old_workflow', 'new_workflow') as $name) {
                    if (isset($log['params']["{$name}_id"])) {
                        $wf_id = $log['params']["{$name}_id"];
                        $log[$name] = $wf_id;
                        try {
                            $wf = helpdeskWorkflow::getWorkflow($wf_id);
                            $log[$name] = $wf->getName() . " (id = {$wf_id})";
                        } catch (Exception $e) {

                        }
                    }
                }
            }
        }
        unset($log);

        return $logs;
    }

}

