<?php

class helpdeskRequestsReadController extends helpdeskJsonController
{
    public function execute()
    {
        $um = new helpdeskUnreadModel();
        if (waRequest::post('hash') === '@all') {
            $um->readAll();
        }
    }
}

