<?php
return array(
    'name' => 'Helpdesk',
    'icon' => 'img/helpdesk.svg',
    'version' => '2.0.0',
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

    'ui' => '1.3,2.0'
);