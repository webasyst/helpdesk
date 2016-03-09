<?php

$m = new waModel();

$sql = "CREATE TABLE IF NOT EXISTS `helpdesk_messages_queue` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `created` DATETIME NULL DEFAULT NULL,
    `data` LONGBLOB NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `created` (`created`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);
