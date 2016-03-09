<?php

/**
  * Instances of this class represent request log history records.
  *
  * ORM class for helpdesk_request_log + helpdesk_request_log_params tables.
  *
  * DB access is lazy: no queries are made at construct time. Data is retrieved only when needed.
  * If record is not found in DB at access time, then waException is thrown.
  */
class helpdeskRequestLog extends helpdeskRecordWithFiles
{
    /** @var waContact cache for $this->getAuthor() */
    protected $author = null;

    /** @var helpdeskRequest cache for $this->getRequest() */
    protected $request = null;

    /** @var boolean Set by beforeSave() for afterSave() to know whether a record existed in DB before the save() call */
    protected $first_save = false;

    /** @param int $id request log id */
    public function __construct($id=null)
    {
        parent::__construct(new helpdeskRequestLogModel(), new helpdeskRequestLogParamsModel(), 'request_log_id', $id);
    }

    /** @return helpdeskRequest request that this log entry is attached to */
    public function getRequest()
    {
        if (!$this->request) {
            $this->request = new helpdeskRequest($this['request_id']);
        }
        return $this->request;
    }

    /** @return waContact author of this log entry, or null for system actions */
    public function getAuthor()
    {
        if ($this['actor_contact_id'] <= 0) {
            return null;
        }
        if (!$this->author) {
            $this->author = new waContact($this['actor_contact_id']);
        }
        return $this->author;
    }

    //
    // Protected functions
    //

    // default values for new record
    protected function getDefaultValues()
    {
        return array(
            'action_id' => null, // must be set before saving
            'request_id' => null, // must be set before saving
            'after_state_id' => null, // must be set before saving
            'before_state_id' => null, // taken from DB if not set
            'message_id' => null,
            'assigned_contact_id' => null,
            'actor_contact_id' => null,
            'datetime' => date('Y-m-d H:i:s'),
            'text' => '',
            'to' => '',
        ) + parent::getDefaultValues();
    }

    // validate request before saving
    protected function beforeSave()
    {
        if (!$this['request_id']) {
            throw new waException('Unable to save request history item with no request_id');
        }
        foreach(array('action_id'/*, 'after_state_id'*/) as $key) {
            if (!$this[$key] && $this[$key] !== '0' && $this[$key] !== 0) {
                throw new waException('Unable to save request history item with no '.$key);
            }
        }

        if (!$this['before_state_id']) {
            $this['before_state_id'] = $this->getRequest()->state_id;
        }

        if (!$this->id) {
            $this->first_save = true;
        }

        foreach(array('action_id' => 64, 'message_id' => 255) as $field => $limit) {
            if(mb_strlen($this[$field]) > $limit) {
                $this[$field] = mb_substr($this[$field], 0, $limit);
            }
        }

        parent::beforeSave();
    }

    // update helpdesk_request according to this log record
    protected function afterSave()
    {
        if ($this->first_save) {
            $r = $this->getRequest();
            $r->last_log_id = $this->id;
            $r->state_id = $this['after_state_id'];
            $r->save();
        }
        return parent::afterSave();
    }

    // abstract from helpdeskRecordWithFiles
    protected function attachmentsDir()
    {
        return helpdeskRequest::getAttachmentsDir($this['request_id'], $this->id);
    }

    // abstract from helpdeskRecordWithFiles
    protected function assetsDir()
    {
        return helpdeskRequest::getAssetsDir($this['request_id'], $this->id);
    }
}

