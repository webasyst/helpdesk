<?php

$m = new waModel();

$sql = "CREATE TABLE IF NOT EXISTS `helpdesk_tag` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `count` int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);


$sql = "
    CREATE TABLE IF NOT EXISTS `helpdesk_request_tags` (
        `request_id` int(11) NOT NULL,
        `tag_id` int(11) NOT NULL,
    PRIMARY KEY (`request_id`, `tag_id`)
)  ENGINE=MyISAM DEFAULT CHARSET=utf8";

$m->exec($sql);