<?php

$m = new waModel();
$m->exec("DELETE FROM `helpdesk_filter` WHERE hash = '@all'");
