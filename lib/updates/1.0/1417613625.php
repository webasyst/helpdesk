<?php

$_file = wa('helpdesk')->getAppPath().'/templates/actions/faq/includeList.html';
if (file_exists($_file)) {
    waFiles::delete($_file, true);
}

$_file = wa('helpdesk')->getAppPath().'/lib/actions/faq/helpdeskFaqUrl.controller.php';
if (file_exists($_file)) {
    waFiles::delete($_file, true);
}