<?php

$m = new helpdeskRightsModel();

try {
    $m->query("SELECT state_id FROM `helpdesk_rights` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_rights` ADD COLUMN state_id VARCHAR(64) NULL DEFAULT NULL");
}

try {
    $m->query("SELECT id FROM `helpdesk_rights` WHERE 0");
} catch (waDbException $e) {
    try {
        $m->exec("ALTER TABLE `helpdesk_rights` DROP PRIMARY KEY"); // contact_id, workflow_id, action_id
    } catch (waDbException $e) {
        
    }
    $m->exec("ALTER TABLE `helpdesk_rights` ADD `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
    $m->exec("ALTER TABLE `helpdesk_rights` CHANGE COLUMN `action_id` `action_id` VARCHAR(64) NULL DEFAULT NULL");
    $m->exec("CREATE UNIQUE INDEX `key` ON `helpdesk_rights` (`contact_id`, `workflow_id`, `action_id`, `state_id`)");
}

$m->query("UPDATE `helpdesk_rights` SET action_id = NULL WHERE action_id = ''");