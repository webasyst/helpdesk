<?php
/**
 * Instances of this class represent workflow sources: pieces of code that
 * create new Requests based on user input (emails, forms, etc.)
 *
 * Each source has a type represented by subclasses of helpdeskSourceType.
 * Corresponding SourceType class contains all logic, whereas this class
 * is just a simple container for settings.
 */
class helpdeskSource extends helpdeskRecordWithParams
{
    //
    // Static
    //

    public static function getBackendSource()
    {
        $m = new helpdeskSourceModel();
        $item = $m->getByField(array(
            'type' => 'backend',
            'status' => 1
        ));
        if (!$item) {
            throw new waException('Backend source not found.');
        }
        return self::get($item['id']);
    }

    public static function getFirstEmailSource()
    {
        $m = new helpdeskSourceModel();
        $item = $m->getByField(array(
            'type' => 'email',
            'status' => 1
        ));
        if (!$item) {
            throw new waException('Email source not found.');
        }
        return self::get($item['id']);
    }

    /**
      * Get source by id. Kinda factory.
      * @param int|array $id
      * @return helpdeskSource
      */
    public static function get($id)
    {
        if ($id === 'backend') {
            return self::getBackendSource();
        }
        if (!is_array($id)) {
            return new helpdeskSource($id);
        }
        if (isset($id['id'])) {
            $r = new helpdeskSource($id['id']);
            $r->load($id);
        } else {
            $r = new helpdeskSource();
            $r->setAll($id);
        }
        return $r;
    }

    //
    // Non-static
    //

    /** @var helpdeskSourceType cache for $this->getSourceType() */
    protected $source_type = false;

    /**
      * @param int|array $id source id
      */
    public function __construct($id = null)
    {
        parent::__construct(new helpdeskSourceModel(), new helpdeskSourceParamsModel(), 'source_id', $id);

        if ($id) {
            try {
                $st = $this->getSourceType();
                if ($st instanceof helpdeskBackendSourceType) {

                    if (!empty($this->params->new_request_state_id)) {
                        $workflow_id = '';
                        $state_id = $this->params->new_request_state_id;
                        if (strstr($this->params->new_request_state_id, '@') === false) {
                            try {
                                $wf = helpdeskWorkflow::get();
                                $workflow_id = $wf->getId();
                            } catch (waException $e) {}
                        } else {
                            $state_id_ar = explode('@', $this->params->new_request_state_id, 2);
                            $workflow_id = $state_id_ar[0];
                            $state_id = ifset($state_id_ar[1]);
                        }
                        $all_states = helpdeskHelper::getAllWorkflowsWithStates(false);
                        if (!isset($all_states[$workflow_id]['states'][$state_id])) {
                            $this->params->new_request_state_id = '';
                        } else {
                            $this->params->new_request_state_id = $workflow_id . '@' . $state_id;
                        }
                    }

                    if (!empty($this->params->assigned_contact_id) && $this->params->assigned_contact_id != '0') {
                        $assigned_id = $this->params->assigned_contact_id;
                        if ($assigned_id < 0) {
                            $gm = new waGroupModel();
                            if (!$gm->getById(-$assigned_id)) {
                                $this->params->assigned_contact_id = '0';
                            }
                        } else if ($assigned_id > 0) {
                            $cm = new waContactModel();
                            if (!$cm->getById($assigned_id)) {
                                $this->params->assigned_contact_id = '0';
                            }
                        }
                    }
                }
            } catch (waException $e) {

            }
        }
    }

    /**
      * Get an object representing this source's type.
      * @return helpdeskSourceType
      */
    public function getSourceType()
    {
        if ($this->source_type === false) {
            $this->source_type = helpdeskSourceType::get($this['type']);
        }
        return $this->source_type;
    }

    protected function getDefaultValues()
    {
        return array(
            'id' => '',
            'type' => '',
            'name' => '',
            'status' => '1',
        ) + parent::getDefaultValues();
    }

    /** Short for $this->getSourceType()->describeBehaviour($this) */
    public function describeBehaviour()
    {
        return $this->getSourceType()->describeBehaviour($this);
    }
}

