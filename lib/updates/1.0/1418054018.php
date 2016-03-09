<?php

$m = new waModel();

try {
    $m->query("SELECT is_backend FROM `helpdesk_faq` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq` ADD COLUMN is_backend TINYINT(1) NOT NULL DEFAULT 0");
}

try {
    $m->query("SELECT is_backend FROM `helpdesk_faq_category` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq_category` ADD COLUMN is_backend TINYINT(1) NOT NULL DEFAULT 0");
}