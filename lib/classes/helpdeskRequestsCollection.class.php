<?php
/**
 * helpdeskRequestsCollection instance represents list of requests
 * possibly filtered according to several parametrisized filters.
 *
 * See $this->addFilter() for list of available filters.
 */
class helpdeskRequestsCollection
{

    /**
     * Factory method. Allows to specify custom collection class via
     * lib/config/factories.php
     */
    public static function create($filters = array(), $options = array())
    {
        is_array($options) || $options = array();
        is_array($filters) || $filters = array();

        $config = wa('helpdesk')->getConfig()->getFactory('requests_collection');

        if (!$config) {
            return new self($filters, $options);
        }

        if (is_array($config)) {
            $class = $config[0];
            $opts = ifset($config[1]);
            is_array($opts) || $opts = array();
            $options = $opts + $options;
        } else if (is_string($config)) {
            $class = $config;
            if (!class_exists($class)) {
                throw new waException('Unable to instantiate requests collection class: '.$class, 500);
            }
        } else {
            throw new waException('Factory error: requests_collection', 500);
        }

        return new $class($filters, $options);
    }

    public $options = array();

    /** @var helpdeskRequestModel */
    public $model;

    /** Conditions to be joined (via AND) into WHERE clause.
      * @var array */
    public $where = array();

    /** Conditions to be joined into JOIN clause.
      * @var array */
    public $from = array();

    /** Parameters for LIMIT clause
      * @var string */
    public $limit = '';

    /** Parameters for ORDER clause
      * @var string */
    public $order = 'r.updated desc';

    /** Human-readable description of filters
      * @var string */
    public $header = '';

    /** Cache for $this->count() */
    protected $count = null;

    /** Debugging helper. Stores SQL used by last getRequets() call. */
    public $last_sql = null;

    /**
     * @param array $filters list of filters, each represented by array(name => '...', params => array(...). See $this->addFilter() for list of available filters.
     */
    public function __construct($filters=array(), $options = array())
    {
        $this->model = new helpdeskRequestModel();
        $this->options += $options;
        foreach($filters as $f) {
            $this->addFilter($f['name'], $f['params'], ifset($f['op'], ':'));
        }
    }

    /**
      * Filter this collection by given filter.
      *
      * For description of available filters, see $this->*FilterAdd() functions.
      *
      * @param string $name filter name
      * @param array $params filter parameters
      * @return $this
      */
    public function addFilter($name, $params=array(), $op = ':')
    {
        if ($name == '@all') {
            return $this;
        }

        $method = strtolower($name).'FilterAdd';
        if (method_exists($this, $method)) {
            $this->$method($params, $op);
            return $this;
        }

        $params = array(
            'collection' => $this,
            'filter_name' => $name,
            'filter_op' => $op,
            'filter_params' => $params,
        );

        /**
         * @event requests_collection_filter
         * Allows to add collection filters unknown to base collection class.
         * @return bool null if ignored, true when something changed in the collection
         */
        $processed = wa()->event('requests_collection_filter', $params);
        if (!$processed) {
            throw new waException('Unknown filter for helpdeskRequestsCollection: '.$name, 404);
        }

        return $this;
    }

    /**
      * Order to apply to the list.
      * Does not check its parameters in any way.
      * @param string $field DB field to sort by (or custom parameter for ORDER sql clause)
      * @param string $order ASC or DESC (defaults to MySQL defaults)
      * @return $this
      */
    public function orderBy($field, $order='')
    {
        $this->order = $field.' '.$order;
        return $this;
    }

    /**
      * When called with one parameters, limits the number of rows to return to given number.
      * When called with two parameters, skips the first $skip rows in list and limits
      * the number of returned rows to $limit.
      * @return $this
      */
    public function limit($skip, $limit=null)
    {
        $skip = (int) $skip;
        if ($limit) {
            $limit = (int) $limit;
            $this->limit = $skip.','.$limit;
        } else {
            $this->limit = $skip;
        }
        return $this;
    }

    /**
      * How many requests does this collection contain (ignoring $this->limit() settings).
      * @return int
      */
    public function count()
    {
        if ($this->count === null) {
            $this->joinRights();
            $sql =  "SELECT COUNT(DISTINCT r.id)".$this->getSqlBase();
            $this->count = $this->model->query($sql)->fetchField();
        }
        return $this->count;
    }

    /**
      * All requests in this collection.
      * @return array list of database rows from helpdesk_request table, with additional keys: client_name, assigned_name
      */
    public function getRequests($full_text=false)
    {
        // do not select full text by default because in big list views it takes too much memory
        // and is not needed most of the time anyway.
        if ($full_text) {
            $fields = 'r.*';
        } else {
            $fields = $this->model->getMetadata();
            unset($fields['text']);
            $fields = array_keys($fields);
            $fields = 'r.'.implode(',r.', $fields);
            $fields .= ',SUBSTRING(r.text, 1, 500) AS text';
        }

        $sql = "SELECT ";
        $used_calc_found_rows = false;
        if ($this->count === null) {
            if ($this->where) {
                $used_calc_found_rows = true;
                $sql .= 'SQL_CALC_FOUND_ROWS ';
            } else {
                // in case when there are a lot of rows in resulting set,
                // it is much much faster to use a second SQL request to count them.
            }
        }

        $this->joinRights();

        // List of rows
        $sql .= $fields.
                $this->getSqlBase().
                ($this->from  ? "\nGROUP BY r.id" : '').
                ($this->order ? "\nORDER BY ".$this->order : '').
                ($this->limit ? "\nLIMIT ".$this->limit : '');

        $this->last_sql = $sql;

        $result = array();
        $contacts = array(); // id => array
        $group_names = array(); // id => string
        $request_ids = array();
        $last_logs = array(); // id => array

        foreach($this->model->query($sql) as $row) {
            $result[] = $row + array(
                'is_unread' => 0,
                'actor_name' => '',
                'actor_photo_ts' =>  '',
                'client_name' => '',
                'client_photo_ts' =>  '',
                'assigned_name' => '',
                'assigned_photo_ts' => '',
                'last_action_id' => '',
                'last_action_datetime' => '',
            );
            if ($row['client_contact_id']) {
                $contacts[$row['client_contact_id']] = array();
            }
            if ($row['assigned_contact_id'] > 0) {
                $contacts[$row['assigned_contact_id']] = array();
            } else if ($row['assigned_contact_id'] < 0) {
                $group_names[-$row['assigned_contact_id']] = '';
            }
            if ($row['last_log_id']) {
                $last_logs[$row['last_log_id']] = array();
            }
            $request_ids[] = $row['id'];
        }

        if ($this->count === null && $used_calc_found_rows) {
            $this->count = (int) $this->model->query('SELECT FOUND_ROWS()')->fetchField();
        }

        // Last action
        if ($last_logs) {
            $sql = "SELECT id, datetime, action_id, actor_contact_id FROM helpdesk_request_log WHERE id IN (?)";
            foreach($this->model->query($sql, array(array_keys($last_logs))) as $l) {
                $last_logs[$l['id']] = $l;
                if ($l['actor_contact_id'] > 0) {
                    $contacts[$l['actor_contact_id']] = array();
                }
            }
        }

        // Contact and group names, and contact photos
        if ($contacts) {
            $sql = "SELECT c.id, c.name, c.firstname, c.middlename, c.lastname, c.photo, e.email
                FROM wa_contact c
                LEFT JOIN wa_contact_emails e ON c.id = e.contact_id AND e.sort = 0
                WHERE c.id IN (?)";
            foreach($this->model->query($sql, array(array_keys($contacts))) as $c) {
                $contacts[$c['id']] = $c;
            }
        }
        if ($group_names) {
            $cm = new waGroupModel();
            $group_names = $cm->getName(array_keys($group_names));
        }

        // Unread status
        $unreads = array();
        if ($request_ids) {
            $um = new helpdeskUnreadModel();
            $flds = array(
                'contact_id' => wa()->getUser()->getId(),
                'request_id' => $request_ids,
            );
            if (count($request_ids) > 200) {
                unset($flds['request_id']);
            }
            $unreads = $um->getByField($flds, 'request_id');
        }

        foreach($result as &$row) {

            // client
            if ($row['client_contact_id'] && !empty($contacts[$row['client_contact_id']])) {;
                $row['client_name'] = $this->contactFormatName($contacts[$row['client_contact_id']], true);
                $row['client_photo_ts'] = $contacts[$row['client_contact_id']]['photo'];
                $row['client_email'] = $contacts[$row['client_contact_id']]['email'];
            } else {
                $row['client_name'] = _w('Anonymous');
            }

            // assignee
            if ($row['assigned_contact_id'] > 0 && !empty($contacts[$row['assigned_contact_id']])) {
                $row['assigned_name'] = $this->contactFormatName($contacts[$row['assigned_contact_id']], false);
                $row['assigned_photo_ts'] = $contacts[$row['assigned_contact_id']]['photo'];
            } else if ($row['assigned_contact_id'] < 0 && isset($group_names[-$row['assigned_contact_id']])) {
                $row['assigned_name'] = $group_names[-$row['assigned_contact_id']];
            }

            // Last log
            if (!empty($last_logs[$row['last_log_id']])) {
                $l = $last_logs[$row['last_log_id']];
                $row['last_action_id'] = $l['action_id'];
                $row['last_action_datetime'] = $l['datetime'];
                $row['actor_contact_id'] = $l['actor_contact_id'];
                if ($row['actor_contact_id'] > 0 && !empty($contacts[$row['actor_contact_id']])) {
                    $row['actor_name'] = $contacts[$row['actor_contact_id']]['name'];
                    $row['actor_photo_ts'] = $contacts[$row['actor_contact_id']]['photo'];
                }
            }

            // Unread status
            if (!empty($unreads[$row['id']])) {
                $row['is_unread'] = 1;
            }

            if (empty($row['summary']) || !trim($row['summary'])) {
                $row['summary'] = _w('no subject');
            }

        }
        unset($row);

        return $result;
    }

    protected function joinRights()
    {
        $joins = array();

        if (wa()->getEnv() === 'backend') {
            $helpdesk_backend_rights = wa()->getUser()->getRights('helpdesk', 'backend');
            if (!$helpdesk_backend_rights) {
                $this->where[] = '0';
            } else if ($helpdesk_backend_rights <= 1) {
                $in = array(
                    wa()->getUser()->getId()
                );
                $ugm = new waUserGroupsModel();
                $groups = $ugm->getGroupIds(wa()->getUser()->getId());
                foreach ($groups as $group_id) {
                    if ($group_id != 0) {
                        $in[] = -$group_id;
                    }
                }

                $joins = array(
                    "JOIN helpdesk_rights AS hrr ON hrr.contact_id IN (".implode(',', $in).") AND
                    hrr.workflow_id = r.workflow_id AND
                    IF (hrr.state_id = '!state.all', 1, hrr.state_id = r.state_id)"
                );
            }
        }

        if ($joins) {
            $this->from = array_merge($this->from, $joins);
        }

    }

    /**
      * Creates a mark to pass to `mark` filter later to retrieve requests changed since the mark has been set.
      * @return string
      */
    public function getMark()
    {
        $rlm = new helpdeskRequestLogModel();
        return $this->model->getLastId().':'.$rlm->getLastId();
    }

    public function getHeader()
    {
        return $this->header;
    }

    /**
      * Helper for count() and getRequests()
      * @return string part of SQL including FROM and WHERE, but no SELECT, GROUP BY, ORDER BY or LIMIT.
      */
    protected function getSqlBase()
    {
        return "\nFROM helpdesk_request AS r\n".
                    implode("\n", array_unique($this->from)).
                ($this->where ? "\nWHERE (".implode(') AND (', $this->where).')' : '')."\n";
    }

    //
    // Filter implementation
    //

    /**
     * Only show requests with param containing given text
     * @param array $param [0]: param_id; [1]: value
     */
    protected function paramFilterAdd($param)
    {
        if(count($param) < 2) {
            throw new waException('Parameter filter requires at least 2 parameters.', 404);
        }

        $t = 'rp'.count($this->from);
        $this->from[] = "JOIN helpdesk_request_params AS $t ON $t.request_id=r.id";
        $this->where[] = "($t.name='".$this->model->escape($param[0])."' AND $t.value LIKE '%".$this->model->escape($param[1])."%')";

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        $this->header .= $param[0].'='.$param[1];
    }

    /**
     * Only show requests with param containing given text
     * @param array $field [0]: field_id; [1]: op, [2]: value
     */
    protected function fieldFilterAdd($param)
    {
        if(count($param) < 2) {
            throw new waException('Field filter requires at least 2 parameters.', 404);
        }

        $field_id = $param[0];
        $field_name = $field_id;
        $field = helpdeskRequestFields::getField($field_id);
        if ($field) {
            $field_name = $field->getName();
        }

        $t = 'rp'.count($this->from);
        $join = "JOIN helpdesk_request_data AS {$t} ON {$t}.request_id=r.id AND {$t}.field='".$this->model->escape($param[0])."'";

        if ($field && $field->getType() === 'Checkbox') {
            if ($param[2]) {
                $this->from[] = "{$join} AND {$t}.value != 0";
            } else {
                $this->from[] = "{$join} AND {$t}.value = 0";
            }
            // human-readable description
            $this->header .= $this->header ? ', ' : '';
            $this->header .= $field_name.'='. ($param[2] ? _w('Yes') : _w('No'));
        } else {
            $this->from[] = "{$join}";

            if (!$field || $field->getType() !== 'Checkbox') {
                $values = array();
                foreach (explode(',', $param[2]) as $val) {
                    if ($val) {
                        $values[] = "{$t}.value LIKE '%" . $this->model->escape($val, 'like') . "%'";
                    }
                }
                if ($values) {
                    $this->where[] = "("  . implode(' OR ', $values) . ")";
                }
            }
            // human-readable description
            $this->header .= $this->header ? ', ' : '';
            $this->header .= $field_name.'='. $param[2];
        }
    }

    protected function createdFilterAdd($param, $op)
    {
        $header = '';
        if ($op === '>=') {
            $this->where[] = "r.created >= '" . $this->model->escape($param[0]) . "'";
            $header = _w('Create date') . '>=' . $param[0];
        } else if ($op === '<=') {
            $this->where[] = "r.created <= '" . $this->model->escape($param[0]) . "'";
            $header = _w('Create date') . '<=' . $param[0];
        } else if ($op === ':') {
            if (count($param) < 2) {
                $param = explode('--', $param[0]);
            }
            $this->where[] = "r.created >= '" . $this->model->escape($param[0]) . "' AND r.created <= '" . $this->model->escape($param[1]) . "'";
            $header = _w('Create date') . '=' . $param[0] . '–' . $param[1];
        } else {
            $this->where[] = '0';
        }

        if ($header) {
            $this->header .= ($this->header ? ', ' : '') . $header;
        }
    }

    /**
      * Simple search by client email.
      * @param array $param [0]: text to search
      */
    protected function c_emailFilterAdd($param)
    {
        if(!$param) {
            throw new waException('c_email filter requires one parameter.', 404);
        }

        // escape parameter for use inside mysql LIKE
        $string = array_shift($param);
        //$string = str_replace(array('\\', '_', '%'), array('\\\\', '\_', '\%'), $string);
        $val = $this->model->escape($string, 'like');

        $this->from[] = 'JOIN wa_contact_emails AS client_email ON client_email.contact_id=r.client_contact_id';
        $this->where[] = "client_email.email LIKE '%{$val}%'";

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Client email').'='.$string;
    }

    /**
      * Simple search by request id or client name.
      * @param array $param [0]: text to search
      */
    protected function c_name_idFilterAdd($param)
    {
        //#
        if(!$param) {
            throw new waException('c_name_id filter requires one parameter.', 404);
        }

        // escape parameter for use inside mysql LIKE
        $string = array_shift($param);

        if (wa_is_int($string)) {
            $string = $this->model->escape($string, 'like');
            $this->where[] = "CONVERT(r.id USING utf8) LIKE '%{$string}%'";
            $this->header .= $this->header ? ', ' : '';
            $this->header .= _w('Request ID').'≈'.$string;
        } else {
            $this->header .= $this->header ? ', ' : '';
            $this->header .= _w('Client name').'='.$string;

            $this->from[] = 'JOIN wa_contact AS client ON client.id = r.client_contact_id';
            foreach(preg_split('~\s+~', $string) as $string) {
                $string = $this->model->escape($string, 'like');
                $this->where[] = "client.name LIKE '%{$string}%'";
            }
        }
    }

    /**
      * By helpdesk_request_log.actor_contact_id
      * @param array $param [0]: text to search
      */
    protected function actor_idFilterAdd($param)
    {
        if(!$param || !is_array($param) || !$param[0]) {
            throw new waException('actor_id filter requires one parameter.', 404);
        }

        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }
        foreach($param as $v) {
            if (!wa_is_int($v)) {
                throw new waException('actor_id filter only accepts integers.', 404);
            }
        }
        $col = new waContactsCollection('id/' . implode(',', $param));
        $contacts = $col->getContacts('id,name,firstname,middlename,lastname', 0, $col->count());
        $param = implode(',', $param);

        $this->from[] = 'JOIN helpdesk_request_log AS rlog ON rlog.request_id=r.id';
        $this->where[] = 'rlog.actor_contact_id IN ('.$param.')';

        // human-readable description
        $this->header .= $this->header ? ', ' : '';

        $header = array();
        foreach (explode(',', $param) as $c_id) {
            $name = $c_id;
            if (isset($contacts[$c_id])) {
                $name = waContactNameField::formatName($contacts[$c_id]);
            }
            $header[] = $name;
        }

        $this->header .= _w('Actor').'='.implode(',', $header);
    }

    protected function tag_idFilterAdd($param)
    {
        if(!$param || !is_array($param) || !$param[0]) {
            throw new waException('action_id filter requires one parameter.', 404);
        }

        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        $param = implode(',', array_map('intval', $param));

        $this->from[] = 'JOIN helpdesk_request_tags AS hrt ON hrt.request_id = r.id';
        $this->where[] = 'hrt.tag_id IN ('.$param.')';

        $tag_model = new helpdeskTagModel();
        $tags = $tag_model->getByField(array(
            'id' => explode(',', $param)
        ), 'id');
        $names = array();
        foreach (explode(',', $param) as $tag_id) {
            if (isset($tags[$tag_id])) {
                $names[] = $tags[$tag_id]['name'];
            } else {
                $names[] = $tag_id;
            }
        }

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Tags').'='.implode(',', $names);
    }

        /**
      * By helpdesk_request_log.action_id
      * @param array $param [0]: text to search
      */
    protected function action_idFilterAdd($param)
    {
        if(!$param || !is_array($param) || !$param[0]) {
            throw new waException('action_id filter requires one parameter.', 404);
        }

        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }
        foreach($param as &$v) {
            $v = "'".$this->model->escape($v)."'";
        }
        unset($v);
        $param = implode(',', $param);

        $this->from[] = 'JOIN helpdesk_request_log AS rlog ON rlog.request_id=r.id';
        $this->where[] = 'rlog.action_id IN ('.$param.')';

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Actions').'='.$param;
    }

    /**
      * Only show requests containing given text.
      * @param array $param [0]: text to search; [1]: operation, [2] (optional): subset of id, text, summary, client_name, client_email (array or comma-separated; defaults to everywhere)
      */
    protected function searchFilterAdd($param)
    {
        if(!$param) {
            throw new waException('Search filter requires at least one parameter.', 404);
        }

        // escape parameter for use inside mysql LIKE
        $string = array_shift($param);
        $op = array_shift($param);
        $param_places = ifset($param[0], array());

        // possible search columns
        static $possible_places = array(
            'text' => 'r.text',
            'summary' => 'r.summary',
            'log_text' => 'rlog.text',
        );

        // collect all fields to search in
        if (!$param_places) {
            $param_places = array_keys($possible_places);
        }
        if(!is_array($param_places)) {
            $param_places = explode(',', $param_places);
        }

        // Places where to search
        $columns = array();
        foreach($param_places as $place) {
            if(!isset($possible_places[$place])) {
                throw new waException('Unknown place to search: '.$place, 404);
            }
            $col = $possible_places[$place];
            $columns[] = $col;
        }
        if(!$columns) {
            return;
        }
        if(in_array('log_text', $param_places)) {
            $this->from[] = 'JOIN helpdesk_request_log AS rlog ON rlog.request_id=r.id';
        }

        // Prepare search string and add conditions to $this->where
        $where_match_against = array();
        $where_like = array();
        if (preg_match('~\(|\)|\"~', $string)) {
            // Search string contains special characters. Use as is.
            $this->where[] = "MATCH (".implode(',', $columns).") AGAINST ('".$this->model->escape($string)."' IN BOOLEAN MODE)";
        } else {
            // Prepare each word in search string.
            // Use MATCH ... AGAINST or LIKE depending on length of the word
            $search_words = preg_split('~\s+~', $string);
            foreach($search_words as $word) {
                $word = ltrim($word, '+');
                if (trim($word, '+-><*~') != $word || mb_strlen($word) >= 4) {
                    if (ltrim($word, '+-><~') == $word) {
                        $word = '+'.$word;
                    }
                    if (rtrim($word, '*') == $word) {
                        $word .= '*';
                    }
                    $where_match_against[] = $word;
                    if($param_places == array('summary')) {
                        $this->where[] = "r.summary LIKE '%".$this->model->escape(trim($word, '+-><*~'))."%'";
                    }
                } else {
                    $where_like[] = $word;
                }
            }

            if ($where_like) {
                $like_conditions = array();
                foreach($where_like as $s) {
                    $like_conditions[] = "CONCAT_WS(' ', ".implode(', ', $columns).") LIKE '%".$this->model->escape($s)."%'";
                }
                if ($like_conditions) {
                    $this->where[] = '('.implode(') AND (', $like_conditions).')';
                }
            }

            if ($where_match_against) {
                // make use of a `text_summary` fulltext index
                if($param_places == array('summary')) {
                    $columns[] = 'r.text';
                } else if($param_places == array('text')) {
                    $columns[] = 'r.summary';
                }
                $this->where[] = "MATCH (".implode(',', $columns).") AGAINST ('".$this->model->escape(implode(' ', $where_match_against))."' IN BOOLEAN MODE)";
            }
        }

        // human-readable description
        $descr = '';
        static $names = array(
            'text' => 'Text',   // _w('Text')
            'summary' => 'Summary', // _w('Summary')
            'log_text' => 'Action text',    // _w('Action text')
        );
        foreach($param_places as $col) {
            if (isset($names[$col])) {
                $descr .= $descr ? ' ' . _w('or') . ' ' : '';
                $descr .= _w($names[$col]);
            }
        }
        $descr .= '=' . str_replace('+', '', $string);
        $this->header .= $this->header ? ', ' : '';
        $this->header .= $descr;
    }

    /**
      * Only show requests containing given text.
      * @param array $param [0]: text to search
      */
    protected function search_likeFilterAdd($param)
    {
        if(!$param) {
            throw new waException('Search_like filter requires one parameter.', 404);
        }

        // escape parameter for use inside mysql LIKE
        $string = array_shift($param);
        $this->where[] = "r.text LIKE '%".$this->model->escape($string, 'like')."%'";
        $this->header .= $this->header ? ', ' : '';
        $this->header .= 'text LIKE '.$string;
    }

    /**
      * Only show requests created at given date.
      * @param array $param [0] = year, [1] = month, [2] = day; each part is either an int or an empty string
      */
    protected function dateFilterAdd($param)
    {
        if(count($param) < 3) {
            throw new waException('Source filter requires at least 3 parameters.', 404);
        }
        for($i = 0; $i < 3; $i++) {
            $param[$i] = (int) $param[$i];
            if (!$param[$i]) {
                $param[$i] = '%';
            } else {
                $param[$i] = str_pad($param[$i], $i == 0 ? 4 : 2, '0', STR_PAD_LEFT);
            }
        }

        $this->where[] = "r.created LIKE '{$param[0]}-{$param[1]}-{$param[2]} %'";

        // human-readable description
        if ($param[0] != '%') {
            $this->header .= $this->header ? ', ' : '';
            $this->header .= _w('year').'='. ((int)$param[0]);
        }
        if ($param[1] != '%') {
            $this->header .= $this->header ? ', ' : '';
            $this->header .= _w('month').'='.((int)$param[1]);
        }
        if ($param[2] != '%') {
            $this->header .= $this->header ? ', ' : '';
            $this->header .= _w('day').'='.((int)$param[2]);
        }
    }

    /**
      * Only show requests from given source or sources.
      * @param array $param [0] = source id or list of ids (array or comma-separated string)
      */
    protected function sourceFilterAdd($param)
    {
        if(count($param) < 1) {
            throw new waException('Source filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        foreach($param as &$v) {
            $v = $this->model->escape($v);
        }

        if (count($param) > 1) {
            $this->where[] = "r.source_id IN ('".implode("','", $param)."')";
        } else {
            $this->where[] = "r.source_id='{$param[0]}'";
        }

        // fetch names
        $names = array();
        foreach ($param as $s_id) {
            try {
                $s = new helpdeskSource($s_id);
                $names[] = $s->name;
            } catch (Exception $e) {
                $names[] = $s_id;
            }
        }

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Source') . '=' . implode(', ', $names);

    }

    /**
      * Only show requests in given state or states.
      * @param array $param [0] = state or list of states (array or comma-separated string)
      */
    protected function stateFilterAdd($param)
    {
        if(count($param) < 1) {
            throw new waException('State filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        $default_wf = helpdeskWorkflow::getWorkflow();  // default

        $values = array();
        $workflows = array();
        foreach($param as $v) {
            if (preg_match('~^([0-9]+)@(.*)$~', $v, $m)) {
                $workflows[$m[1]] = true;
                $values[] = array(
                    $this->model->escape($m[2]), // state_id
                    $m[1], // workflow_id
                );
            } else {
                if (strpos($v, '@') !== false) {
                    $workflows[''] = true;
                    $values[] = array(
                        '',
                        null
                    );
                } else {
                    $workflows[''] = true;
                    $values[] = array(
                        $this->model->escape($v),
                        null,
                    );
                }
            }
        }

        if (count($workflows) == 1) {

            $header = array();

            $workflow = null;
            $workflow_id = key($workflows);
            if ($workflow_id) {
                $workflow_name = $workflow_name = $workflow_id;;
                $workflow = helpdeskWorkflow::getWorkflow($workflow_id);
                if ($workflow) {
                    $workflow_name = $workflow->getName();
                }
                $this->where[] = "r.workflow_id=".$workflow_id;
                $header[] = $workflow_name;
            }

            $values = array_map(wa_lambda('$i', 'return $i[0];'), $values);
            $state_names = array();
            foreach ($values as $state_id) {
                if ($workflow) {
                    try {
                        $state = $workflow->getStateById($state_id);
                        $state_names[] = $state->getName();
                    } catch (waException $e) {
                        $state_names[] = $state_id;
                    }
                } else {
                    if ($default_wf) {
                        try {
                            $state = $default_wf->getStateById($state_id);
                            $state_names[] = $state->getName();
                        } catch (waException $e) {
                            $state_names[] = $state_id;
                        }
                    } else {
                        $state_names[] = $state_id;
                    }
                }
            }
            $this->where[] = "r.state_id IN ('".implode("','", $values)."')";
            $header[] = implode(', ', $state_names);

            $this->header .= $this->header ? ', ' : '';
            $this->header .= implode(' / ', $header);

        } else {
            $ors = array();
            $hdr = array();
            foreach($values as $i) {
                if ($i[1]) {
                    $ors[] = '(r.workflow_id='.$i[1]." AND r.state_id='".$i[0]."')";
                    $hdr[] = $i[1].'@'.$i[0];
                } else {
                    $ors[] = "r.state_id='".$i[0]."'";
                    $hdr[] = $i[0];
                }
            }
            $this->where[] = '('.implode(' OR ', $ors).')';
            $this->header .= $this->header ? ', ' : '';
            $this->header .= 'workflow@state='.implode(',', $hdr);
        }
    }

    /**
      * Only show requests that belong to given workflow or workflows.
      * @param array $param [0] = workflow_id or a list of ids (array or comma-separated string)
      */
    protected function workflowFilterAdd($param)
    {
        if (!$param) {
            throw new waException('Workflow filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        foreach($param as &$v) {
            try {
                $wf = helpdeskWorkflow::getWorkflow($v);
            } catch (Exception $e) {
                continue;
            }
            $v = (int) $wf->getId();
        }

        if (count($param) > 1) {
            $this->where[] = "r.workflow_id IN (".implode(",", $param).")";
        } else {
            $this->where[] = "r.workflow_id={$param[0]}";
        }

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        try {
            $this->header .= _w('workflow').'='.helpdeskWorkflow::getWorkflow($param[0])->getName();
        } catch (Exception $e) {
            $this->header .= _w('workflow').'='.$param[0];
        }
    }

    /**
      * Only show requests assigned to given user or users. With no parameters shows requests that are not assigned to anybody.
      * @param array $param [0] = user_id, list of ids (array or comma-separated string); empty array for not assigned requests.
      */
    protected function assignedFilterAdd($param)
    {
        if (!$param) {
            $param = array(0);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        foreach($param as &$v) {
            $v = (int) $v;
        }

        if (count($param) > 1) {
            $this->where[] = "r.assigned_contact_id IN (".implode(",", $param).")";
        } else {
            $this->where[] = "r.assigned_contact_id={$param[0]}";
        }

        // human-readable description
        $this->header .= $this->header ? ', ' : '';
        try {
            // assigned to contact?
            if ($param[0] > 0) {
                $c = new waContact($param[0]);
                $this->header .= _w('Assigned to').' '.$c->getName();
            }
            // assigned to group?
            else if ($param[0] < 0) {
                $gm = new waGroupModel();
                if ( ( $g = $gm->getById(-$param[0]))) {
                    $this->header .= _w('Assigned to').' '.$g['name'];
                } else {
                    throw new waException('No such group.', 404);
                }
            }
            // not assigned?
            else {
                $this->header .= _w('Not assigned');
            }
        } catch (Exception $e) {
            $this->header .= _w('Assigned to id=').$param[0];
        }
    }

    /**
      * Only show requests created by given contact id (or ids)
      * @param array $param [0] = contact_id or a list of those (array or comma-separated string)
      */
    protected function clientFilterAdd($param)
    {
        if (!$param) {
            throw new waException('CLient filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        foreach($param as &$v) {
            $v = (int) $v;
        }

        $this->header .= $this->header ? ', ' : '';

        if (count($param) > 1) {
            $this->where[] = "r.client_contact_id IN (".implode(",", $param).")";
            $this->header .= _w('client').'='.implode(',', $param);
        } else {
            $this->where[] = "r.client_contact_id={$param[0]}";
            try {
                $c = new waContact($param[0]);
                $this->header .= _w('client').'='.$c->getName();
            } catch (Exception $e) {
                $this->header .= _w('client').'='.$param[0];
            }
        }
    }

    /**
      * Only show requests that are closed, or that are open.
      * TODO: allow to specify a date.
      * @param array $param [0] = 1 to show closed; 0 to show open requests.
      */
    protected function closedFilterAdd($param)
    {
        if(count($param) < 1) {
            throw new waException('"Closed" filter requires at least one parameter.', 404);
        }
        $this->header .= $this->header ? ', ' : '';
        if (((int)$param[0]) > 0) {
            $this->where[] = "(r.closed IS NOT NULL AND r.closed<>'0000-00-00 00:00:00')";
            $this->header .= _w('closed');
        } else {
            $this->where[] = "(r.closed IS NULL OR r.closed='0000-00-00 00:00:00')";
            $this->header .= _w('open');
        }
    }

    /**
      * Only show requests updated since the mark has been set by $this->getMark().
      * @param array $param [0] = mark to use
      */
    protected function markFilterAdd($param)
    {
        if(count($param) < 1) {
            throw new waException('Mark filter requires at least one parameter.', 404);
        }
        $mark = explode(':', $param[0], 2);
        if(count($mark) < 2) {
            throw new waException('Bad parameters for mark filter.', 404);
        }

        //
        // What we basically want to do here is to add one condition:
        //     r.id > ?  OR  r.last_log_id > ?
        // which, when used alone, has a TERRIBLE performance being an 'OR' query.
        //
        // To optimize the query, we use an additional condition using `updated` field,
        // allowing MySQL to use an index.
        //

        $updated1 = $this->model->query("SELECT created FROM helpdesk_request WHERE id = ?", (int)$mark[0])->fetchField();
        $updated2 = $this->model->query("SELECT datetime FROM helpdesk_request_log WHERE id = ?", (int)$mark[1])->fetchField();

        if ($updated1 && $updated2) {
            if (strtotime($updated1) > strtotime($updated2)) {
                $updated = $updated1;
            } else {
                $updated = $updated2;
            }
        } else if ($updated1) {
                $updated = $updated1;
        } else if ($updated2) {
                $updated = $updated2;
        } else {
            $this->where[] = '1=0';
            return;
        }

        $this->where[] = "r.updated > '".$this->model->escape($updated)."' AND (r.id > ".((int)$mark[0])." OR r.last_log_id > ".((int)$mark[1]).")";
    }

    /**
     * Filter by access rights.
     *
     * $param[0] = array(wf_id => true if all requests allowed, false if assigned only)
     * $param[1] = list of contact_ids (positive) and group_ids (negative) to check assigned_contact_id against
     *             (including contact_id of the user we're building the collection for)
     *
     * @param array $param
     */
    protected function rightsFilterAdd($param)
    {
        if(count($param) < 2 || !is_array($param[0]) || !is_array($param[1])) {
            throw new waException('Bad parameters for rights filter.', 404);
        }

        // prepare contact ids
        foreach($param[1] as &$gid) {
            $gid = (int) $gid;
        }
        $contact_ids = implode(',', $param[1]);

        // build list of conditions
        $cond = array();
        foreach($param[0] as $wf_id => $all_allowed) {
            if ($all_allowed) {
                $cond[] = '(r.workflow_id='.((int)$wf_id).')';
            } else if ($contact_ids) {
                $cond[] = '(r.workflow_id='.((int)$wf_id).' AND r.assigned_contact_id IN ('.$contact_ids.'))';
            }
        }

        // apply conditions, if any
        if ($cond) {
            $this->where[] = implode(' OR ', $cond);
        } else {
            $this->where[] = '1=0';
        }
    }

    /**
     * Only show requests with given ids
     *
     * $param[0] = id or list of ids
     *
     * @param array $param
     */
    protected function idFilterAdd($param)
    {
        if (!$param) {
            throw new waException('ID filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }
        foreach($param as &$v) {
            $v = (int) $v;
        }

        $this->where[] = 'r.id IN ('.implode(',', $param).')';
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Request ID').'='.implode(',', $param);
    }

    protected function range_idFilterAdd($param, $op)
    {
        $header = '';
        if ($op === '>=') {
            $this->where[] = "r.id >= '" . $this->model->escape($param[0]) . "'";
            $header = _w('Request ID') . '>=' . $param[0];
        } else if ($op === '<=') {
            $this->where[] = "r.id <= '" . $this->model->escape($param[0]) . "'";
            $header = _w('Request ID') . '<=' . $param[0];
        } else if ($op === ':') {
            if (count($param) < 2) {
                $param = explode('--', $param[0]);
            }
            $this->where[] = "r.id >= '" . $this->model->escape($param[0]) . "' AND " .
                                    "r.id <= '" . $this->model->escape($param[1]) . "'";
            $header = _w('Request ID') . '=' . $param[0] . '–' . $param[1];
        } else {
            $this->where[] = '0';
        }

        if ($header) {
            $this->header .= ($this->header ? ', ' : '') . $header;
        }

    }


    /**
     * Unread requests: join with helpdesk_unread
     * @param array $param
     */
    protected function unreadFilterAdd($param)
    {
        $this->from[] = 'JOIN helpdesk_unread AS unr ON unr.request_id=r.id';
        $this->where[] = 'unr.contact_id='.wa()->getUser()->getId();
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Unread ');
    }

    /**
     * Unread requests: join with helpdesk_follow
     * @param array $param
     */
    protected function followFilterAdd($param)
    {
        $this->from[] = 'JOIN helpdesk_follow AS flm ON flm.request_id=r.id';
        $this->where[] = 'flm.contact_id='.wa()->getUser()->getId();
        $this->header .= $this->header ? ', ' : '';
        $this->header .= _w('Follow');
    }

    /**
     * Filter by last workflow action performed with the request
     * $param[0] = action_id, or list of ids, or comma-separated list
     * @param array $param
     */
    protected function last_action_idFilterAdd($param)
    {
        if (!$param) {
            throw new waException('ID filter requires at least one parameter.', 404);
        }
        $param = $param[0];
        if (!is_array($param)) {
            $param = explode(',', $param); // returns array(string) if there are no commas in string
        }

        $action_ids = array();
        foreach($param as $v) {
            $action_ids[] = $this->model->escape($v);
        }

        $this->from[] = 'JOIN helpdesk_request_log AS ll ON ll.id=r.last_log_id';
        $this->where[] = "ll.action_id IN ('".implode("','", $action_ids)."')";
        $this->header .= $this->header ? ', ' : '';
        $this->header .= 'last action = '.implode(', ', $param);
    }

    public static function parseSearchHash($hash, $assoc = false)
    {

        // Strings to temporary repace quoted literals with
        foreach(array('q_amp', 'q_colon', 'q_backtick', 'q_ge', 'q_le') as $var) {
            do {
                $$var = $var.rand();
            } while(FALSE !== strpos($hash, $$var));
        }

        // replace quoted literals with temporary values to simplify parsing
        $hash = str_replace(
            array('`&', '`:', '``', '`>=', '`<='),
            array($q_amp, $q_colon, $q_backtick, $q_ge, $q_le),
            $hash
        );

        // parse hash
        $result = array();
//        $this->filter_mark = null;
        foreach(explode('&', $hash) as $fltr) {
            if (!$fltr) {
                throw new waException('Bad filters parameter.');
            }

            $fltr = preg_split('/(:|>=|<=)/', $fltr, 3, PREG_SPLIT_DELIM_CAPTURE);
            $name = array_shift($fltr);
            $op = array_shift($fltr);
            $params = array();
            foreach($fltr as $param) {
                $params[] = str_replace(
                    array($q_amp, $q_colon, $q_backtick, $q_ge, $q_le),
                    array('&', ':', '`', '>=', '<='),
                $param);
            }
            $result[] = array(
                'name' => $name,
                'op' => $op,
                'params' => $params,
            );
//            if ($name == 'mark') {
//                $this->filter_mark = end($result);
//            }
        }

        if ($assoc) {
            $res = array();

            foreach ($result as $r) {
                $p = reset($r['params']);
                $name = $r['name'];
                $op = $r['op'];

                if ($name === 'search') {
                    $v = array();
                    foreach (preg_split('/\s+/', $p) as $t) {
                        $t = trim($t, '+ ');
                        if ($t) {
                            $v[] = $t;
                        }
                    }
                    $p = array(
                        implode(' ', $v),
                        ifset($r['params'][2], '')
                    );
                } else if (!in_array($name, array('c_name_id', 'id', 'c_email'))) {
                    if ($name === 'field') {
                        $name = 'field_' . $r['params'][0];
                        $p = array_slice($r['params'], 2);
                        $field = helpdeskRequestFields::getField($r['params'][0]);

                        $val = null;
                        if ($field) {
                            if ($field->getType() === 'Checkbox') {
                                $val = $r['params'][2];
                            } elseif ($field->getType() === 'Select') {
                                $val = explode(',', $p[0]);
                            }
                        }

                        if ($val === null) {
                            if (strpos($p[0], ',') !== false) {
                                $val = explode(',', $p[0]);
                            } else {
                                $val = $p[0];
                            }
                        }

                        $p = $val;

                    } else {
                        $p = preg_split('/,|--/', $p);
                    }
                }

                $res[$name] = array(
                    'op' => $op,
                    'val' => $p
                );
            }

            return $res;
        }

        return $result;
    }

    public function contactFormatName($contact, $bold_lastname = false)
    {
        if (!$bold_lastname) {
            return waContactNameField::formatName($contact);
        } else {
            $name = array();
            foreach(array('firstname', 'middlename', 'lastname') as $k) {
                if (!empty($contact[$k])) {
                    if ( ($val = trim($contact[$k])) || $k === '0') {
                        $val = htmlspecialchars($val);
                        if ($k === 'lastname') {
                            $val = "<strong>{$val}</strong>";
                        }
                        $name[] = $val;
                    }
                }
            }

            $name = trim(implode(' ', $name));

            if (empty($name)) {
                $name = htmlspecialchars(ifset($contact['name'], ''));
            }
            if (empty($name)) {
                $name = htmlspecialchars(ifset($contact['email'], ''));
            }
            if (empty($name) || $name == $contact['id']) {
                $name = htmlspecialchars(_w('<no name>'));
            }

            return $name;
        }
    }

}

