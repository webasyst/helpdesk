<?php

$m = new waModel();

try {
    $m->query('SELECT view_type FROM `helpdesk_faq_category` WHERE 0');
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq_category` ADD COLUMN `view_type` VARCHAR(64) NOT NULL DEFAULT 'separate'");
}
