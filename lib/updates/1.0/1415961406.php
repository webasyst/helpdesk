<?php

$config = helpdeskWorkflow::getWorkflowsConfig();

foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
    $actions = $wf->getAllActions();
    foreach ($actions as $a_id => $action) {
        $options = $action->getOptions();
        if (!empty($options['assignees_options'])) {
            $config['workflows'][$wf_id]['actions'][$a_id]['options']['assignment'] = 1;
            $config['workflows'][$wf_id]['actions'][$a_id]['options']['allow_choose_assign'] = 1;
            unset($config['workflows'][$wf_id]['actions'][$a_id]['options']['assignees_options']);
        }
    }
}

helpdeskWorkflow::saveWorkflowsConfig($config);