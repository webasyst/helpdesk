<?php

$m = new helpdeskRequestLogModel();

try {
    $m->query("SELECT `workflow_id` FROM `helpdesk_request_log` WHERE 0");
} catch (waDbException $e) {
    $m->query("ALTER TABLE `helpdesk_request_log` ADD COLUMN `workflow_id` INT(11) NOT NULL");    
}

if (!$m->query("SELECT `workflow_id` FROM `helpdesk_request_log` LIMIT 1")->fetchField()) {
    $m->exec("UPDATE `helpdesk_request_log` l JOIN `helpdesk_request` r ON l.request_id = r.id
                SET l.workflow_id = r.workflow_id");
}