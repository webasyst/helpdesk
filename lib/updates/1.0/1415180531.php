<?php

$settings_model = new waAppSettingsModel();
$locale = $settings_model->get('webasyst','locale','en_US');
$this->setLocale($locale);

$m = new waModel();

try {
    $m->query("SELECT creator_type FROM helpdesk_request WHERE 0");
} catch (waDbException $e) {

    $m->exec("
        ALTER TABLE helpdesk_request ADD creator_type VARCHAR(32) NOT NULL AFTER source_id
    ");
    $m->exec("
        UPDATE helpdesk_request, helpdesk_source SET helpdesk_request.creator_type='auth' WHERE helpdesk_source.id=helpdesk_request.source_id AND helpdesk_source.type='customerportal'
    ");
    $m->exec("
        UPDATE helpdesk_request, helpdesk_source SET helpdesk_request.creator_type='backend' WHERE helpdesk_source.id=helpdesk_request.source_id AND helpdesk_source.type='backend'
    ");
    $m->exec("
        UPDATE helpdesk_request, helpdesk_source SET helpdesk_request.creator_type='public' WHERE helpdesk_source.id=helpdesk_request.source_id AND helpdesk_source.type IN ('email', 'publicfrontend')
    ");

    $dmomain = wa()->getRouting()->getDomain();
    $sources = $m->query("SELECT id FROM helpdesk_source WHERE type='customerportal'")->fetchAll();
    foreach ($sources as $s) {
        try {
            $m->query("INSERT INTO helpdesk_source_params (`source_id`, `name`, `value`) VALUES (?, ?, ?)",
                $s['id'], 'fld_subject', serialize(array(
                    'caption' => _wd('helpdesk', 'Subject'),
                    'sort' => 0
                )));
            $m->query("INSERT INTO helpdesk_source_params (`source_id`, `name`, `value`) VALUES (?, ?, ?)",
                $s['id'], 'fld_text', serialize(array(
                    'caption' => _wd('helpdesk', 'Text'),
                    'sort' => 1
                )));
            $m->query("INSERT INTO helpdesk_source_params (`source_id`, `name`, `value`) VALUES (?, ?, ?)",
                $s['id'], 'fld_attachments', serialize(array(
                    'caption' => _wd('helpdesk', 'Attachments'),
                    'sort' => 2
                )));
            $m->query("INSERT INTO helpdesk_source_params (`source_id`, `name`, `value`) VALUES (?, ?, ?)",
                $s['id'], 'domain_' . $dmomain, 1);

        } catch (waDbException $e) {
        }
        $m->exec("
            UPDATE helpdesk_source SET `type`='form', `name`='" . _wd('helpdesk', 'New request') . "' WHERE id="
            . (int)$s['id'] . " AND `type`='customerportal'
        ");
    }

    $sources = $m->query("SELECT id FROM helpdesk_source WHERE type='publicfrontend'")->fetchAll();
    foreach ($sources as $s) {
		try {
            $m->query("INSERT INTO helpdesk_source_params (`source_id`, `name`, `value`) VALUES (?, ?, ?)",
                $s['id'], 'use_on_site', 1);
			
		} catch (waDbException $e) {
        }
	}

	
    try {
        $m->exec("UPDATE helpdesk_source SET `type`='form' WHERE `type`='publicfrontend'");

        $app_id = 'helpdesk';

        $_files = array();

        // rm actions
        $_files[wa($app_id)->getAppPath().'/lib/'] = array(
            'sources/helpdeskAbstractFormST.class.php', 'sources/helpdeskPublicfrontendSourceType.class.php', 'sources/helpdeskCustomerportalSourceType.class.php'
        );

        // rm templates
        $_files[wa($app_id)->getAppPath().'/lib/sources/templates/'] = array(
            'publicfrontend.html', 'publicfrontend_backend.html', 'publicfrontend_frontend.html', 'publicfrontend_settings.html'
        );

        foreach ($_files as $path => $fls) {
            foreach ($fls as $f) {
                $_file = $path . $f;
                if (file_exists($_file)) {
                    waFiles::delete($_file, true);
                }
            }
        }

    } catch (waDbException $e) {
    }

}
