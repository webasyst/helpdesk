<?php

$m = new waModel();

$m->query("CREATE TABLE IF NOT EXISTS `helpdesk_follow` (
    `contact_id` bigint(20) NOT NULL,
    `request_id` bigint(20) NOT NULL,
    PRIMARY KEY `request_contact` (`request_id`, `contact_id`)
)  ENGINE=MyISAM DEFAULT CHARSET=utf8");
