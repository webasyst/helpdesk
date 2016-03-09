<?php

$m = new waModel();

$sql = "DELETE sp FROM helpdesk_source_params sp
        JOIN helpdesk_source s ON sp.source_id = s.id
        WHERE s.type = 'backend' AND sp.name = 'workflow'";
$m->exec($sql);