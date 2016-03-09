<?php

$app_id = 'helpdesk';

$_files = array();

// rm actions
$_files[wa($app_id)->getAppPath().'/lib/actions/'] = array(
    'filters/helpdeskFiltersDialog.action.php'
);

// rm templates
$_files[wa($app_id)->getAppPath().'/templates/actions/'] = array(
    'filters'
);

foreach ($_files as $path => $fls) {
    foreach ($fls as $f) {
        $_file = $path . $f;
        if (file_exists($_file)) {
            waFiles::delete($_file, true);
        }
    }
}