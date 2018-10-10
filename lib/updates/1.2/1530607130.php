<?php

$model = new waModel();

try {
    $model->exec("ALTER TABLE helpdesk_page MODIFY content mediumtext NOT NULL");
} catch (waException $e) {

}