<?php

$routing_config_file = wa()->getConfigPath() . '/routing.php';
if (file_exists($routing_config_file)) {
    $changed = false;
    $routing_config = include($routing_config_file);
    foreach ($routing_config as $domain => &$domain_routings) {
        foreach ($domain_routings as &$route_options) {
            if ($route_options['app'] === 'helpdesk') {
                if (ifset($route_options['url_type']) == 1 && ifset($route_options['private']) != 1) {
                    $route_options['private'] = 1;
                    $changed = true;
                }
            }
        }
        unset($route_options);
    }
    unset($domain_routings);

    if ($changed) {
        waUtils::varExportToFile($routing_config, $routing_config_file);
    }
}

