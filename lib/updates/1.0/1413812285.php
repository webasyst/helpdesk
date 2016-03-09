<?php

$m = new helpdeskSourceParamsModel();

foreach ($m->select('DISTINCT source_id')->fetchAll() as $item) {
    $source_id = $item['source_id'];
    try {
        $m->exec("UPDATE `helpdesk_source_params` SET name='fldc_email' WHERE name='fld_email' AND source_id = {$source_id}");
        $m->exec("DELETE FROM `helpdesk_source_params` WHERE name='fld_email' AND source_id = {$source_id}");
    } catch (waDbException $e) {

    }

    try {
        $m->exec("UPDATE `helpdesk_source_params` SET name='fldc_phone' WHERE name='fld_phone' AND source_id = {$source_id}");
        $m->exec("DELETE FROM `helpdesk_source_params` WHERE name='fld_phone' AND source_id = {$source_id}");
    } catch (waDbException $e) {

    }

    try {
        $m->query("UPDATE `helpdesk_source_params` SET name='fldc_name' WHERE name='fld_name' AND source_id = {$source_id}");
        $m->exec("DELETE FROM `helpdesk_source_params` WHERE name='fld_name' AND source_id = {$source_id}");
    } catch (waDbException $e) {

    }
}