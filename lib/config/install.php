<?php

//
// This script is executed during installation
// after database tables are created. Also see db.php
//

$cfg = $this;

// Create default workflow, if not exists
$workflows_cfg = $cfg->getConfigPath('workflows.php');
if (!file_exists($workflows_cfg)) {
    $locales = array(wa()->getLocale(), 'en_US');
    foreach($locales as $locale) {
        $file = $cfg->getAppConfigPath('install/workflows.'.$locale);
        if (file_exists($file)) {
            waFiles::copy($file, $workflows_cfg);
            break;
        }
    }

    waFiles::copy(
        $cfg->getAppConfigPath('install/graph'),
        $cfg->getConfigPath('graph.php')
    );
}

// Create default sources, if not exist
if (wao(new helpdeskSourceModel())->countAll() <= 0) {

    // Backend
    helpdeskSource::get(1)->setAll(array(
        'type' => 'backend',
        'name' => _w('Backend'),
        'params' => array(
            'new_request_state_id' => 'new',
            'fld_subject' => '1',
        ),
    ))->save();

    // Public frontend forms
    helpdeskSource::get(2)->setAll(array(
        'type' => 'form',
        'name' => _w('New request'),
        'params' => array(
            'workflow' => '1',
            'new_request_state_id' => 'new',
            'button_caption' => _w('Send'),
            'locale' => wa()->getLocale(),
            'new_request_assign_contact_id' => '',
            'fld_subject' => array(
                'caption' => _w('Subject'),
                'placeholder' => '',
            ),
            'fld_text' => array (
              'caption' => _w('Text'),
              'placeholder' => '',
            ),
            'fld_attachments' => array (
              'caption' => _w('Attachments'),
            ),

            'html_after_submit' => helpdeskFormSourceType::getDefaultParam('html_after_submit'),
            'antispam_mail_template' => helpdeskCommonST::getDefaultParam('antispam_mail_template'),

            'domain_' . wa()->getRouting()->getDomain() => 1,
       ),
    ))->save();

    helpdeskSource::get(3)->setAll(array(
        'type' => 'form',
        'name' => _w('Support request'),
        'params' => array(
            'workflow' => '1',
            'new_request_state_id' => 'new',
            'button_caption' => _w('Send'),
            'locale' => wa()->getLocale(),
            'new_request_assign_contact_id' => '',
            'fldc_name' => array(
                'caption' => _w('Name'),
                'placeholder' => '',
            ),
            'fldc_email' => array(
                'caption' => _w('Email'),
                'placeholder' => 'your@email',
            ),
            'fld_subject' => array(
                'caption' => _w('Subject'),
                'placeholder' => '',
            ),
            'fld_text' => array (
                'caption' => _w('Text'),
                'placeholder' => '',
            ),
            'fld_attachments' => array (
                'caption' => _w('Attachments'),
            ),
            'html_after_submit' => helpdeskFormSourceType::getDefaultParam('html_after_submit'),
            'antispam_mail_template' => helpdeskCommonST::getDefaultParam('antispam_mail_template'),
            'use_on_site' => 1,

            'domain_' . wa()->getRouting()->getDomain() => 0,
        ),
    ))->save();

}

// Filters in sidebar
if (wao(new helpdeskFilterModel())->countAll() <= 0) {
    wao(new helpdeskFilter())->setAll(array(
        'shared' => 1,
        'contact_id' => wa('webasyst')->getUser()->getId(),
        'name' => _w('Request states'),
        'hash' => '@by_states',
    ))->save();
}

// Auto increment starts with 1001 for new requests
$rm = new helpdeskRequestModel();
if ($rm->countAll() <= 0) {
    $rm->query("ALTER TABLE helpdesk_request AUTO_INCREMENT=1001");
}

// Mark request as "read" default value
$csm = new waContactSettingsModel();
$csm->set(wa()->getUser()->getId(), 'helpdesk', array(
    'mark_read_when_open' => 1
));

if (waLocale::getLocale() === 'ru_RU') {
    // create rate select-field for one-click-feedback
    $rate_field = new helpdeskRequestSelectField('rate',
        array('en_US' => 'Rate', 'ru_RU' => 'Оценка'),
        array(
            'options' => array(
                'отлично' => 'отлично',
                'хорошо' => 'хорошо',
                'не очень' => 'не очень',
                'плохо' => 'плохо'
            ),
            'my_visible' => '1'
        )
    );
} else {
    // create rate select-field for one-click-feedback
    $rate_field = new helpdeskRequestSelectField('rate',
        array('en_US' => 'Rate'),
        array(
            'options' => array(
                'excellent' => 'excellent',
                'good' => 'good',
                'moderate' => 'moderate',
                'bad' => 'bad'
            ),
            'my_visible' => '1'
        )
    );
}
if (!helpdeskRequestFields::fieldExists($rate_field->getId())) {
    helpdeskRequestFields::updateField($rate_field);
}


/**
 * @event installed
 * @return void
 */
wa('helpdesk')->event('installed');

