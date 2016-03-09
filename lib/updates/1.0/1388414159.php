<?php
$mod = new waModel();
try {
    $mod->exec("
        ALTER TABLE `helpdesk_request` DROP INDEX `text`
    ");
} catch (Exception $e) {
}

try {
    $mod->exec("
        ALTER TABLE `helpdesk_request` DROP INDEX `summary`
    ");
} catch (Exception $e) {
}

try {
    $mod->exec("
        ALTER TABLE `helpdesk_request` ADD FULLTEXT KEY `text_summary` (`text`,`summary`)
    ");
} catch (Exception $e) {
}

