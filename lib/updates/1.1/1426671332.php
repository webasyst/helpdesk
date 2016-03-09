<?php

$asm = new waAppSettingsModel();
$hrm = new helpdeskRightsModel();

if (!$asm->get('helpdesk', 'clear_duplicates_update')) {
    $asm->set('helpdesk', 'clear_duplicates_update', 1);

    // clear duplicates
    $all_unique_data = $hrm->query("SELECT DISTINCT contact_id, workflow_id, action_id, state_id FROM `helpdesk_rights`")->fetchAll();
    $hrm->exec("DELETE FROM `helpdesk_rights` WHERE 1");
    $hrm->multipleInsert($all_unique_data);
}