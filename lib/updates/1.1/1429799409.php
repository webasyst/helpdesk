<?php

// update format of composite field data in helpdesk_requet_data

$sql_replace_map = array();
$where_in = array();
foreach (waContactFields::getAll('all') as $field_id => $field) {
    if ($field instanceof waContactCompositeField) {
        $prefix = helpdeskRequestDataModel::PREFIX_CONTACT . $field_id;
        foreach ($field->getFields() as $subfield_id => $subfield) {
            $from = $prefix . '_' . $subfield_id;
            $to = $prefix . ':' . $subfield_id;
            $sql_replace_map[] = "WHEN '{$from}' THEN '{$to}'";
            $where_in[] = $from;
        }
    }
}

if ($sql_replace_map) {
    $m = new waModel();
    $where_in_str = "field IN ('" . implode("','", $where_in) . "')";
    $count = $m->query("SELECT COUNT(*) FROM `helpdesk_request_data` WHERE {$where_in_str}")->fetchField();
    if ($count > 0) {
        $replace_map_str = "CASE field\n" . implode("\n", $sql_replace_map) . " ELSE field \nEND";
        $sql = "UPDATE `helpdesk_request_data` hrd JOIN(
                    SELECT id, {$replace_map_str } AS field FROM `helpdesk_request_data`
                    WHERE {$where_in_str}
                ) t ON hrd.id = t.id
                SET hrd.field = t.field
            ";
        $m->exec($sql);
    }
}