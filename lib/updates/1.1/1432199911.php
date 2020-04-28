<?php

$config = helpdeskWorkflow::getWorkflowsConfig();

$sep = '{SEPARATOR}';

$changed = false;

foreach (helpdeskWorkflow::getWorkflows() as $wf_id => $wf) {
    $actions = $wf->getAllActions();
    foreach ($actions as $a_id => $action) {
        $options = $action->getOptions();
        foreach ($options['messages'] as $i => $message) {
            if (stripos($message['tmpl'], '<br') === false) {
                $changed = true;

                $tmpl = $message['tmpl'];
                $tmpl_sep = explode($sep, $tmpl);
                if (empty($tmpl_sep[2])) {
                    continue;
                }
                $tmpl_sep[2] = trim($tmpl_sep[2]);
                if ($tmpl_sep[2][0] !== '<') {
                    $tmpl_sep[2] = '<p>' . $tmpl_sep[2] . '</p>';
                }
                $tmpl = implode($sep, $tmpl_sep);

                $config['workflows'][$wf_id]['actions'][$a_id]['options']['messages'][$i]['tmpl'] =
                    str_replace(
                        "\n",
                        "<br>",
                        str_replace(
                            "\r",
                            "<br>",
                            str_replace("\r\n", "<br>", $tmpl)
                        )
                );
            }
        }
    }
}

if ($changed) {
    helpdeskWorkflow::saveWorkflowsConfig($config);
}

$spm = new helpdeskSourceParamsModel();

// messages
foreach ($spm->getByField('name', 'messages', true) as $item) {
    $source_id = $item['source_id'];
    if ($item['value']) {
        $messages = @unserialize($item['value']);
        if (is_array($messages)) {
            $changed = false;
            foreach ($messages as &$message) {
                if (stripos($message['tmpl'], '<br') === false) {
                    $changed = true;

                    $tmpl = $message['tmpl'];
                    $tmpl_sep = explode($sep, $tmpl);
                    if (empty($tmpl_sep[2])) {
                        continue;
                    }
                    $tmpl_sep[2] = trim($tmpl_sep[2]);
                    if ($tmpl_sep[2][0] !== '<') {
                        $tmpl_sep[2] = '<p>' . $tmpl_sep[2] . '</p>';
                    }
                    $tmpl = implode($sep, $tmpl_sep);

                    $message['tmpl'] =
                        str_replace(
                            "\n",
                            "<br>",
                            str_replace(
                                "\r",
                                "<br>",
                                str_replace("\r\n", "<br>", $tmpl)
                            )
                    );
                }
            }
            unset($message);
            if ($changed) {
                $spm->updateByField(array(
                    'source_id' => $source_id,
                    'name' => 'messages'
                ), array(
                    'value' => serialize($messages)
                ));
            }
        }
    }
}

// antispam
foreach ($spm->getByField('name', 'antispam_mail_template', true) as $item) {
    $source_id = $item['source_id'];
    if ($item['value']) {
        $tmpl = $item['value'];
        if (stripos($tmpl, '<br') === false) {

            $tmpl_sep = explode($sep, $tmpl);
            if (empty($tmpl_sep[2])) {
                continue;
            }
            $tmpl_sep[2] = trim($tmpl_sep[2]);
            if ($tmpl_sep[2][0] !== '<') {
                $tmpl_sep[2] = '<p>' . $tmpl_sep[2] . '</p>';
            }
            $tmpl = implode($sep, $tmpl_sep);

            $tmpl =
                str_replace(
                    "\n",
                    "<br>",
                    str_replace(
                        "\r",
                        "<br>",
                        str_replace("\r\n", "<br>", $tmpl)
                    )
            );
            $spm->updateByField(array(
                'source_id' => $source_id,
                'name' => 'antispam_mail_template'
            ), array(
                'value' => $tmpl
            ));
        }
    }
}