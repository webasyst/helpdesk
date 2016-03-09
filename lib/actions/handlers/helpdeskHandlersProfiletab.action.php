<?php
/**
 * Used by event handler to show Requests tab in contacts.
 */
class helpdeskHandlersProfiletabAction extends helpdeskViewAction
{
    public function getTabContent($params)
    {
        $this->contact_id = $params;
        $html = $this->display();

        if ($this->requests) {
            return array(
                'title' => _w('Requests').' ('.count($this->requests).')',
                'html' => $html,
                'count' => 0,
            );
        } else {
            return null;
        }
    }

    public function execute()
    {
        // List of requests
        $c = helpdeskRequestsCollection::create(array(
            array(
                'name' => 'client',
                'params' => array($this->contact_id),
            ),
        ));
        $this->requests = helpdeskRequest::prepareRequests($c->limit(0)->getRequests());
        $link_tpl = wa()->getAppUrl('helpdesk').'#/request/%id%/';
        $this->view->assign('requests', $this->requests);
        $this->view->assign('link_tpl', $link_tpl);
    }
}

