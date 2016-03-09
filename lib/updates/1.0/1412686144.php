<?php

$m = new waModel();

try {
    $m->query("SELECT icon FROM helpdesk_faq_category WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq_category` ADD COLUMN icon VARCHAR(255) NULL DEFAULT NULL AFTER name");
}