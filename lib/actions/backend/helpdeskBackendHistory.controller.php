<?php
/**
 * User removed recent search history.
 */
class helpdeskBackendHistoryController extends helpdeskJsonController
{
    public function execute() 
    {
        $hm = new helpdeskHistoryModel();
        if (waRequest::get('clear')) {
            $type = waRequest::get('ctype');
            $hm->prune(0, $type);
            $this->response['cleared'] = 1;
        }
    }
}
