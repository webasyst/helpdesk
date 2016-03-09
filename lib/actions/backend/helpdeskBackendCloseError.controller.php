<?php
/**
 * User closed a global error block in backend.
 */
class helpdeskBackendCloseErrorController extends helpdeskJsonController
{
    public function execute()
    {
        wa()->getStorage()->set('helpdesk_error_hidden', true);
    }
}
