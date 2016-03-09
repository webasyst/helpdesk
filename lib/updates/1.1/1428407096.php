<?php

$m = new helpdeskRequestLogModel();

if ($m->query("SELECT COUNT(*) FROM `helpdesk_request_log` WHERE workflow_id = 0")->fetchField() > 0) {
    $m->exec("UPDATE `helpdesk_request_log` l JOIN `helpdesk_request` r ON l.request_id = r.id
                SET l.workflow_id = r.workflow_id WHERE l.workflow_id = 0");
}
