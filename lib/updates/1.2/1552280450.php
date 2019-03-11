<?php

$m = new waModel();

$columns = array('title', 'description', 'keywords');
foreach ($columns as $column) {
    try {
        $m->query('SELECT '.$column.' FROM `helpdesk_faq` WHERE 0');
    } catch (waDbException $e) {
        $m->exec('ALTER TABLE `helpdesk_faq` ADD COLUMN `'.$column.'` VARCHAR(255) NOT NULL');
    }
}

try {
    $m->query('SELECT `update_datetime` FROM `helpdesk_faq` WHERE 0');
} catch (waDbException $e) {
    $m->exec('ALTER TABLE `helpdesk_faq` ADD COLUMN `update_datetime` DATETIME NULL DEFAULT NULL');
}
