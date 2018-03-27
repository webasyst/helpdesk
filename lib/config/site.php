<?php

wa('helpdesk');
$sm = new helpdeskSourceModel();
$spm = new helpdeskSourceParamsModel();
$sql = "SELECT s.* FROM {$sm->getTableName()} s
    INNER JOIN {$spm->getTableName()} p ON p.source_id = s.id AND p.name='use_on_site' AND p.value = 1
    WHERE s.type='form'";
$forms = $sm->query($sql)->fetchAll('id');
$forms = array(0 => array('name' => _w('No request form'))) + $forms;

// Dirty hack, because of not custom controls isn't supported
$url_type_script = '<script>$(function() {

    var private_checkbox = $("input[name=\'params[private]\']");
    var private_hidden = $("<input type=\'hidden\' name=\'params[private]\' value=\'1\' disabled=\'disabled\'>")
                            .insertAfter(private_checkbox);
    var main_form_select = $("select[name=\'params[main_form_id]\']");
    var url_type_radio = $("input[name=\'params[url_type]\']");
    url_type_radio.change(function() {
        var el = $(this);
        if (el.val() === "1") {
            private_checkbox.attr("disabled", true).attr("checked", true);
            main_form_select.attr("disabled", true).val(0);
            private_hidden.attr("disabled", false);
        } else {
            private_checkbox.attr("disabled", false);
            private_hidden.attr("disabled", true);
            main_form_select.attr("disabled", false);
        }
    }).filter(":checked").change();
});</script>';

return array(
    'params' => array(
        'url_type' => array(
            'name' => _w('Settlement type'),
            'type' => 'radio_select',
            'items' => array(
                0 => array(
                    'name' => _w('With public frontend'),
                    'description' => _w('<br>Includes Customer Portal') . $url_type_script,
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
