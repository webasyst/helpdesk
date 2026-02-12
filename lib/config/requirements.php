<?php

// Installer will refuse to install or update the app,
// unless all requirements specified here are met.
return array(
    'app.installer' => array(
        'version' => 'latest',
        'strict' => true,
    ),
    'app.site' => array(
        'version' => '>=2.1.0.26683',
        'strict' => true,
    )
);
