<?php

$m = new waModel();
$m->query("ALTER TABLE `helpdesk_request_data` CHANGE `field` `field` VARCHAR(255) NOT NULL");