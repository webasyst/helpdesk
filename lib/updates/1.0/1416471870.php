<?php

$m = new waModel();

try {
    $m->exec("
        CREATE TABLE IF NOT EXISTS `helpdesk_page` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              `title` varchar(255) NOT NULL DEFAULT '',
              `url` varchar(255) DEFAULT NULL,
              `full_url` varchar(255) DEFAULT NULL,
              `content` text NOT NULL,
              `create_datetime` datetime NOT NULL,
              `update_datetime` datetime NOT NULL,
              `create_contact_id` int(11) NOT NULL,
              `sort` int(11) NOT NULL DEFAULT '0',
              `status` tinyint(1) NOT NULL DEFAULT '0',
              `domain` varchar(255) DEFAULT NULL,
              `route` varchar(255) DEFAULT NULL,
              `parent_id` int(11) DEFAULT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
    ");
} catch (waDbException $e) {
}

try {
    $m->exec("
        CREATE TABLE IF NOT EXISTS `helpdesk_page_params` (
          `page_id` int(11) NOT NULL,
          `name` varchar(255) NOT NULL,
          `value` text NOT NULL,
          PRIMARY KEY `page_name` (`page_id`,`name`)
        ) ENGINE=MyISAM DEFAULT CHARSET=utf8;
    ");
} catch (waDbException $e) {
}
