<?php

$_file = wa('helpdesk')->getAppPath().'/js/compiled';
if (file_exists($_file)) {
    waFiles::delete($_file, true);
}

$_file = wa('helpdesk')->getAppPath().'/js/vendor';
if (file_exists($_file)) {
    waFiles::delete($_file, true);
}