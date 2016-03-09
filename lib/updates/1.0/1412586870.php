<?php

$m = new waModel();

$sql = "CREATE TABLE IF NOT EXISTS `helpdesk_request_data` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `request_id` int(11) NOT NULL,
    `field` varchar(32) NOT NULL,
    `value` varchar(255) NOT NULL,
    `sort` int(11) NOT NULL DEFAULT 0,
    `status` int(11) NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `id_field_status` (`request_id`,`field`,`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);