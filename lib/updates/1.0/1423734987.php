<?php

$m = new helpdeskFaqCategoryModel();

foreach ($m->query("SELECT * FROM `helpdesk_faq_category` WHERE url IS NULL OR url = ''") as $category)
{
    $m->updateById($category['id'], array(
        'url' => helpdeskHelper::transliterate($category['name'])
    ));
}