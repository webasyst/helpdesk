<?php

class helpdeskContactsProfileTabHandler extends waEventHandler
{
    public function execute(&$params)
    {
        if (!wa()->getUser()->getRights('helpdesk', 'backend')) {
            return;
        }

        $contact_id = (is_array($params) ? ifset($params, 'id', 0) : $params);
        $counter_inside = is_array($params) ? ifset($params, 'counter_inside', true) : waRequest::param('profile_tab_counter_inside', true);

        $backend_url = wa()->getConfig()->getBackendUrl(true);

        $old_app = wa()->getApp();
        wa('helpdesk', true);

        $result = array();

        $count = $e = null;
        try {
            // List of requests
            $c = helpdeskRequestsCollection::create(array(
                array(
                    'name'   => 'client',
                    'params' => array($contact_id),
                ),
            ));
            $count = $c->count();
        } catch(Exception $e) {
        }
        if ($count || $e) {
            $result[] = array(
                'title' => _w('Requests').($counter_inside && $count ? ' ('.$count.')' : '') ,
                'html'  => $e ? $e->getMessage().' ('.$e->getCode().')' : '',
                'url'   => $e ? '' : $backend_url.'helpdesk/?module=handlers&action=profiletab&contact_id='.$contact_id,
                'count' => $e ? '!' : ($counter_inside ? null : $count),
            );
        }

        wa($old_app, true);
        return ifempty($result, null);
    }
}
