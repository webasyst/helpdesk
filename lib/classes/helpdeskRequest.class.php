<?php
/**
 * Instances of this class represent requests. See helpdeskRequestRecord class for details.
 *
 * This file contains glue code between model (helpdeskRequestRecord) and templates.
 * Static functions are general helper functions related to requests. Non-static functions
 * are kinda ViewModel in MVVM pattern.
 *
 * Also serves as an ORM class for helpdesk_request + helpdesk_request_params tables.
 * See helpdeskRequestRecord for details.
 *
 * @property id|null $id ID
 * @property int|null $client_contact_id Client ID
 * @property int|null $creator_contact_id Creator ID
 * @property int|null $assigned_contact_id Assigned contact or group ID. Groupd ID < 0
 * @prorerty int|null $workflow_id Workflow ID
 * @property string $state_id State ID
 * @property string|null $creator_type
 * @property string created When created datetime
 *
 */
class helpdeskRequest extends helpdeskRequestRecord
{
    //
    // Static functions
    //

    /**
     * Format data, append additional fields etc. to use in templates in list view.
     * @param array $requests list of db rows as returned by requestsCollection->getRequests()
     */
    public static function prepareRequests($requests)
    {
        $sm = new helpdeskSourceModel();

        $wfs = array();
        $sources = array();
        foreach($sm->getAll(true) as $s) {
            $sources[$s['id']] = helpdeskSource::get($s);
        }

        foreach ($requests as &$request) {

            // Format time: only show hh:mm for today's requests
            $request['ts'] = strtotime($request['created']);
            $request['age'] = self::getAge(time() - $request['ts']);
            $request['dt_formatted'] = self::formatListDate($request['ts']);

            // updated
            $request['upd_ts'] = strtotime($request['updated']);
            $request['upd_formatted'] = self::formatListDate($request['upd_ts']);
            $request['time_since_update'] = self::getAge(time() - $request['upd_ts']);

            // Closed
            if (!$request['closed'] || $request['closed'] == '0000-00-00 00:00:00') {
                $request['closed'] = false;
            }

            // workflow
            if (!isset($wf[$request['workflow_id']])) {
                try {
                    $wfs[$request['workflow_id']] = helpdeskWorkflow::getWorkflow($request['workflow_id']);
                } catch (Exception $e) {
                    $wfs[$request['workflow_id']] = false;
                }
            }

            $request['list_row_css'] = '';
            $request['workflow'] = $request['workflow_id'];
            $request['state'] = $request['state_id'];
            if ($wfs[$request['workflow_id']]) {
                $request['workflow'] = $wfs[$request['workflow_id']]->getName();
                try {
                    $state = $wfs[$request['workflow_id']]->getStateById($request['state_id']);
                    $request['state'] = $state->getName();
                    $request['list_row_css'] = $state->getOption('list_row_css');
                } catch (Exception $e) { }
                try {
                    $request['last_action_performs_string'] = helpdeskWorkflow::get($request['workflow_id'])->getActionById($request['last_action_id'])->getPerformsActionString();
                } catch (Exception $e) { }
            }
            if (empty($request['last_action_performs_string'])) {
                $request['last_action_performs_string'] = self::getPerformsActionStringSpecial($request['last_action_id']);
            }

            $request['actor_name'] = htmlspecialchars($request['actor_name']);
            $request['client_name'] = $request['client_name'];
            $request['assigned_name'] = htmlspecialchars($request['assigned_name']);
            if ($request['assigned_contact_id'] < 0) {
                $request['assigned_name'] .= ' <span class="assigned-group-marker">('._w('group').')</span>';
            }
            $request['summary'] = htmlspecialchars($request['summary'], ENT_QUOTES);
            if ($request['client_contact_id'] && $request['client_photo_ts']) {
                $request['client_photo_url'] = waContact::getPhotoUrl($request['client_contact_id'], $request['client_photo_ts'], 50);
            } else {
                if (!empty($request['client_email'])) {
                    $request['client_photo_url'] = helpdeskHelper::getGravatar($request['client_email']);
                } else {
                    $request['client_photo_url'] = helpdeskHelper::getGravatar('');
                    //$request['client_photo_url'] = wa()->getRootUrl().'wa-content/img/userpic50.jpg';
                }
            }

//            $request['text_start'] = '';
//            if (0 < ( $text_start_length = 200 - mb_strlen($request['summary']))) {
//                $request['text_start'] = strip_tags(self::br2nl($request['text']));
//                $request['text_start'] = preg_replace('~(&nbsp;|\s| )+~u', ' ', $request['text_start']); // there's a hardcoded Alt+0160 (0xA0) character representing UTF-8 non-breakable space
//                if (mb_strlen($request['text_start']) > $text_start_length) {
//                    $request['text_start'] = mb_substr($request['text_start'], 0, $text_start_length).'...';
//                }
//                $request['text_start'] = htmlspecialchars($request['text_start'], ENT_QUOTES, 'UTF-8');
//            }

            $request['text_clean'] = strip_tags(self::br2nl($request['text']));
            unset($request['text']);

            $request['source_class'] = '';
            $request['source_name'] = '';
            if (!empty($request['source_id']) && !empty($sources[$request['source_id']])) {
                $request['source_class'] = helpdeskHelper::getSourceClass($sources[$request['source_id']], $request);
                $request['source_name'] = htmlspecialchars($sources[$request['source_id']]->offsetGet('name'));
            }

            // do not allow too long words in summary or text start
            $request['text_clean'] = trim(self::breakWords($request['text_clean'], 30));
            $request['summary'] = trim(self::breakWords($request['summary'], 30));
        }

        return $requests;
    }

    public static function getPerformsActionStringSpecial($action_id)
    {
        $name = self::getSpecialActionName($action_id);
        if (in_array($action_id, array(
            '!bulk_change_assigned_contact_id',
            '!bulk_change_state_id',
            '!one_change_summary',
            '!email',
            helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID
        )))
        {
            return _w('performs action').' <span style="color:black">'.$name.'</span>';
        } else if ($action_id && $action_id[0] == '!') {
            return sprintf_wp('performs bulk operation “%s”', $name);
        } else {
            return _w('performs action').' '.$name;
        }
    }

    public static function getSpecialActionName($action_id)
    {
        switch($action_id) {
            case '!bulk_change_assigned_contact_id':
                return _w('Change assignment');
            case '!bulk_change_state_id':
                return _w('Change state');
            case '!one_change_summary':
                return _w('Edit subject');
            case '!email':
                return _w('Reply by email');
            case helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID:
                return helpdeskOneClickFeedback::getRequestLogActionName();
            default:
                if ($action_id && $action_id[0] == '!') {
                    return $action_id;
                } else {
                    return htmlspecialchars($action_id);
                }
        }
    }

    /** Insert space every $width characters inside long words in $string. */
    public static function breakWords($string, $width=30)
    {
        $result = '';
        foreach(explode(' ', $string) as $word) {
            while (mb_strlen($word) > $width) {
                $result .= ' ';
                $result .= mb_substr($word, 0, $width);
                $word = mb_substr($word, $width);
            }
            $result .= ' ';
            $result .= $word;
        }
        return $result;
    }

    /** self::prepareRequests() helper. Reversed nl2br(). */
    public static function br2nl($string)
    {
        return preg_replace('~<br\s*/?\s*>~u', "\n", $string);
    }

    public static function formatListDate($dt)
    {
        if(!wa_is_int($dt)) {
            $ts = strtotime($dt);
        } else {
            $ts = $dt;
            $dt = date('Y-m-d H:i:s', $ts);
        }

        if (date('Y-m-d', $ts) == date('Y-m-d')) {
            return waDateTime::format('time', $dt, wa()->getUser()->getTimezone());
        } else if (date('Y', $ts) == date('Y')) {
            $date = wa_date('humandate', $ts);
            $info = waLocale::getInfo(wa()->getLocale());
            if (!$info || empty($info['date_formats']['humandate'])) {
                return date('j ', $ts).waDateTime::format('F', $dt, wa()->getUser()->getTimezone());
            } else {
                $format = trim(trim(str_replace('Y', '', $info['date_formats']['humandate'])), ',.');
                return waDateTime::date($format, $ts);
            }
            return $date;
        } else {
            return waDateTime::format('date', $dt, wa()->getUser()->getTimezone());
        }
    }

    /**
     * Make human-readable time difference string from number of seconds.
     * Used in request list.
     */
    public static function getAge($fullseconds)
    {
        if ($fullseconds > 3600*24*360) {
            $years = floor($fullseconds / (3600*24*360));
            $string = $years.' '._ws('year', 'years', $years);
            $fullseconds_more = $fullseconds - $years*3600*24*360;
            if ($fullseconds_more > 3600*24) {
                $string .= ' '.self::getAge($fullseconds_more);
            }
            return $string;
        } else if ($fullseconds > 3600*24*30) {
            $months = floor($fullseconds / (3600*24*30));
            $string = $months.' '._ws('month', 'months', $months);
            $fullseconds_more = $fullseconds - $months*3600*24*30;
            if ($fullseconds_more > 3600*24) {
                $string .= ' '.self::getAge($fullseconds_more);
            }
            return $string;
        } else if ($fullseconds > 3600*24) {
            $days = floor($fullseconds / (3600*24));
            return $days.' '._ws('day', 'days', $days);
        } else if ($fullseconds > 3600) {
            $hours = floor($fullseconds / 3600);
            return $hours.' '._ws('hour', 'hours', $hours);
        } else {
            return _w('<1 hour');
        }
    }

    /**
     * Make human-readable time difference string from number of seconds.
     * Used in request history to indicate intervals between actions.
     */
    public static function getDatetimeBySeconds($fullseconds)
    {
        if($fullseconds < 60) {
            return sprintf(_w('%ds'), $fullseconds);
        } elseif($fullseconds < 60 * 60) {
            return sprintf(_w('%dm'), round(($fullseconds) / 60));
        } else {
            $minutes = round(($fullseconds / 60) % 60);
            $hours = round(($fullseconds / (60*60)) % 24);
            $days = round(($fullseconds / (60*60*24)) % 31);
            $months = round(($fullseconds / (60*60*24*31)) % 12);
            $years = round(($fullseconds / (60*60*24*31*12)));

            if($fullseconds < 60 * 60 * 24) {
                return  sprintf(_w('%dh %dm'), $hours, $minutes );
            } elseif($fullseconds < 60 * 60 * 24 * 7)  {
                return sprintf(_w('%dd %dh'), $days, $hours);
            } elseif($fullseconds < 60 * 60 * 24 * 31) {
                return sprintf(_w('%dd'), $days);
            } elseif($fullseconds < 60 * 60 * 24 * 365) {
                return sprintf(_w('%dm %dd'), $months, $days);
            } else {
                $yearDays = round(($fullseconds / (60*60*24)) % 365);
                return sprintf(_w('%dy %dd'), $years, $yearDays);
            }
        }
    }

    /**
      * URL to download previously saved attachemnt.
      * When caled with three parameters (int request_id, int log_id, string attach_id), looks for log attachment.
      * When caled with two parameters (int request_id, string attach_id), looks for request attachment.
      * @return string URL to download attachment from
      */
    public static function getAttachmentUrl($request_id, $log_id, $attach_id=null)
    {
        if ($attach_id === null) {
            $attach_id = $log_id;
            $log_id = null;
        }

        $params = "r={$request_id}&a={$attach_id}".($log_id ? '&l='.$log_id : '');

        if (wa()->getEnv() == 'backend') {
            return wa('helpdesk')->getUrl(true)."?action=attach&{$params}";
        } else {
            $domain = wa()->getConfig()->getDomain();
            return wa()->getRouteUrl('helpdesk/frontend/myAttachment', array(), true, $domain)."?{$params}";
        }
    }

    /**
      * URL to download previously saved attachemnt.
      * When caled with three parameters (int request_id, int log_id, string filename), looks for log attachment.
      * When caled with two parameters (int request_id, string filename), looks for request attachment.
      * @return string URL to download attachment from
      */
    public static function getAssetUrl($request_id, $log_id, $filename=null)
    {
        if ($filename === null) {
            $filename = $log_id;
            $log_id = null;
        }

        $params = "r={$request_id}&a={$filename}".($log_id ? '&l='.$log_id : '');
        return wa('helpdesk')->getUrl()."?action=asset&{$params}";
    }

    /**
      * Full path to directory where attachments are saved.
      * @param int $request_id
      * @param int $log_id optional; when missing then request's attachment dir is returned.
      * @return string full path (no trailing slash)
      */
    public static function getAttachmentsDir($request_id, $log_id=null)
    {
        return self::getAssetsDir($request_id, $log_id).'/files';
    }

    /**
      * Full path to directory where assets are saved.
      * @param int $request_id
      * @param int $log_id optional; when missing then request's asset dir is returned.
      * @return string full path (no trailing slash)
      */
    public static function getAssetsDir($request_id, $log_id=null)
    {
        $id = str_pad($request_id, 4, '0', STR_PAD_LEFT);
        $dir = wa('helpdesk')->getDataPath('requests', false).'/'.substr($id, -2).'/'.substr($id, -4, 2).'/'.$request_id;
        if ($log_id) {
            $dir .= '/log/'.$log_id;
        }
        return $dir;
    }

    /**
      * Prepare text for display on a page.
      *
      * @param array $entity row from _request or _request_log table (including attachments)
      * @return string HTML string ready to insert into template
      */
    public static function formatHTML($entity)
    {
        $text = $entity['text'];
        $text = helpdeskHtmlSanitizer::work($text);
        return $text;
    }

    public static function stripBlockquotes($text)
    {
        // Strip blockquotes (>)
        while (preg_match("!(\r?\n\s?(&gt;|>)[^\r\n]*)!uis", $text)) {
            $text = preg_replace("!(\r?\n\s?(&gt;|>)[^\r\n]*)!uis", '', $text);
        }
        while (preg_match('!</blockquote>[\s\r\t\n]*(<br ?/?>)?[\s\r\t\n]*<blockquote[^>]*>!uis', $text)) {
            $text = preg_replace('!</blockquote>[\s\r\t\n]*(<br ?/?>)?[\s\r\t\n]*<blockquote[^>]*>!uis', '', $text);
        }
        return self::stripBlockquoteTags($text);
    }

    public static function stripBlockquoteTags($text)
    {
        $parts1 = preg_split('!(<blockquote).*?>!', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (empty($parts1)) {
            return $text;
        }
        $blocks = array();
        foreach ($parts1 as $part) {
            if ($part !== '<blockquote') {
                $parts2 = preg_split('!(</blockquote)\s*>!', $part, -1, PREG_SPLIT_DELIM_CAPTURE);
                $blocks = array_merge($blocks, $parts2);
            } else {
                $blocks[] = $part;
            }
        }
        $counter = 0;
        foreach ($blocks as $block) {
            if ($block === '<blockquote') $counter += 1;
            if ($block === '</blockquote') $counter -= 1;
        }

        if ($counter < 0) {
            $len = count($blocks);
            for ($i = $len - 1; $i >= 0; $i -= 1) {
                if ($blocks[$i] === '</blockquote') {
                    $blocks[$i] = '<blockquote|</blockquote';
                    $counter += 1;
                    if ($counter == 0) {
                        break;
                    }
                }
            }

        }
        if ($counter > 0) {
            $len = count($blocks);
            for ($i = 0; $i < $len; $i += 1) {
                if ($blocks[$i] === '<blockquote') {
                    $blocks[$i] = '<blockquote|</blockquote';
                    $counter -= 1;
                    if ($counter == 0) {
                        break;
                    }
                }
            }
        }

        $blc = $blocks;
        $blocks = array();
        foreach ($blc as $bl) {
            if ($bl === '<blockquote|</blockquote') {
                $blocks = array_merge($blocks, explode('|', $bl));
            } else {
                $blocks[] = $bl;
            }
        }


        $counter = 0;
        $first_entry = 0;
        foreach ($blocks as $i => $block) {
            if ($block === '<blockquote') {
                if ($counter == 0) {
                    $first_entry = $i;
                }
                $counter += 1;
            }
            if ($block === '</blockquote') {
                $counter -= 1;
                if ($counter == 0) {
                    for ($j = $first_entry; $j <= $i; $j += 1) {
                        $blocks[$j] = '';
                    }
                }
            }
        }
        return implode('', $blocks);
    }

    /** Helper for self::formatHTML()
      * Replaces plaintext-style quotes (> ...) with <blockquote>...</blockquote> */
    protected static function blockquote($str)
    {
        if (is_array($str)) {
            $str = $str[1];
        }
        $str = preg_replace("~\r?\n\s?(&gt;|>)\s*([^\r\n]*)~ui", "\n$2", $str);
        return "\n<blockquote>".stripcslashes($str)."\n</blockquote>";
    }

    //
    // Non-static functions: helpers to get data in form most conenient for templates.
    // Kinda ViewModel from MVVM.
    //

    /** @var array cache for $this->getInfo() */
    public $info;

    /** @var array cache for $this->getLogs() */
    public $logs;

    /** @var cache for $this->getContactNameFromData() */
    protected $contact_name_from_data;

    /** @var cache for $this->getContactEmailFromData() */
    protected $contact_email_from_data;

    /**
     * Array with this record's info, ready for template.
     */
    public function getInfo()
    {
        if (!$this->info) {
            $this->info = $this->toArray();

            // Format time: only show hh:mm for today's requests
            $this->info['ts'] = strtotime($this->info['created']);
            $this->info['age'] = self::getAge(time() - $this->info['ts']);
            $this->info['dt_formatted'] = self::formatListDate($this->info['ts']);

            // updated
            $this->info['upd_ts'] = strtotime($this->info['updated']);
            $this->info['upd_formatted'] = self::formatListDate($this->info['upd_ts']);
            $this->info['time_since_update'] = self::getAge(time() - $this->info['upd_ts']);

            // Closed
            if (!$this->info['closed'] || $this->info['closed'] == '0000-00-00 00:00:00') {
                $this->info['closed'] = false;
            }

            // Link to download original email, if exists
            $mail_file = self::getAssetsDir($this->id).'/mail.eml';
            if (is_readable($mail_file)) {
                $this->info['original_email'] = self::getAssetUrl($this->id, 'mail.eml');
            } else {
                $this->info['original_email'] = false;
            }

            // Clean up summary
            if (trim($this->info['summary'])) {
                $this->info['summary'] = htmlspecialchars(trim($this->info['summary']));
            } else {
                $this->info['summary'] = _w('no subject');
            }

            // Format HTML text
            if (!empty($this->info['text'])) {
                $this->info['text'] = self::formatHTML($this->info);
            }

            // Attachments
            if ($this->info['attachments']) {
                $attachments = array();
                $attachments_html = array();
                foreach($this->info['attachments'] as $data) {
                    $link = self::getAttachmentUrl($this->id, basename($data['file']));
                    if (isset($data['datetime'])) {
                        $link .= '&datetime=' . $data['datetime'];
                    }
                    $data['name'] = ifset($data['name'], basename($data['file']));

                    if (isset($data['cid'])) {
                        /**
                         * Http protocol added in sanitizer for security
                         * @see helpdeskHtmlSanitizer::sanitizeUrl()
                         */
                        $this->info['text'] = str_replace('http://cid:'.$data['cid'], $link, $this->info['text']);
                        $this->info['text'] = str_replace('cid:'.$data['cid'], $link, $this->info['text']);
                    } else if (preg_match('~\.(jpg|jpeg|gif|png)$~i', $data['name'])) {
                        // Show image at the bottom of the request text
                        $img_link = '<a class="same-tab" href="'.$link.'">'.
                                '<img src="'.$link.'" class="h-request-attachment-image" data-request-id="'.$this->id.'" data-attach-id="'.basename($data['file']).'">'.
                        '</a>';
                        $attachments_html[] = '<div class="h-request-image"><hr><p>' . $img_link . '</p></div>';
                    }
                    $attachments[] = array(
                        'orig_name' => $data['name'],
                        'link' => $link,
                        'size' => @filesize($data['file']),
                    );
                }
                $this->info['attachments'] = $attachments;
                if ($attachments_html) {
                    $this->info['text'] .= "\n".'<div class="h-request-images">'."\n";
                    $this->info['text'] .= join("\n", $attachments_html);
                    $this->info['text'] .= '</div>';
                }
            }

            // Status
            try {
                $state = $this->getWorkflow()->getStateById($this->info['state_id']);
                $this->info['status'] = $state->getName();
                $this->info['status_css'] = $state->getOption('list_row_css');
            } catch(Exception $e) {
                // No such state or workflow for some reason. Ignore.
                $this->info['status'] = $this->info['state_id'];
                $this->info['status_css'] = '';
            }

            // Name of user of group request is assigned to
            $this->info['assigned_name'] = '';
            if ($this->info['assigned_contact_id'] > 0) {
                try {
                    $c = new waContact($this->info['assigned_contact_id']);
                    $this->info['assigned_name'] = $c->getName();
                } catch(Exception $e) {
                    $this->info['assigned_name'] = 'contact_id='.$this->info['assigned_contact_id'];
                }
            } else if ($this->info['assigned_contact_id'] < 0) {
                $gm = new waGroupModel();
                $this->info['assigned_name'] = $gm->getName(-$this->info['assigned_contact_id']);
                if (mb_strlen($this->info['assigned_name']) <= 0) {
                    $this->info['assigned_name'] = 'group_id='.(-$this->info['assigned_contact_id']);
                }
            }
        }

        return $this->info;
    }

    public function getData($status = null)
    {
        $rdm = new helpdeskRequestDataModel();
        return $rdm->getByRequest($this->id, $status);
    }

    public function setField($field_id, $value, $status = 1)
    {
        $rdm = new helpdeskRequestDataModel();

        $rdm->updateByField(array(
            'request_id' => $this->id, 'field' => $field_id, 'status' => 0
        ), array(
            'status' => -1,
        ));
        $rdm->replace(array(
            'request_id' => $this->id, 'field' => $field_id, 'value' => $value, 'status' => $status
        ));
    }

    /**
     * Array with this record's logs, ready for template.
     * Returns all history, no access control checking is done.
     */
    public function getLogs()
    {
        if ($this->logs === null) {
            $rlm = new helpdeskRequestLogModel();
            $this->logs = $rlm->getByRequestWithParams($this->id);
            $this->logs || $this->logs = array();

            if (!$this->logs) {
                return array();
            }

            try {
                $wf = $this->getWorkflow();
            } catch (Exception $e) {
                $wf = helpdeskWorkflow::get();
            }
            $previousTime = strtotime($this['created']);

            $contacts_cache = array(); // id => waContact or false
            $groups_cache = array();

            $gm = new waGroupModel();

            $this->extendLogsByWorkflows($this->logs);

            foreach ($this->logs as &$l) {
                // Load waContacts we need into cache
                foreach(array($l['actor_contact_id'], $l['assigned_contact_id']) as $contact_id) {
                    if ($contact_id > 0 && !isset($contacts_cache[$contact_id])) {
                        try {
                            $c = new waContact($contact_id);
                            $c->getName();
                            $contacts_cache[$contact_id] = $c;
                        } catch (Exception $e) {
                            $contacts_cache[$contact_id] = false;
                        }
                    }
                }

                $l['contact_name'] = '';

                // contact name and photo of a person who performed the action
                if ($l['actor_contact_id'] && $l['actor_contact_id'] > 0 && $contacts_cache[$l['actor_contact_id']]) {
                    $c = $contacts_cache[$l['actor_contact_id']];
                    if ($c['photo']) {
                        $l['upic'] = $c->getPhoto(50);
                    } else {
                        $email = $c->get('email', 'default');
                        if ($email) {
                            $l['upic'] = helpdeskHelper::getGravatar($email);
                        } else {
                            $l['upic'] = helpdeskHelper::getGravatar('');
                        }
                    }
                    $l['contact_name'] = $c->getName();
                } else {
                    $l['upic'] = helpdeskHelper::getGravatar('');
                    if ($l['actor_contact_id'] >= 0) {
                        $l['contact_name'] = 'contact_id=' . $l['actor_contact_id'];
                    }
                }

                // Time in different formats
                $l['ts'] = strtotime($l['datetime']);
                $l['delay'] = self::getDatetimeBySeconds($l['ts'] - $previousTime);
                $previousTime = strtotime($l['datetime']);

                // State transfer
                try {
                    if ($l['workflow']) {
                        $state = $l['workflow']->getStateById($l['before_state_id']);
                    } else {
                        $state = $wf->getStateById($l['before_state_id']);
                    }
                    $l['before_state_name'] = $state->getName();
                    $l['before_state_css'] = $state->getOption('list_row_css');
                } catch(Exception $e) {
                    // No such state or workflow for some reason. Ignore.
                    $l['before_state_name'] = $l['before_state_id'];
                    $l['before_state_css'] = '';
                }
                try {
                    if ($l['workflow']) {
                        $state = $l['workflow']->getStateById($l['after_state_id']);
                    } else {
                        $state = $wf->getStateById($l['after_state_id']);
                    }
                    $l['after_state_name'] = $state->getName();
                    $l['after_state_css'] = $state->getOption('list_row_css');
                } catch(Exception $e) {
                    // No such state or workflow for some reason. Ignore.
                    $l['after_state_name'] = $l['after_state_id'];
                    $l['after_state_css'] = '';
                }

                // List of recipients
                $recipients = explode('|', $l['to']);
                $l['to'] = $recipients[0];
                if (!empty($recipients[1])) $l['cc'] = $recipients[1];
                if (!empty($recipients[2])) $l['bcc'] = $recipients[2];

                // Action explanation to use in context %username% %performs_action%
                try {
                    if ($l['workflow']) {
                        $action = $l['workflow']->getActionById($l['action_id']);
                    } else {
                        $action = $wf->getActionById($l['action_id']);
                    }
                    $l['performs_action_string'] = $action->getPerformsActionString($l);
                    $client_visible = $action->getOption('client_visible');
                    $l['visible_to_client'] = $this['client_contact_id'] && ($client_visible === 'all' || ($client_visible && $l['actor_contact_id'] == $this['client_contact_id']));
                    if ($action instanceof helpdeskWorkflowActionAutoInterface) {
                        $l['contact_name'] = $action->getActorName();
                    }
                } catch(Exception $e) {
                    $l['visible_to_client'] = false;
                    $l['performs_action_string'] = self::getPerformsActionStringSpecial($l['action_id']);
                    if ($l['action_id'] === '!email' || $l['action_id'] === helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID) {
                        $l['visible_to_client'] = true;
                    }
                    if ($l['actor_contact_id'] == helpdeskWorkflowBasicAutoAction::ACTOR_CONTACT_ID) {
                        $l['contact_name'] = helpdeskWorkflowBasicAutoAction::getDefaultActorName();
                    }
                }
                if ($l['action_id'] == '!email' && $l['actor_contact_id'] != $this->info['client_contact_id']) {
                    $l['visible_to_client'] = false;
                }

                if (isset($l['params']['summary'])) {
                    $l['old_summary'] = $l['params']['summary'];
                }

                // Link to download original email, if exists
                $mail_file = self::getAssetsDir($this->id, $l['id']).'/mail.eml';
                if (is_readable($mail_file)) {
                    $l['original_email'] = self::getAssetUrl($this->id, $l['id'], 'mail.eml');
                } else {
                    $l['original_email'] = false;
                }

                // format HTML text
                if (!empty($l['text'])) {
                    if ($l['actor_contact_id'] != $this->client_contact_id) {
                        $l['text'] = preg_replace('~(\r?\n\r?|<div>|<br ?/?>)\s*(<(font|span)[^>]*>\s*)*-{2,}\s*(</(font|span)[^>]*>\s*)*(<br ?/?>|</div>|\r?\n\r?).*$~isum', '', $l['text']);
                        $l['text'] = preg_replace('~(\r?\n\r?|<div>|<br ?/?>)\s*(<(font|span)[^>]*>\s*)*С уважением, .*$~isum', '', $l['text']);
                    }
                    $l['text'] = helpdeskRequest::formatHTML($l);
                }

                // Attachments
                if (isset($l['params']['attachments']) && $l['params']['attachments'] && ( $l['params']['attachments'] = unserialize($l['params']['attachments']))) {
                    $l['attachment'] = array();
                    $attachments_html = array();
                    foreach ($l['params']['attachments'] as $data) {
                        $attach_id = $data['file'];
                        $data['name'] = ifset($data['name'], $attach_id);
                        $link = self::getAttachmentUrl($this->id, $l['id'], $attach_id);
                        if (isset($data['datetime'])) {
                            $link .= '&datetime='.$data['datetime'];
                        }

                        if (isset($data['cid'])) {
                            /**
                             * Http protocol added in sanitizer for security
                             * @see helpdeskHtmlSanitizer::sanitizeUrl()
                             */
                            $l['text'] = str_replace('http://cid:'.$data['cid'], $link, $l['text']);
                            $l['text'] = str_replace('cid:'.$data['cid'], $link, $l['text']);
                        } else if (preg_match('~\.(jpg|jpeg|gif|png)$~i', $data['name'])) {
                            // Show image at the bottom of the request text
                            $img_link = '<a class="same-tab" href="'.$link.'">'.
                                            '<img src="'.$link.'"
                                    class="h-request-attachment-image"
                                    data-request-id="'.$this->id.'"
                                    data-log-id="'.$l['id'].'"
                                    data-attach-id="'.$attach_id.'"></a>';
                            $attachments_html[] = "\n".'<div class="h-request-image"><hr><p>' . $img_link . '</p></div>';
                        }
                        $l['attachment'][] = array(
                            'orig_name' => $data['name'],
                            'link' => $link,
                            'size' => @filesize(self::getAttachmentsDir($l['request_id'], $l['id']).'/'.$data['file']),
                        );
                    }
                    unset($l['params']['attachments']);
                    if ($attachments_html) {
                        $l['text'] .= "\n".'<div class="h-request-images">'."\n";
                        $l['text'] .= join("\n", $attachments_html);
                        $l['text'] .= '</div>';
                    }
                } else {
                    $l['attachment'] = array();
                }

                // Assigned contact name
                $l['assigned_name'] = '';
                if ($l['assigned_contact_id'] !== null) {
                    if (!$l['assigned_contact_id']) {
                        $l['assigned_name'] = '';
                    } else if ($l['assigned_contact_id'] > 0) {
                        if ($contacts_cache[$l['assigned_contact_id']]) {
                            $l['assigned_name'] = $contacts_cache[$l['assigned_contact_id']]->getName();
                        } else {
                            $l['assigned_name'] = 'contact_id='.$l['assigned_contact_id'];
                        }
                    } else {
                        $group_id = -$l['assigned_contact_id'];
                        if (!isset($groups_cache[$group_id])) {
                            $groups_cache[$group_id] = $gm->getName($group_id);
                            if (!$groups_cache[$group_id]) {
                                $groups_cache[$group_id] = 'group_id='.$group_id;
                            }
                        }
                        $l['assigned_name'] = $groups_cache[$group_id];
                    }
                }

                // List of emails this action sent
                $l['recipients'] = array();
                foreach(explode(',', $l['to']) as $addr) {
                    if (preg_match('~^(.*)<([^>]*)>$~', trim($addr), $m)) {
                        $name = trim($m[1]);
                        $email = trim($m[2]);
                        if ($name == $l['assigned_name']) {
                            continue;
                        }
                    } else {
                        $name = '';
                        $email = trim($addr);
                    }
                    if ($email || $name) {
                        $l['recipients'][] = array(
                            'name' => ifempty($name, $email),
                            'email' => $email,
                        );
                    }
                }
            }

            $this->logs = array_reverse($this->logs, true);
        }

        return $this->logs;
    }

    private function extendLogsByWorkflows(&$logs)
    {
        foreach ($logs as &$l) {
            $l['workflow'] = null;
            if ($l['workflow_id']) {
                try {
                    $l['workflow'] = helpdeskWorkflow::get($l['workflow_id']);
                } catch (Exception $e) {
                }
            }
            unset($l);
        }
    }

    /**
     * Same as getLogs(), but for customer center (frontend) page.
     * Not all records are visible.
     * @throws waException if
     */
    public function getLogsClient()
    {
        try {
            $wf = $this->getWorkflow();
        } catch (Exception $e) {
            return array();
        }

        if (!$this['client_contact_id']) {
            // Client-visible logs for anonymous requests make no sense!
            return array();
        }

        // Client-visible names setting
        $portal_actor_display = wa()->getSetting('portal_actor_display', 'company_name', 'helpdesk');
        if ($portal_actor_display == 'company_name') {
            $portal_actor_display = wa()->getSetting('name', 'Webasyst', 'webasyst');
        }

        $log = array();
        foreach($this->getLogs() as $l) {
            if ($l['action_id'] == '!email' && $l['actor_contact_id'] != $this['client_contact_id']) {
                continue;
            } else {
                if (!in_array($l['action_id'], array('!email', helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID))) {
                    try {
                        $action = $wf->getActionById($l['action_id']);
                        $client_visible = $action->getOption('client_visible');
                        if ($client_visible !== 'all' && !($client_visible && $l['actor_contact_id'] == $this['client_contact_id'])) {
                            continue;
                        }
                    } catch (Exception $e) {
                        // Ignore unknown actions
                        continue;
                    }
                }
            }
            $l['is_actor_hidden'] = false;
            $l['real_contact_name'] = $l['contact_name'];
            if ($l['actor_contact_id'] != $this['client_contact_id'] && $portal_actor_display != 'contact_name') {
                $l['is_actor_hidden'] = true;
                $l['contact_name'] = $portal_actor_display;
            }
            $log[$l['id']] = $l;
        }

        return $log;
    }

    /**
     * Helper to generate {REQUEST_HISTORY} and {REQUEST_HISTORY_CLIENT} vars for email templates.
     * @param array $logs history as returned by getLogs() or getLogsClient()
     * @return string
     */
    public function getEmailRequestHistory($logs, $type = 'customer')
    {
        if ($type === 'customer') {
            $tmpl = '
            {LOGS}
            <div style="margin-top: 15px;"><b>{CUSTOMER_NAME}</b></div>
            <div style="color: #888; margin-bottom: 15px;">{REQUEST_CREATE_DATETIME}</div>
            <div>{REQUEST_TEXT}</div>
        ';
        } else {
            $tmpl = '
            {LOGS}
            <div style="margin-top: 15px;"><b>{CUSTOMER_NAME}</b> ' . _w('creates request') . '</div>
            <div style="color: #888; margin-bottom: 15px;">{REQUEST_CREATE_DATETIME}</div>
            <div>{REQUEST_TEXT}</div>
        ';
        }

        if ($type === 'customer') {
            $log_tmpl = '
            <div style="margin-top: 15px;"><b>{ACTOR_NAME}</b></div>
            <div style="color: #888; margin-bottom: 15px;">{LOG_DATETIME}</div>
            <div>{ACTION_TEXT}</div>
        ';
        } else {
            $log_tmpl = '
            <div style="margin-top: 15px;"><b>{ACTOR_NAME}</b> {ACTION_STRING}</div>
            <div style="color: #888; margin-bottom: 15px;">{LOG_DATETIME}</div>
            <div>{ACTION_TEXT}</div>
        ';
        }

        $log_tmpl = nl2br(preg_replace('~^\s+~', '', trim($log_tmpl)));
        $tmpl = nl2br(preg_replace('~^\s+~', '', str_replace('%s', '{REQUEST_ID}', trim($tmpl))));
        if (function_exists('smarty_gettext_translate')) {
            $tmpl = preg_replace_callback("~\[\`([^\`]+)\`\]~usi", "smarty_gettext_translate", $tmpl);
        } else {
            $tmpl = str_replace(array('[`', /*`*/ '`]'), '', $tmpl);
        }

        $logs_str = array();
        foreach($logs as $l) {
            $replace_tmpl = array_map('htmlspecialchars', array(
                '{ACTOR_NAME}' => $l['contact_name'],
                '{LOG_DATETIME}' => wa_date('humandatetime', $l['datetime']),
            ));

            $fields = array();
            foreach ($l['params'] as $param_key => $param_val) {
                if (helpdeskRequestLogParamsModel::getType($param_key) === helpdeskRequestLogParamsModel::TYPE_REQUEST) {
                    $field = helpdeskRequestLogParamsModel::getField($param_key);
                    if ($field) {
                        if ($type === 'customer') {
                            if ($field->getParameter('my_visible')) {
                                $fields[$param_key] = $param_val;
                            }
                        } else {
                            $fields[$param_key] = $param_val;
                        }
                    }
                }
            }


            $text = $l['text'];
            foreach (helpdeskRequestLogParamsModel::formatFields($fields) as $info) {
                $text .= '<div><span style="color:#888;">' . $info['name'] . ':</span> ' . $info['value'] . '</div>';
            }

            $replace_action_text = $text;

            if ($type === 'customer' && empty($l['text']) && empty($fields)) {
                $replace_action_text = $l['performs_action_string'];
            }

            $replace_action_text = helpdeskRequest::stripBlockquotes($replace_action_text);

            $replace_tmpl['{ACTION_TEXT}'] = $replace_action_text;

            if ($type !== 'customer') {
                $replace_tmpl['{ACTION_STRING}'] = $l['performs_action_string'];
            }

            $logs_str[] = str_replace(array_keys($replace_tmpl), array_values($replace_tmpl), $log_tmpl);
        }
        $logs_str = implode('<hr>', $logs_str);

        try {
            $customer_name = $this->getClient()->getName();
        } catch (Exception $e) {
            $customer_name = $this->getContactNameFromData();
        }

        $r_info = $this->getInfo();
        $replace_tmpl = array_map('htmlspecialchars', array(
            '{REQUEST_ID}' => $r_info['id'],
            '{REQUEST_CREATE_DATETIME}' => wa_date('humandatetime', $r_info['created']),
            '{REQUEST_SUBJECT}' => $r_info['summary'],
            '{CUSTOMER_NAME}' => $customer_name,
        ));

        $replace_tmpl['{LOGS}'] = $logs_str ? $logs_str . '<hr>' : '';

        $text = $r_info['text'];

        $fields = array();
        foreach ($this->getData(array(0, -1)) as $field_id => $info) {
            $field = helpdeskRequestDataModel::getField($field_id);
            if ($field) {
                if ($type === 'customer') {
                    if ($field->getParameter('my_visible')) {
                        $fields[$field_id] = $info;
                    }
                } else {
                    $fields[$field_id] = $info;
                }
            }
        }

        foreach (helpdeskRequestDataModel::formatFields($fields) as $info) {
            $text .= '<div><span style="color:#888;">' . $info['name'] . ':</span> ' . $info['value'] . '</div>';
        }
        $replace_tmpl['{REQUEST_TEXT}'] = $text;

        return str_replace(array_keys($replace_tmpl), array_values($replace_tmpl), $tmpl);
    }

    public function getContactNameFromData()
    {
        if ($this->contact_name_from_data === null) {
            $data = array();
            foreach ($this->getData() as $field_id => $val) {
                if (strpos($field_id, 'c_') === 0) {
                    $data[substr($field_id, 2)] = ifset($val['value'], '');
                }
            }
            $name = '';
            if (isset($data['name'])) {
                $name = trim($data['name']);
            }
            if (!$name) {
                $name = waContactNameField::formatName($data);
            }
            $this->contact_name_from_data = $name;
        }
        return $this->contact_name_from_data;
    }

    public function getContactEmailFromData()
    {
        if ($this->contact_email_from_data === null) {
            $email = '';
            $data = $this->getData();
            if (isset($data['c_email']['value'])) {
                $email = $data['c_email']['value'];
            }
            $this->contact_email_from_data = $email;
        }
        return $this->contact_email_from_data;
    }

}
