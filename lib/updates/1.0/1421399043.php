<?php

$m = new waModel();

try {
    $receipts = $m->query("SELECT * FROM helpdesk_source_params WHERE `name`='messages'");

    foreach ($receipts as $r) {
        if (!$r['value']) {
            continue;
        }
        $messages = unserialize($r['value']);

        foreach ($messages as $id=>$msg) {
            if (!empty($msg['sourcefrom'])) {
                $messages[$id]['sourcefrom'] = 'sourcefrom';

                $tmpl = explode('{SEPARATOR}', $msg['tmpl']);
                $tmpl[0] = '';
                $messages[$id]['tmpl'] = join('{SEPARATOR}', $tmpl);
            }
        }
        $messages = $m->escape(serialize($messages));
        $m->exec("UPDATE helpdesk_source_params SET `value`='$messages' WHERE `name`='messages' AND source_id="
            . (int)$r['source_id']);
    }
} catch (waDbException $e) {
    waLog::log('Update error: Unable to convert message sourcefrom: '.$e->getMessage()."\n".$e->getTraceAsString(), 'helpdesk.log');
}

try {
    $receipts = $m->query("SELECT * FROM helpdesk_source_params WHERE `name`='receipt_template'");

    foreach ($receipts as $r) {
        if (!$r['value']) {
            continue;
        }
        $messages = $m->query("SELECT * FROM helpdesk_source_params WHERE `name`='messages' AND source_id="
            . (int)$r['source_id']
            . " LIMIT 1")->fetchAssoc();
        if ($messages) {
            $msg = unserialize($messages['value']);
        } else {
            $msg = array();
        }

        $msg[] = array(
            'tmpl' => $r['value'],
            'to' => array('client' => 1),
        );
        $msg = $m->escape(serialize($msg));

        if ($messages) {
            $m->exec("UPDATE helpdesk_source_params SET `value`='$msg' WHERE `name`='messages' AND source_id="
                . (int)$r['source_id']);
        } else {
            $m->exec("INSERT INTO helpdesk_source_params SET `value`='$msg', `name`='messages', source_id="
                . (int)$r['source_id']);
        }
        $m->exec("DELETE FROM helpdesk_source_params WHERE `name`='receipt_template' AND source_id="
            . (int)$r['source_id']);
        $m->exec("DELETE FROM helpdesk_source_params WHERE `name`='receipt' AND source_id="
            . (int)$r['source_id']);
    }
} catch (waDbException $e) {
    waLog::log('Update error: Unable to convert receipt record(s): '.$e->getMessage()."\n".$e->getTraceAsString(), 'helpdesk.log');
}
