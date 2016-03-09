<?php

/**
  * Instances of this class represent requests and simplify common tasks with them.
  *
  * ORM class for helpdesk_request + helpdesk_request_params tables.
  * ORM functionalyty is is moved from helpdeskRequest into a separate parent class for convenience.
  *
  * DB access is lazy: no queries are made at construct time. Data is retrieved only when needed.
  * If record is not found in DB at access time, then waException is thrown.
  */
class helpdeskRequestRecord extends helpdeskRecordWithFiles
{
    /** @var waContact cache for $this->getClient() */
    protected $client = null;
    protected $exists;

    /** @param int $id request_id */
    public function __construct($id=null)
    {
        parent::__construct(new helpdeskRequestModel(), new helpdeskRequestParamsModel(), 'request_id', $id, 'name', 'value', 255, 'long_value');
    }

    public function exists()
    {
        if ($this->exists === null) {
            $this->exists = !!$this->m->getById($this->id);
        }
        return $this->exists;
    }

    /**
      * Workflow this request is attached to
      * @return helpdeskWorkflow
      */
    public function getWorkflow()
    {
        return helpdeskWorkflow::getWorkflow($this['workflow_id']);
    }

    /**
     * @return helpdeskWorkflowState this request is currently in
     */
    public function getState()
    {
        return $this->getWorkflow()->getStateById($this['state_id']);
    }

    /**
     * Actions that can be performed on this request.
     * @return array action_id => helpdeskWorkflowAction
     */
    public function getActions()
    {
        return $this->getWorkflow()->getActions($this['state_id']);
    }

    /**
     * @return waContact
     * @throws waException when no client_contact_id specified, or no such contact exists
     */
    public function getClient()
    {
        if (!$this['client_contact_id']) {
            throw new waException('No client for helpdeskRequest.');
        }
        if (!$this->client || $this->client->getId() != $this['client_contact_id']) {
            $c = new waContact($this['client_contact_id']);
            $c->getName(); // throws exception when no contact exists
            $this->client = $c;
        }
        return $this->client;
    }

    /**
     * @return waContact
     * @throws waException when no creator_contact_id specified
     */
    public function getCreator()
    {
        if ($this['client_contact_id'] == $this['creator_contact_id']) {
            return $this->getClient();
        }
        if (!$this['creator_contact_id']) {
            throw new waException('No creator for helpdeskRequest.');
        }
        $c = new waContact($this['creator_contact_id']);
        $c->getName();
        return $c;
    }

    /**
     * @return helpdeskSource
     * @throws waException when source does not exist
     */
    public function getSource()
    {
        $source = helpdeskSource::get($this->source_id);
        $source->name; // make sure it exists; throws 404 if not found
        return $source;
    }

    /**
     * @param int $user_id contact id if positive, group id if negative; defaults to current user logged in
     * @return boolean true if user has access to this request, false otherwise
     */
    public function isVisibleForUser($user_id = null)
    {
        $rm = new helpdeskRightsModel();
        if (wa()->getUser()->getRights('helpdesk', 'backend') > 1) {
            return true;
        }
        $state_rights = $rm->getWorkflowStatesRights($user_id === null ? wa()->getUser()->getId() : $user_id);
        return !empty($state_rights[$this->workflow_id][$this->state_id]) || !empty($state_rights[$this->workflow_id]['!state.all']);
    }

    //
    // Protected functions
    //

    // default values for new record
    protected function getDefaultValues()
    {
        $time = date('Y-m-d H:i:s');
        return array(
            'workflow_id' => null, // must be set before saving
            'source_id' => null, // must be set before saving
            'state_id' => null, // must be set before saving
            'summary' => '',
            'text' => '',
            'rating' => 0,
            'closed' => null,
            'created' => $time,
            'updated' => $time,
            'message_id' => null,
            'client_contact_id' => 0,
            'creator_contact_id' => 0,
            'assigned_contact_id' => 0,
        ) + parent::getDefaultValues();
    }

    // validate and prepare request data before saving
    protected function beforeSave()
    {
        if ($this['source_id'] === null) {
            throw new waException('Unable to save request with no source id.');
        }
        if ($this['workflow_id'] === null) {
            //throw new waException('Unable to save request with no workflow id.');
        }
        if ($this['state_id'] === null) {
            //throw new waException('Unable to save request with no state.');
        }

        foreach(array('summary' => 255, 'message_id' => 255) as $field => $limit) {
            if(mb_strlen($this[$field]) > $limit) {
                $this[$field] = mb_substr($this[$field], 0, $limit);
            }
        }

        parent::beforeSave();
    }

    // abstract from helpdeskRecordWithFiles
    protected function attachmentsDir()
    {
        return helpdeskRequest::getAttachmentsDir($this->id);
    }

    // abstract from helpdeskRecordWithFiles
    protected function assetsDir()
    {
        return helpdeskRequest::getAssetsDir($this->id);
    }

    public function delete()
    {
        $this->beforeDelete();
        $this->clearPersistent();
        $this->m->delete($this->id);
        $this->afterDelete();
        $this->id = null;
        $this->exists = false;
    }
}

