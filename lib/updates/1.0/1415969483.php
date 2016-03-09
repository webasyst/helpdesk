<?php

$m = new waModel();

try {
    $m->query("SELECT is_public FROM helpdesk_faq_category WHERE 0");
} catch (waDbException $e) {

    try {
        $m->exec("
            ALTER TABLE `helpdesk_faq_category` ADD `is_public` TINYINT(1) NOT NULL DEFAULT '0' , ADD `url` VARCHAR(255) NULL
        ");
    } catch (waDbException $e) {
    }
}

try {
    $m->query("SELECT is_public FROM helpdesk_faq WHERE 0");
} catch (waDbException $e) {

    try {
        $m->exec("
            ALTER TABLE `helpdesk_faq` ADD `is_public` TINYINT(1) NOT NULL DEFAULT '0'
        ");
    } catch (waDbException $e) {
    }
    try {
        $m->exec("
            ALTER TABLE `helpdesk_faq` ADD `url` VARCHAR(255)
        ");
    } catch (waDbException $e) {
    }
}
