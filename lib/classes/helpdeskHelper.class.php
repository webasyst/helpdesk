<?php
/**
 * Collection of static functions that didn't find their place anywhere else.
 */
class helpdeskHelper
{
    /** Save request and log to DB after WorkflowAction (or SourceType) finishes. */
    public static function saveRequestLog($r, $log)
    {
        self::prepareRequestLog($r, $log);

        // Save request and log
        $r->updated = date('Y-m-d H:i:s');
        $r->save();

        $log->request_id = $r->getId();
        $log->save();

        self::copyLogTextFiles($r, $log);

        // Mark request as unread for users
        $um = new helpdeskUnreadModel();
        $um->markUnread($r, $log);

        // Mark request as unread for following users
        $fm = new helpdeskFollowModel();
        $fm->makeUnread($r->id, true);


        // Mark request as read for person who performed an action
        if ($log->actor_contact_id > 0) {
            $um->read($log->request_id, $log->actor_contact_id);
        }

        /**
         * @event request_created
         *
         * Notify plugins about workflow action just performed on request.
         *
         * @param array[string]helpdeskRequest      $params['request']
         * @param array[string]helpdeskRequestLog   $params['log']
         */
        $params = array(
            'request' => $r,
            'log' => $log,
        );
        wa('helpdesk')->event('request_action', $params);

        return $log->id;
    }

    /**
     * @param helpdeskRequest $r
     * @param helpdeskRequestLog $log
     * Makes sure corresponding pieces of data in helpdeskRequest $r and helpdeskRequestLog $log are in sync:
     * - $r->state_id, $log->after_state_id and $log->before_state_id (also makes sure after_state_id exists)
     * - $log->assigned_contact_id and $r->assigned_contact_id
     * - $r->id and $log->request_id
     *
     * Does not write anything to DB.
     */
    public static function prepareRequestLog($r, $log)
    {
        // request id
        $r->getId() && $log->request_id = $r->getId();

        // state_id
        if (!isset($log->before_state_id) || $log->before_state_id === null) {
            $log->before_state_id = $r->state_id;
        }
        if (!isset($log->after_state_id) || $log->after_state_id === null) {
            $log->after_state_id = $log->before_state_id;
        }

        if ($log->after_state_id !== $log->before_state_id) {
            if (preg_match("/^(\d+):/", $log->after_state_id)) {
                $log->workflow_id = $r->workflow_id;
                list($r->workflow_id, $log->after_state_id) = explode(':', $log->after_state_id, 2);
            }

            $r->state_id = $log->after_state_id;
            try {
                $workflow = helpdeskWorkflow::getWorkflow($r->workflow_id);
                $state = $workflow->getStateById($r->state_id);
                if ($state->isClosed()) {
                    $r->closed = date('Y-m-d H:i:s');
                    $r->assigned_contact_id = 0;
                    $log->assigned_contact_id = 0;
                } else {
                    $r->closed = null;
                }
            } catch (waException $e) {
                // no such state
                $log->after_state_id = $r->state_id = $log->before_state_id;
            }
        }

        // assignment
        if ($log->assigned_contact_id !== null && $r->assigned_contact_id != $log->assigned_contact_id) {
            $r->assigned_contact_id = $log->assigned_contact_id;
        }

        if (!$log->workflow_id) {       // 0 or null
            $log->workflow_id = $r->workflow_id;
        }

    }

    public static function copyLogTextFiles($r, $log)
    {
        // Copy images mentioned in html code into new log's directory
        $url = wa()->getDataUrl('files/', true, 'helpdesk');
        $path = wa()->getDataPath('files/', true, 'helpdesk');

        $id = str_pad($r->id, 4, '0', STR_PAD_LEFT);
        $dir = wa()->getDataPath('files/requests', true, 'helpdesk').'/'.substr($id, -2).'/'.substr($id, -4, 2).'/'.$r->id;
        if ($log->id) {
            $dir .= '/log/'.$log->id;
        }
        $new_url = wa()->getDataUrl('files/requests', true, 'helpdesk').'/'.substr($id, -2).'/'.substr($id, -4, 2).'/'.$r->id;
        if ($log->id) {
            $new_url .= '/log/' . $log->id;
        }

        if (preg_match_all('~'.$url.'([^\)"\']+)~is', $log->text, $m)) {
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
                $log->text = str_replace($url.$old_file, $new_url.'/'.$new_file, $log->text);
            }

            $log->save();

        }
    }

    public static function getSourceIconUrl($source, $request = null)
    {
        return wa()->getAppStaticUrl().'img/source-'.self::getSourceClass($source, $request).'.png';
    }

    public static function getSourceIconColor($source, $request = null)
    {
        switch(self::getSourceClass($source, $request)) {
            case 'email':
                return '#3784d3';
            case 'backend':
                return '#000000';
            case 'my':
                return '#d8920a';
            case 'form':
                return '#17a208';
            default:
                return '#777'; // unknonwn
        }
    }

    public static function getSourceClass($source, $request = null)
    {
        if ($source->type == 'email') {
            return 'email';
        } elseif ($source->type == 'backend') {
            return 'backend';
        } elseif ($source->type == 'form') {
            if (ifset($request['creator_type']) == 'auth') {
                return 'my';
            } elseif (ifset($request['creator_type']) == 'backend') {
                return 'backend';
            }
            return 'form'; // $request['creator_type'] == 'public' and other
        }
        return 'unknown';
    }

    /**
     * Parse simple HTML $template_string, replace $vars and send to $address.
     *
     * Special {SEPARATOR} var is used to divide 'From', 'Subject' and 'Body' parts of a template.
     * Newlines are replaced with <br>s unless at least one <br> is found in body text.
     *
     * Example:
     *
     * WebAsyst <noreply@webasyst.net>
     * {SEPARATOR}
     * Sample email subject {SOME_VAR}
     * {SEPARATOR}
     * Sample email Body. Allows to use <u>HTML</u>.
     *
     * {SOME_VAR}
     *
     * --
     * Best regards, WebAsyst support team.
     *
     * @param string $address
     * @param string $template_string
     * @param array $vars key => value pairs to replace in template. By consideration, keys should be in "{SOMETHING}" form.
     * @throws waException if template does not contain two {SEPARATOR}s
     * @return true when mail sent successfully, false when waMailMessage->send() failed.
     */
    public static function sendEmailHtmlTemplate($address, $template_string, $vars, $attachments=array())
    {
        if (!$address) {
            return true;
        }

        // Load template and replace $vars
        $message = explode('{SEPARATOR}', $template_string);
        if(empty($message[2])) {
            $message[2] = ' ';
        }

        $from = trim($message[0]) ? trim($message[0]) : self::getDefaultFrom();
        $subject = trim($message[1]);
        $content = $message[2];

        $subject = helpdeskHelper::substituteVars($vars, $subject);
        $content = helpdeskHelper::substituteVars($vars, $content);

        foreach ($address as $k => $v) {
            // Send message
            try {
                $m = new waMailMessage(htmlspecialchars_decode($subject), $content);
                $m->setTo(array($k => $v))->setFrom($from);
                if ($attachments && is_array($attachments)) {
                    foreach($attachments as $file) {
                        $m->addAttachment($file['file'], $file['name']);
                    }
                }
                $sent = $m->send();
                $reason = 'waMailMessage->send() returned FALSE';
            } catch (Exception $e) {
                $sent = false;
                $reason = $e->getMessage();
            }
        }

        if (!$sent) {
            if (is_array($address)) {
                $address = var_export($address, true);
            }

            waLog::log('Unable to send email from '.$from.' to '.$address.' ('.$subject.'): '.$reason, 'helpdesk.log');
            return false;
        }

        return true;
    }
    /**
     * Parse simple HTML $template_string, replace $vars and push into queue to send to $address.
     *
     * Special {SEPARATOR} var is used to divide 'From', 'Subject' and 'Body' parts of a template.
     * Newlines are replaced with <br>s unless at least one <br> is found in body text.
     *
     * Example:
     *
     * WebAsyst <noreply@webasyst.net>
     * {SEPARATOR}
     * Sample email subject {SOME_VAR}
     * {SEPARATOR}
     * Sample email Body. Allows to use <u>HTML</u>.
     *
     * {SOME_VAR}
     *
     * --
     * Best regards, WebAsyst support team.
     *
     * @param string $address
     * @param string $template_string
     * @param array $vars key => value pairs to replace in template. By consideration, keys should be in "{SOMETHING}" form.
     * @param int $log_id
     * @throws waException if template does not contain two {SEPARATOR}s
     */
    public static function pushToSendEmailHtmlTemplate($address, $template_string, $vars, $log_id)
    {
        if (!$address) {
            return true;
        }

        // Load template and replace $vars
        $message = helpdeskHelper::substituteVars($vars, $template_string);
        $message = explode('{SEPARATOR}', $message);
        if(empty($message[2])) {
            $message[2] = ' ';
        }

        $from = trim($message[0]) ? trim($message[0]) : self::getDefaultFrom();
        $subject = trim($message[1]);
        $content = $message[2];

        $nl2br = true;
        foreach (array('<br', '</p>', '</div>', '</ul>', '</li>', '</table>') as $marker) {
            if (stripos($content, $marker)) {
                $nl2br = false;
                break;
            }
        }

        if ($nl2br) {
            $content = nl2br($content);
        }

        $queue_model = new helpdeskMessagesQueueModel();
        $queue_model->push(array(
            'subject' => $subject,
            'content' => $content,
            'log_id' => $log_id,
            'address' => $address,
            'from' => $from
        ));

        return true;
    }

    /**
     * "From:" email address to use when sending email conserning request from given source.
     * Used when other methods of obtaining "From:" address (such as from source settings or email template) fail.
     */
    public static function getDefaultFrom($source_or_request=null)
    {
        $source = null;
        if ($source_or_request && $source_or_request instanceof helpdeskRequest) {
            try {
                $source = $source_or_request->getSource();
            } catch (Exception $e) {
                // No valid source for some reason. Ignore.
            }
        } else if ($source_or_request && $source_or_request instanceof helpdeskSource) {
            $source = $source_or_request;
        }

        $from = waMail::getDefaultFrom();
        reset($from);
        $email = key($from);
        $name = current($from);

        // For email sources use source address
        try {
            if ($source && $source->getSourceType() instanceof helpdeskEmailSourceType) {
                $email = $source->params->ifset('email');
            }
        } catch (Exception $e) {
        }

        if ($email) {
            if ($name) {
                return $name.' <'.$email.'>';
            } else {
                return $email;
            }
        }

        return '';
    }

    public static function isCronOk()
    {
        return wa()->getSetting('last_cron_time', null, 'helpdesk') + 3600*2 > time();
    }

    public static function assignPagination($view, $start, $limit, $total_rows)
    {
        $pagination = array();
        $current_page = floor($start/$limit) + 1;
        $total_pages = floor(($total_rows-1)/$limit) + 1;
        $dots_added = false;
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i < 4) {
                $pagination[$i] = ($i-1)*$limit;
                $dots_added = false;
            } else if (abs($i-$current_page) < 3) {
                $pagination[$i] = ($i-1)*$limit;
                $dots_added = false;
            } else if ($total_pages - $i < 3) {
                $pagination[$i] = ($i-1)*$limit;
                $dots_added = false;
            } else if (!$dots_added) {
                $dots_added = true;
                $pagination[$i] = false;
            }
        }

        $view->assign('start', $start);
        $view->assign('total_rows', $total_rows);
        $view->assign('pagination', $pagination);
        $view->assign('current_page', $current_page);
    }

    public static function getAllStates()
    {
        $states = array();
        foreach(helpdeskWorkflow::getWorkflows() as $wf) {
            foreach($wf->getAllStates() as $state_id => $state) {
                if (empty($states[$state_id])) {
                    $states[$state_id] = array(
                        'name' => $state->getName(),
                        'id' => $state_id,
                        'css' => $state->getOption('list_row_css'),
                        'deleted' => false,
                        'workflow_ids' => array($wf->getId()),
                    );
                } else {
                    $states[$state_id]['workflow_ids'][] = $wf->getId();
                }
            }
        }
        asort($states);

        $deleted_states = array();
        $rows = wao(new waModel())->query("SELECT DISTINCT state_id FROM helpdesk_request ORDER BY state_id");
        foreach($rows as $row) {
            $state = null;
            $state_id = $row['state_id'];
            $state_name = $state_id;
            if ($wf) {
                try {
                    $state = $wf->getStateById($state_id);
                    $state_name = $state->getName();
                } catch (Exception $e) {
                }
            }
            $deleted_states[$state_id] = array(
                'name' => $state_name,
                'id' => $state_id,
                'css' => $state ? $state->getOption('list_row_css') : '',
                'deleted' => true,
                'workflow_ids' => array(),
            );
        }
        asort($deleted_states);

        return $states + $deleted_states;
    }

    /**
     *
     * @param boolean $all with deleted states (get from DB)
     * @return array
     */
    public static function getAllWorkflowsWithStates($all = true)
    {
        $workflows_and_states = array();
        if ($all) {
            $rows = wao(new waModel())->query("SELECT DISTINCT workflow_id, state_id FROM helpdesk_request ORDER BY workflow_id, state_id");
        } else {
            $rows = array();
        }
        foreach ($rows as $row) {
            if (!isset($workflows_and_states[$row['workflow_id']])) {
                $workflows_and_states[$row['workflow_id']] = array();
            }
            if (!isset($workflows_and_states[$row['workflow_id']][$row['state_id']])) {
                $workflows_and_states[$row['workflow_id']][$row['state_id']] = $row['state_id'];
            }
        }

        $workflows = array();
        foreach(helpdeskWorkflow::getWorkflows() as $wf) {
            $states = $wf->getAllStates();
            $workflow = array(
                'id' => $wf->getId(),
                'name' => $wf->getName(),
                'deleted' => false,
                'states' => array()
            );
            foreach ($states as $state_id => $state) {
                $workflow['states'][$state_id] = array(
                    'id' => $state_id,
                    'name' => $state->getName(),
                    'css' => $state->getOption('list_row_css'),
                    'deleted' => false,
                    'customer_portal_name' => $state->getOption('customer_portal_name')
                );
            }
            $workflows[$wf->getId()] = $workflow;
        }

        foreach ($workflows_and_states as $workflow_id => $state_ids) {
            if (!isset($workflows[$workflow_id])) {
                $workflows[$workflow_id] = array(
                    'id' => $workflow_id,
                    'name' => _w('Workflow') . ' ' . $workflow_id,
                    'deleted' => true,
                    'states' => array()
                );
                foreach ($state_ids as $state_id) {
                    $workflows[$workflow_id]['states'][$state_id] = array(
                        'id' => $state_id,
                        'name' => $state_id,
                        'css' => '',
                        'deleted' => true,
                        'customer_portal_name' => ''
                    );
                }
            } else {
                foreach ($state_ids as $state_id) {
                    if (!isset($workflows[$workflow_id]['states'][$state_id])) {
                        $workflows[$workflow_id]['states'][$state_id] = array(
                            'id' => $state_id,
                            'name' => $state_id,
                            'css' => '',
                            'deleted' => true,
                            'customer_portal_name' => ''
                        );
                    }
                }
            }
        }

        // filter by rights
        $rm = new helpdeskRightsModel();
        $helpdesk_backend_rights = wa()->getUser()->getRights('helpdesk', 'backend');
        if (!$helpdesk_backend_rights) {
            // access denied
            return array();
        } else if ($helpdesk_backend_rights <= 1) {
            $limited_rights = $rm->getWorkflowStatesRights(wa()->getUser()->getId());

            foreach ($workflows as $w_id => $workflow) {
                if (!isset($limited_rights[$w_id])) {
                    unset($workflows[$w_id]);
                } else if (empty($limited_rights[$w_id]['!state.all'])) {
                    foreach ($workflow['states'] as $state_id => $state) {
                        if (empty($limited_rights[$w_id][$state_id])) {
                            unset($workflows[$w_id]['states'][$state_id]);
                        }
                    }
                }
            }
        }

        return $workflows;
    }


    /**
     * List of workflows and sources that currently have unresolved errors.
     *
     * Returns two arrays: a list of workflow_ids, and array(source_id => source_name).
     */
    public static function getWorkflowsErrors()
    {
        $sources_errors = array();
        $workflows_errors = array();
        foreach (wao(new helpdeskSourceModel())->getWithError() as $s) {
            $sources_errors[$s['id']] = $s['name'];
            try {
                $workflows_errors += $s->describeBehaviour();
            } catch (Exception $e) {
            }
        }
        $workflows_errors = array_keys($workflows_errors);

        if (wa()->getStorage()->get('helpdesk_error_hidden')) {
            $sources_errors = array();
        }

        if (!$workflows_errors) {
            // clear header counts cache, if there used to be an error from helpdesk
            $appscount = wa()->getStorage()->read('apps-count');
            if (isset($appscount['helpdesk']) && $appscount['helpdesk'] === '!') {
                wa()->getStorage()->remove('apps-count');
            }
        }

        return array($workflows_errors, $sources_errors);
    }

    /**
     * List of backend users and groups with access to helpdesk app.
     * Positive contact_ids for users, negative group_ids for groups.
     * @return array id => name
     */
    public static function getAssignOptions($workflow_id = null)
    {
        // User and group ids who have access rights to given workflow
        if ($workflow_id) {
            $users = array();
            $groups = array();
            $rm = new helpdeskRightsModel();
            foreach($rm->getUserAndGroupsCanBeAssigned($workflow_id) as $id) {
                if ($id > 0) {
                    $users[] = $id;
                } else {
                    $groups[] = -$id;
                }
            }

            // filter off by assigned_to_request right
            //$rm->getWorkflowsAssignedToRequestsRights();

        } else {
            $crm = new waContactRightsModel();
            $users = $crm->getUsers('helpdesk');
            $groups = array_keys($crm->getAllowedGroups('helpdesk', 'backend'));
        }

        // User id => user name
        $cm = new waContactModel();
        $user_names = $cm->getName($users);
        asort($user_names);

        // Group id => group name
        $gm = new waGroupModel();
        $group_names = $gm->getName($groups);
        asort($group_names);
        foreach($group_names as $gid => $gname) {
            $group_names[-$gid] = _w('Group:').' '.$gname;
            unset($group_names[$gid]);
        }

        return $group_names + $user_names;
    }

    /** @return string|null plugin id given class is loaded from */
    public static function getPluginByClass($class)
    {
        $file = waAutoload::getInstance()->get($class);
        $file = str_replace('\\', '/', $file);
        if (!preg_match('~/helpdesk/plugins/([^/]+)/~', $file, $m)) {
            return null;
        }
        return $m[1];
    }

    public static function loadPluginLocale($plugin)
    {
        if ($plugin) {
            $path = wa('helpdesk')->getAppPath('plugins/'.$plugin.'/locale');
            if (is_dir($path)) {
                waLocale::load(wa('helpdesk')->getLocale(), $path, waSystem::getActiveLocaleDomain(), false);
            }
        }
    }

    /**
     * Value for {ASSIGNED_NAME} email template var.
     */
    public static function getAssignedString($assigned_contact_id)
    {
        $assigned_string = '';
        if ($assigned_contact_id > 0) {
            try {
                $c = new waContact($assigned_contact_id);
                $assigned_string = $c->getName();
            } catch (Exception $e) {
                // No such contact: ignore.
            }
        } else if ($assigned_contact_id < 0) {
            $name = wao(new waGroupModel())->getName(-$assigned_contact_id);
            if ($name) {
                $assigned_string = $name.' ('._w('group').')';
            }
        }

        return $assigned_string;
    }

    public static function getIcons()
    {
        return array(
            'folder',
            'notebook',
            'lock',
            'lock-unlocked',
            'broom',
            'star',
            'livejournal',
            'contact',
            'lightning',
            'light-bulb',
            'pictures',
            'reports',
            'books',
            'marker',
            'lens',
            'alarm-clock',
            'animal-monkey',
            'anchor',
            'bean',
            'car',
            'disk',
            'cookie',
            'burn',
            'clapperboard',
            'bug',
            'clock',
            'cup',
            'home',
            'fruit',
            'luggage',
            'guitar',
            'smiley',
            'sport-soccer',
            'target',
            'medal',
            'phone',
            'search',
            'store',
            'basket',
            'pencil',
            'lifebuoy',
            'screen',
        );
    }

    /**
     * Returns Gravatar URL for specified email address.
     * @see http://gravatar.com/site/implement/images/php/
     *
     * @param string $email Email address
     * @param int $size Size in pixels, defaults to 50
     * @param string $default Default image set to use. Available image sets: 'custom', '404', 'mm', 'identicon', 'monsterid', 'wavatar'.
     * @return string
     */
    public static function getGravatar($email, $size = 50, $default = 'mm')
    {
        if ($default == 'custom') {
            $default = wa()->getRootUrl(true).'wa-content/img/userpic'.$size.'.jpg';
            $default = urlencode($default);
        }
        return '//www.gravatar.com/avatar/'.md5(strtolower(trim($email)))."?size=$size&default=$default";
    }

    public static function getSpecials()
    {
        static $names = null;
        $names || $names = array(
            '@by_sources' => _w('Sources'),
            '@by_assignment' => _w('Assignments'),
            '@by_states' => _w('Request states'),
            '@by_tags' => _w('Tags')
        );
        return $names;
    }

    /**
     *
     * @param int $id
     * @param array $params
     * @param int $params_mix_mode 0 - rewrite params, 1 - array_merge params
     * @param array[string] string $extra_html extra html fragments. Possible keys 'bottom', 'top'
     * @return string
     */
    public static function form($id, $params = array(), $params_mix_mode = 0,  $extra_html = array())
    {
        try {
            $source = helpdeskSource::get($id);
            $st = $source->getSourceType();
        } catch (Exception $e) {
            return '';
        }

        if (!$st instanceof helpdeskFormSTInterface) {
            return '';
        }

        $old_app = wa()->getApp();
        wa('helpdesk', true);
         if ($params) {
            foreach ($params as $key => $val) {
                switch ($params_mix_mode) {
                    case 1:
                        if (is_array($val)) {
                            foreach ($val as $k => $v) {
                                $source->params[$key][$k] = $v;
                            }
                        } else {
                            $source->params[$key] = $val;
                        }
                        break;
                    case 0:
                    default:
                        $source->params[$key] = $val;
                        break;
                }
             }
         }
        $result = $source->getSourceType()->getFormHtml($source, $extra_html);
        wa($old_app, true);
        return $result;
    }

    public static function getFormFields($id, $env = null)
    {
        try {
            $source = helpdeskSource::get($id);
            $st = $source->getSourceType();
        } catch (Exception $e) {
            return array();
        }
        if (!($st instanceof helpdeskFormSourceType)) {
            return array();
        }

        $env = $env !== null ? $env : wa()->getEnv();

        $form_fields = array();
        $form_constructor = new helpdeskFormConstructor();
        $fields = $form_constructor->getFields($source, $env);
        foreach ($fields as $field_id => $field_opt) {
            if (!empty($field_opt['choosen'])) {
                $form_fields[$field_id] = $field_opt;
            }
        }
        return $form_fields;
    }

    public static function getFormContactFields($id, $env = null)
    {
        try {
            $source = helpdeskSource::get($id);
            $st = $source->getSourceType();
        } catch (Exception $e) {
            return array();
        }
        if (!($st instanceof helpdeskFormSourceType)) {
            return array();
        }

        $env = $env !== null ? $env : wa()->getEnv();

        $form_fields = array();
        $form_constructor = new helpdeskFormConstructor();
        $fields = $form_constructor->getContactFields($source, $env);
        foreach ($fields as $field_id => $field_opt) {
            if (!empty($field_opt['choosen'])) {
                $form_fields[$field_id] = $field_opt;
            }
        }
        return $form_fields;
    }


    public static function transliterate($slug)
    {
        $slug = preg_replace('/\s+/', '-', $slug);
        if ($slug) {
            foreach (waLocale::getAll() as $lang) {
                $slug = waLocale::transliterate($slug, $lang);
            }
        }
        $slug = preg_replace('/[^a-zA-Z0-9_-]+/', '', $slug);
        if (!$slug) {
            $slug = date('Ymd');
        }
        return strtolower($slug);
    }

    public static function logAction($action, $params = null, $subject_contact_id = null, $contact_id = null)
    {
        $old_app = wa()->getApp();
        wa()->setActive('helpdesk');

        if (!class_exists('waLogModel')) {
            wa('webasyst');
        }
        $log_model = new waLogModel();
        $res = $log_model->add($action, $params, $subject_contact_id, $contact_id);

        wa()->setActive($old_app);

        return $res;
    }

    public static function rightsToAppMessage()
    {
        if (wa()->appExists('team')) {
            return sprintf(_w('Select a user or a user group who have access to Helpdesk app. Manage users and user groups, and their access rights in <a href="%s">Team</a> app.'), wa()->getAppUrl('team'));
        } else {
            return '';
        }
    }

    public static function rightsToAppMessageParagraph($class = '', $style='')
    {
        $text = self::rightsToAppMessage();
        if ($text) {
            return "<p class='{$class}' style='{$style}'>" . $text . '</p>';
        }
        return $text;
    }

    public static function getVars($var_keys = null)
    {
        $all_vars = array (
            '{REQUEST_ID}' => _w('Unique identifier automatically assigned to each request (e.g. #1001)'),
            '{REQUEST_SUBJECT}' => _w('Subject of the request'),
            '{REQUEST_SUBJECT_WITH_ID}' => _w('Request subject followed by the request ID (e.g. Request subject [ID: 1001-XXXXX]). The request ID and hash will associate any reply you receive from the customer with the original request.'),
            '{REQUEST_TEXT}' => _w('Text of the original request'),
            '{ACTION_TEXT}' => _w('Text entered in this action'),
            '{REQUEST_STATUS}' => _w('Request status (its current state) displayed in the backend'),
            '{REQUEST_STATUS_CUSTOMER}' => _w('Request status (its current state) that the customer sees in Customer Portal'),
            '{REQUEST_HISTORY}' => _w('Full history of request, including the original message. Similar to the request view in backend.'),
            '{REQUEST_HISTORY_CUSTOMER}' => _w('History of request as seen by the customer. Similar to the request view in Customer Portal.'),
            '{REQUEST_BACKEND_URL}' => _w('URL of the request page in the backend'),
            '{REQUEST_CUSTOMER_PORTAL_URL}' => _w('URL of the request page in the Customer Portal'),
            '{REQUEST_CUSTOMER_CONTACT_ID}' => _w('Unique identifier automatically assigned to each customer in Contacts'),
            '{REQUEST_CUSTOMER_EMAIL}' => _w('Primary email for customer'),
            '{CUSTOMER_NAME}' => _w('Name of customer in the format First Middle Last'),
            '{ACTOR_NAME}' => _w('Full name of person who has performed this action'),
            '{ASSIGNED_NAME}' => _w('Name of user or group assigned to the request'),
            '{COMPANY_NAME}' => _w('Company name specified in your Installer settings (also displayed in the top-left corner of your backend)'),
            '{REQUEST_CONFIRM_URL}' => _w('URL address for not registered clients to confirm their original email. Click on this link opens the request in the client\'s Customer Portal.'),
        );
        if ($var_keys === null) {
            return $all_vars;
        } else if (is_array($var_keys)) {
            $vars = array();
            foreach ($var_keys as $k) {
                if (isset($all_vars[$k])) {
                    $vars[$k] = $all_vars[$k];
                }
            }
            return $vars;
        } else if (isset($all_vars[$var_keys])) {
            return $all_vars[$var_keys];
        } else {
            return $all_vars;
        }
    }

    public static function categorizeVars($vars)
    {
        $categories = array(
            'request' => array(
                'name' => _w('Request fields'),
                'vars' => array()
            ),
            'customer' => array(
                'name' => _w('Customer contact fields'),
                'vars' => array()
            ),
            'actor' => array(
                'name' => _w('User contact fields'),
                'vars' => array()
            ),
            'assignee' => array(
                'name' => _w('Assignee contact fields'),
                'vars' => array()
            ),
            'common' => array(
                'name' => _w('Common fields'),
                'vars' => array()
            )
        );
        $request_vars = helpdeskRequestFields::getFieldsVars();
        foreach ($vars as $var_name => $var_description) {
            if (substr($var_name, 0, 9) === '{REQUEST_' || isset($request_vars[$var_name]) || $var_name === '{ACTION_TEXT}') {
                $categories['request']['vars'][$var_name] = $var_description;
                unset($vars[$var_name]);
            } else if (substr($var_name, 0, 10) === '{CUSTOMER_') {
                $categories['customer']['vars'][$var_name] = $var_description;
                unset($vars[$var_name]);
            } else if (substr($var_name, 0, 7) === '{ACTOR_') {
                $categories['actor']['vars'][$var_name] = $var_description;
                unset($vars[$var_name]);
            } else if (substr($var_name, 0, 10) === '{ASSIGNED_') {
                $categories['assignee']['vars'][$var_name] = $var_description;
                unset($vars[$var_name]);
            } else {
                $categories['common']['vars'][$var_name] = $var_description;
                unset($vars[$var_name]);
            }
        }
        return $categories;
    }

    public static function getContactTabsHtml($id)
    {
        $client_contact_tabs = array();

        $contact = new waContact($id);
        if ($contact->exists()) {

            // Does user have access rights to view profile in Contacts?
            wa('contacts');
            $cr = new contactsRightsModel();
            $has_access = $cr->getRight(null, $id);
            if ($has_access) {
                $links = array();
                foreach (wa('contacts')->event('profile.tab', $id) as $app_id => $one_or_more_links) {
                    if (!isset($one_or_more_links['html'])) {
                        $i = '';
                        foreach ($one_or_more_links as $link) {
                            $key = isset($link['id']) ? $link['id'] : $app_id.$i;
                            $links[$key] = $link;
                            $i++;
                        }
                    } else {
                        $key = isset($one_or_more_links['id']) ? $one_or_more_links['id'] : $app_id;
                        $links[$key] = $one_or_more_links;
                    }
                }

                $backend_url = wa()->getConfig()->getBackendUrl(true);

                $client_contact_tabs[] = array(
                    'id' => 'user',
                    'css_class' => 'user',
                    'href' => htmlspecialchars($backend_url.'contacts/#/contact/'.$id.'/user/'),
                    'name' => contactsHelper::getAccessTabTitle($contact)
                );

                // Format assets info
                foreach ($links as $l_id => $l) {
                    $link_hash = isset($l['hash']) && strlen($l['hash']) ? $l['hash'] : $l_id;
                    $client_contact_tabs[] = array(
                        'id' => $l_id,
                        'css_class' => htmlspecialchars($link_hash),
                        'href' => htmlspecialchars($backend_url.'contacts/#/contact/'.$id.'/'.htmlspecialchars($link_hash).'/'),
                        'name' => $l['title'],
                    );
                }
            }
        }

        $html = '';
        if ($client_contact_tabs) {
            $html = '<div class="contact-tabs"><div class="links">';
            foreach ($client_contact_tabs as $tab) {
                $html .= "<a href='{$tab['href']}' class='{$tab['css_class']}' data-contact-id='{$id}' data-tab-id='{$tab['id']}'>{$tab['name']}</a>";
            }
            $html .= '</div></div>';
        }

        return $html;
    }

    public static function getContactFieldsVars($types = 'all')
    {
        if ($types === 'all') {
            $types = array('CUSTOMER', 'ACTOR', 'ASSIGNED');
        } else {
            $types = (array) $types;
            foreach ($types as &$t) {
                $t = strtoupper($t);
            }
            unset($t);
        }

        $fields = waContactFields::getAll();
        $vars = array();
        foreach ($types as $prefix) {
            $prefix = $prefix . '_';
            foreach ($fields as $field_id => $field) {
                $str = '';
                if ($prefix === 'CUSTOMER_') {
                    $str = _w('Value of customer field "%s"');
                } elseif ($prefix === 'ACTOR_') {
                    $str = _w('Value of actor field "%s"');
                } else {
                    $str = _w('Value of assigned user field "%s"');
                }
                $vars['{' . $prefix . strtoupper($field_id) . '}'] = sprintf($str, $field->getName());
            }
        }
        return $vars;
    }

    public static function substituteVars($vars, $message, $trim = true)
    {
        if (isset($vars['{ACTION_TEXT}'])) {
            $vars['{ACTION_TEXT}'] = str_replace(array_keys($vars), array_values($vars), $vars['{ACTION_TEXT}']);
            $vars['{ACTION_TEXT}'] = preg_replace('/\$\{.*?\}/', '', $vars['{ACTION_TEXT}']);
        }
        $m = str_replace(array_keys($vars), array_values($vars), $message);
        //$m = preg_replace('/\$\{.*?\}/', '', $m);
        return $trim ? trim($m) : $m;
    }

    public static function getFaqMarkHtml($type)
    {
        if (is_array($type)) {
            $html = array();
            foreach ($type as $t) {
                $html[] = self::getFaqMarkHtml($t);
            }
            return implode(PHP_EOL, $html);
        } else {
            switch ($type) {
                case 'draft':
                    return '<span class="h-faq-draft">' . _w('draft') . '</span>';
                case 'site_only':
                    return '<span class="h-faq-site-only">' . _w('site') . '</span>';
                case 'backend_only':
                    return '<span class="h-faq-backend-only">' . _w('backend') . '</span>';
                case 'backend_and_site':
                    return '<span class="h-faq-backend-only">' . _w('backend') . '</span>' .
                                '<span>+</span><span class="h-faq-site-only">' . _w('site') . '</span>';
                default:
                    return '';
            }
            return '';
        }
    }

    public static function arrayMerge($ar1, $ar2, $skip_empty = true)
    {
        $res = $ar1;
        foreach ($ar2 as $k => $v) {
            if (!empty($v) || !$skip_empty) {
                $res[$k] = $v;
            }
        }
        return $res;
    }

}

