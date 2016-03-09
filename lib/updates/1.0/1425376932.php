<?php

$m = new helpdeskRightsModel();

$contacts = $m->query("SELECT DISTINCT group_id FROM `wa_contact_rights` WHERE app_id = 'helpdesk' AND name='backend'")->fetchAll(null, true);

foreach ($contacts as $c_id) {
    $c_id = -$c_id;
    foreach (helpdeskWorkflow::getWorkflows() as $w) {
        try {
            $m->exec("INSERT INTO `helpdesk_rights` SET contact_id=?, workflow_id=?, state_id='!state.all'", array(
                $c_id, $w->id
            ));
            $m->exec("INSERT INTO `helpdesk_rights` SET contact_id=?, workflow_id=?, action_id='!create'", array(
                $c_id, $w->id
            ));
        } catch (waDbException $e) {
        }
    }
}
