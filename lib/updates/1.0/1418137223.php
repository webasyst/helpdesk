<?php

$config = helpdeskWorkflow::getWorkflowsConfig();

$new_config = $config;

$prefix = 'state_';
$num = 1;
$all_state_ids_map = array();
foreach ($config['workflows'] as $wf_id => $wf_config) {
    if (!empty($wf_config['states'])) {
        foreach ($wf_config['states'] as $state_id => $st_config) {
            $state_id = trim($state_id);
            if (empty($state_id)) {
                $new_state_id = "{$prefix}{$num}";
                while (!empty($all_state_ids_map[$new_state_id])) {
                    $num += 1;
                    $new_state_id = "{$prefix}{$num}";
                }
                $st_config['id'] = $new_state_id;
                unset($new_config['workflows'][$wf_id]['states'][$state_id]);
                $new_config['workflows'][$wf_id]['states'][$new_state_id] = $st_config;
                $all_state_ids_map[$new_state_id] = true;
            } else {
                $all_state_ids_map[$state_id] = true;
            }
        }
    }
}

helpdeskWorkflow::saveWorkflowsConfig($new_config);