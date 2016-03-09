<?php

$m = new waModel();
$m->exec("DELETE FROM `helpdesk_filter` WHERE hash = '@all'");

try {
    $m->query("SELECT icon FROM `helpdesk_filter` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_filter` ADD COLUMN `icon` VARCHAR(255) NULL DEFAULT NULL");
}