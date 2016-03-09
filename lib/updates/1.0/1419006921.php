<?php

foreach (array(
    'form_backend.html',
    'include_form_constructor_preview.html',
    'include_form_frontend_css.html',
    'email_settings_rtr.html',
    'form_frontend.html',
    'backend_receipt_preview.html',
    'customerportal.html',
    'form.html',
    'form_settings.html',
    'backend.html',
    'backend_settings.html',
    'email_settings.html',
    'form_constructor.html') as $_file)
{
    $_file_path = wa('helpdesk')->getAppPath().'/lib/sources/templates/' . $_file;
    if (file_exists($_file_path)) {
        waFiles::delete($_file_path);
    }
}

$m = new waModel();
$m->exec(
    "UPDATE `helpdesk_source` hs
    JOIN `helpdesk_source_params` hsp ON hs.id = hsp.source_id
    SET hsp.name = 'frontend_css_type', hsp.value = 'custom'
    WHERE hs.type = 'form' AND hsp.name = 'custom_css_available' AND hsp.value != ''"
);
$m->exec(
    "UPDATE `helpdesk_source` hs
    JOIN `helpdesk_source_params` hsp ON hs.id = hsp.source_id
    SET hsp.name = 'frontend_css_type'
    WHERE hs.type = 'form' AND hsp.name = 'custom_css_available' AND hsp.value = ''"
    );