<?php

$m = new helpdeskRightsModel();

$exists = false;
$data = $m->query('SHOW INDEX FROM helpdesk_rights')->fetchAll();
foreach ($data as $item) {
    if ($item['Key_name'] === 'key') {
        $exists = true;
        break;
    }
}

if (!$exists) {
    $all_unique_data = $m->query("SELECT DISTINCT contact_id, workflow_id, action_id, state_id FROM `helpdesk_rights`")->fetchAll();
    $m->exec("DELETE FROM `helpdesk_rights` WHERE 1");
    $m->multipleInsert($all_unique_data);
    $m->exec('CREATE UNIQUE INDEX `key` ON `helpdesk_rights` (`contact_id`, `workflow_id`, `action_id`, `state_id`)');
}