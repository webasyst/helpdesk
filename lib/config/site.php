<?php

wa('helpdesk');
$sm = new helpdeskSourceModel();
$spm = new helpdeskSourceParamsModel();
$sql = "SELECT s.* FROM {$sm->getTableName()} s
    INNER JOIN {$spm->getTableName()} p ON p.source_id = s.id AND p.name='use_on_site' AND p.value = 1
    WHERE s.type='form'";
$forms = $sm->query($sql)->fetchAll('id');
$forms = array(0 => array('name' => _w('No request form'))) + $forms;

return array(
    'params' => array(
        'url_type' => array(
            'name' => _w('Settlement type'),
            'type' => 'radio_select',
            'items' => array(
                0 => array(
                    'name' => _w('With public frontend'),
                    'description' => _w('<br>Includes Customer Portal'),
                ),
                1 => array(
                    'name' => _w('Customer Portal only'),
                    'description' => '',
                ),
            )
        ),
        'main_form_id' => array(
            'name' => _w('Main request form'),
            'type' => 'select',
            'items' => $forms,
        ),
    ),
);
