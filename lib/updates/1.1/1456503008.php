<?php

$m = new waModel();

$sql = "CREATE TABLE IF NOT EXISTS
  `helpdesk_faq_category_routes` (
    `category_id` int(11) NOT NULL,
    `route` varchar(255) NOT NULL,
    PRIMARY KEY (`category_id`, `route`)
)";

$m->exec($sql);