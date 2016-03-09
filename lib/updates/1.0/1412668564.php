<?php

$m = new waModel();

$sql = "CREATE TABLE IF NOT EXISTS `helpdesk_faq` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `faq_category_id` int(11) NOT NULL,
    `question` text NOT NULL,
    `answer` text NOT NULL,
    `contact_id` int(11) NOT NULL DEFAULT 0,
    `create_datetime` DATETIME NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);

$sql = "CREATE TABLE IF NOT EXISTS `helpdesk_faq_category` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `sort` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);



