<?php

$m = new waModel();

try {
    $m->query("SELECT `comment` FROM `helpdesk_faq` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq` ADD COLUMN `comment` TEXT");
}

