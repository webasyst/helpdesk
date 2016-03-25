<?php

// stamp about that since this time action actions feature is released
$asm = new waAppSettingsModel();
if (!$asm->get('helpdesk', 'auto_actions_feature_release')) {
    $asm->set('helpdesk', 'auto_actions_feature_release', date('Y-m-d H:i:s'));
}

// change type of id in request log model
$rlm = new helpdeskRequestLogModel();
$meta = $rlm->getMetadata();
if (!empty($meta['actor_contact_id']['unsigned'])) {
    $rlm->exec("ALTER TABLE `helpdesk_request_log` CHANGE COLUMN actor_contact_id actor_contact_id BIGINT (20) NOT NULL");
}