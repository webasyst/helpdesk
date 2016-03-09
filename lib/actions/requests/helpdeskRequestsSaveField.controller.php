<?php


class helpdeskRequestsSaveFieldController extends helpdeskJsonController
{
    public function execute()
    {
        $request_id = waRequest::request('request_id', null, waRequest::TYPE_INT);
        if ($request_id) {
            $r = new helpdeskRequest($request_id);
            if ($r) {
                $name = waRequest::request('name', '', waRequest::TYPE_STRING_TRIM);
                $value = waRequest::request('value', '', waRequest::TYPE_STRING_TRIM);
                $r->setField($name, $value);
            }
        }
    }
}

