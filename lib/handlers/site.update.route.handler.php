<?php

class helpdeskSiteUpdateRouteHandler extends waEventHandler
{
    /**
     * @param array $params array('old' => string, 'new' => string)
     * @see waEventHandler::execute()
     * @return void
     */
    public function execute(&$params)
    {
        $spm = new helpdeskSourceParamsModel();
        $spm->updateByField(array('name' => 'domain_' . $params['old']), array('name' => 'domain_' . $params['new']));
        $spm->updateByField(array('name' => 'sort_domain_' . $params['old']), array('name' => 'sort_domain_' . $params['new']));
    }
}
