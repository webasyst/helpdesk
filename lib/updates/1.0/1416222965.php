<?php

$m = new waModel();

try {
    $m->exec("
        INSERT INTO helpdesk_source_params (source_id, name, value)
        SELECT p1.source_id, 'receipt', '1'
        FROM helpdesk_source_params p1
        INNER JOIN helpdesk_source_params p2 ON p2.source_id=p1.source_id AND p2.name='fldc_email'
        LEFT JOIN helpdesk_source_params p3 ON p3.source_id=p1.source_id AND p3.name='receipt'
        WHERE p1.name='receipt_template' AND p3.value IS NULL
    ");
} catch (waDbException $e) {
}
