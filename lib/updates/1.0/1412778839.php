<?php

$m = new waModel();

try {
    $m->query("SELECT sort FROM `helpdesk_faq` WHERE 0");
} catch (waDbException $e) {
    $m->exec("ALTER TABLE `helpdesk_faq` ADD COLUMN sort int(11) NOT NULL DEFAULT 0");
}

$sql = "SELECT * FROM `helpdesk_faq` ORDER BY faq_category_id";
$category_id = null;
$sort = 0;
foreach ($m->query($sql)->fetchAll() as $faq) {
    if ($category_id !== $faq['faq_category_id']) {
        $category_id = $faq['faq_category_id'];
        $sort = 0;
    }
    $m->exec("UPDATE `helpdesk_faq` SET sort = {$sort} WHERE id = {$faq['id']}");
    $sort += 1;
}
