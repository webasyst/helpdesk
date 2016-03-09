<?php

$m = new waModel();
$m->exec("ALTER TABLE `helpdesk_request` CHANGE COLUMN `workflow_id` `workflow_id` INT(11) NULL DEFAULT NULL");
