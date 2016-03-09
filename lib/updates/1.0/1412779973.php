<?php

$m = new waModel();

try {
    $m->exec("SELECT count FROM `helpdesk_faq_category` WHERE 0");
} catch (waDbException $e) {
    $m->query("ALTER TABLE `helpdesk_faq_category` ADD COLUMN `count` int(11) NOT NULL DEFAULT 0");
}

$sql = "
    UPDATE `helpdesk_faq_category` fc JOIN (
        SELECT fc.id, COUNT(*) AS count
        FROM `helpdesk_faq_category` fc 
        JOIN `helpdesk_faq` f ON f.faq_category_id = fc.id
        GROUP BY f.faq_category_id
    ) t ON fc.id = t.id
    SET fc.count = t.count";
$m->exec($sql);