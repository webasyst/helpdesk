<?php
/**
 * helpdesk_rights table has 3 fields: workflow_id, action_id and contact_id.
 * workflow_id is straightforward: it stores numeric workflow IDs.
 * contact_id stores contact or group Ids: contact ID when positive and group ID when negative.
 * action_id when starts with '!' stores special workflow rights such as request creation;
 *           when first char is not an '!' then it is a workflow action ID (string).
 */
class helpdeskRightConfig extends waRightConfig
{
    /**
     * @var helpdeskRightsModel
     */
    protected static $model = null;

    public function init()
    {
        wa('helpdesk');
        if (!self::$model) {
            self::$model = new helpdeskRightsModel();
        }

        $workflows = helpdeskWorkflow::getWorkflows();
        foreach ($workflows as $id => $wf) {
            if (count($workflows) > 1) {
                $this->addItem('wf.'.$id, sprintf(_w('Workflow &ldquo;%s&rdquo;'), $wf->getName()), 'header');
            }
            $this->addItem('wf.'.$id.'.create', _w('Can add new requests'), 'checkbox');
            $this->addItem('wf.'.$id.'.create_tag', _w('Can add new tags'), 'checkbox');
            $this->addItem('wf.'.$id.'.assigned_to_request', _w('Can be assigned to requests'), 'checkbox');

            $items = array();
            foreach($wf->getAllActions() as $a) {
                if ($a instanceof helpdeskWorkflowActionAutoInterface) {
                    continue;
                }
                $items['@'.$a->getId()] = $a->getName();
            }
            if ($items) {
                asort($items);
                $this->addItem('wf.'.$id.'.action', _w('Available actions'), 'list', array('items' => $items, 'hint1' => 'all_checkbox'));
            }

            $items = array();
            foreach ($wf->getAllStates() as $s) {
                $items['@'.$s->getId()] = $s->getName();
            }
            if ($items) {
                asort($items);
                $this->addItem('wf.'.$id.'.state', _w('Available states'), 'list', array('items' => $items, 'hint1' => 'all_checkbox'));
            }

        }
    }

    public function getDefaultRights($contact_id)
    {
        $rights = array();
        $workflows = helpdeskWorkflow::getWorkflows();
        foreach ($workflows as $id => $wf) {
            $rights['wf.'.$id.'.assigned_to_request'] = true;
        }
        return $rights;
    }

    public function getRights($contact_id)
    {
        $result = array();

        if (!is_array($contact_id)) {
            $contact_id = array($contact_id);
        }

        $wfs = array();
        foreach (self::$model->getByField('contact_id', $contact_id, true) as $row) {
            if ($row['action_id'] && $row['action_id'][0] == '!') {
                $result["wf.{$row['workflow_id']}.".substr($row['action_id'], 1)] = 1;
            } else {
                $result["wf.{$row['workflow_id']}.action.@".$row['action_id']] = 1;
            }
            if ($row['state_id'] && $row['state_id'][0] == '!') {
                $result["wf.{$row['workflow_id']}.".substr($row['state_id'], 1)] = 1;
            } else {
                $result["wf.{$row['workflow_id']}.state.@".$row['state_id']] = 1;
            }
            $wfs[$row['workflow_id']] = true;
        }

        return $result;
    }

    public function setRights($contact_id, $name, $value = null)
    {
        if (substr($name, 0, 3) != 'wf.') {
            return false;
        }

        $name_str = $name;

        $name = explode('.', $name);
        $wid = $name[1];
        $aid = $sid = null;
        if ($name[2] == 'action') {
            $aid = (isset($name[3]) && strlen($name[3])) ? $name[3] : '';
            if ($aid && $aid[0] == '@') {
                $aid = substr($aid, 1);
            } else {
                $aid = '!action.'.$aid;
            }
        } else if ($name[2] == 'state') {
            $sid = (isset($name[3]) && strlen($name[3])) ? $name[3] : '';
            if ($sid && $sid[0] == '@') {
                $sid = substr($sid, 1);
            } else {
                $sid = '!state.'.$sid;
            }
        } else if (in_array($name[2], array('create', 'create_tag', 'assigned_to_request'))) {
            $aid = '!' . $name[2];
        }

        if ($value) {
            $item = array(
                'contact_id' => $contact_id,
                'workflow_id' => $wid,
                'action_id' => $aid,
                'state_id' => $sid
            );
            $item_id = (int) self::$model->getByField($item);
            if ($item_id) {
                self::$model->updateById($item_id, $item);
            } else {
                self::$model->insert($item);
            }
        } else {
            if ($aid) {
                self::$model->deleteByField(array(
                    'contact_id' => $contact_id,
                    'workflow_id' => $wid,
                    'action_id' => $aid
                ));
                if ($aid === '!assigned_to_request') {
                    $crm = new waContactRightsModel();
                    $crm->deleteByField(array(
                        'group_id' => -$contact_id,
                        'app_id' => 'helpdesk',
                        'name' => $name_str
                    ));
                }
            } else {
                self::$model->deleteByField(array(
                    'contact_id' => $contact_id,
                    'workflow_id' => $wid,
                    'state_id' => $sid
                ));
            }
        }

        return true;
    }

    public function clearRights($contact_id)
    {
        self::$model->deleteByField(array('contact_id' => $contact_id));
    }

    public function getHTML($rights = array(), $inherited=null)
    {
        $html = parent::getHTML($rights, $inherited);
        $pre_text = _w('Users with limited access are not allowed to change application settings, e.g. sources, workflows, and etc.');

        // when there's no right to see all requests then always disable creation of new requests
        return <<<EOF
        <div class="block">{$pre_text}</div>
        {$html}
EOF;
    }

    public function getUI20HTML($rights = array(), $inherited = null)
    {
        $html = parent::getUI20HTML($rights, $inherited);
        $pre_text = _w('Users with limited access are not allowed to change application settings, e.g. sources, workflows, and etc.');
        return '<div class="alert"><div class="flexbox space-8"><i class="fas fa-info-circle gray"></i><span>' .
            _w('Users with limited access are not allowed to change application settings, e.g. sources, workflows, and etc.') .
            '</span></div></div>' . $html;
    }

}

