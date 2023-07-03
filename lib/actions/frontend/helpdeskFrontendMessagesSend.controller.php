<?php

class helpdeskFrontendMessagesSendController extends waJsonController
{
    public function execute()
    {
        ignore_user_abort(true);
        wa('helpdesk')->getConfig('helpdesk')->sendMessagesFromQueue();
    }
}
