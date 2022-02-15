<?php
return array(
    'name' => 'Helpdesk',
    'icon' => array(
        16 => 'img/helpdesk16.png',
        24 => 'img/helpdesk24.png',
        48 => 'img/helpdesk.png',
        96 => 'img/helpdesk96.png',
    ),
    'version' => '1.2.19',
    'critical' => '1.0.0',
    'vendor' => 'webasyst',
    'csrf' => true,

    'rights' => true,
    'plugins' => true,
    'themes' => true,
    'pages' => true,

    'frontend' => true,
    'my_account' => true,

    'routing_params' => array(
        'main_form_id' => 3,
    ),
);
