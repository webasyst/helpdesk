<?php

$model = new waContactEmailsModel();
$contact_ids = $model->query("SELECT contact_id FROM wa_contact_emails WHERE email = ''")->fetchAll(null, true);

// repair requests
foreach ($contact_ids as $contact_id) {
    $requests = $model->query("SELECT * FROM `helpdesk_request` WHERE `client_contact_id` = {$contact_id}")->fetchAll('id');
    if (!$requests) {
        continue;
    }
    $emails = $model->query("
        SELECT request_id, value
        FROM `helpdesk_request_data`
        WHERE field = 'c_email' AND request_id IN(" . implode(",", array_keys($requests)) . ")"
    )->fetchAll('request_id', true);
    foreach ($requests as $request_id => $request) {
        $email = $emails[$request_id];
        $creator_id = $request['creator_contact_id'];
        $col = new waContactsCollection('search/email=' . $email);
        $contacts = $col->getContacts('*');
        if (!$contacts) {
            continue;
        }
        if (isset($contacts[$creator_id])) {
            $client_id = $creator_id;
        } else {
            reset($contacts);
            $client_id = key($contacts);
        }
        if ($contact_id != $client_id) {
            $model->exec("UPDATE `helpdesk_request` SET client_contact_id = {$client_id} WHERE id={$request_id}");
        }
    }
}

// fix contact - delete empty emails
foreach ($contact_ids as $contact_id) {
    // delete empty emails
    $model->exec("DELETE FROM wa_contact_emails
    WHERE email = '' AND contact_id = {$contact_id}");

    // repair sort fields, sort must be in strongly in 0..n sequence
    $data = array();
    $sort = 0;
    foreach (
        $model->query("SELECT * FROM wa_contact_emails
    WHERE contact_id = {$contact_id}
    ORDER BY sort")->fetchAll() as $item
    )
    {
        $data[] = $item;
        $item['sort'] = $sort;
        $sort += 1;
    }
    if ($data) {
        $model->deleteByField(array(
            'contact_id' => $contact_id
        ));
        $model->multipleInsert($data);
    }
}
