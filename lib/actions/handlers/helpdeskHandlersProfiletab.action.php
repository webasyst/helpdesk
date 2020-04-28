<?php
/**
 * Used by event handler to show Requests tab in contacts.
 */
class helpdeskHandlersProfiletabAction extends helpdeskViewAction
{
    public function execute()
    {
        $contact_id = waRequest::get('contact_id', null, waRequest::TYPE_INT);
        $page = waRequest::get('page', 0, waRequest::TYPE_INT);

        // List of requests
        $c = helpdeskRequestsCollection::create(array(
            array(
                'name' => 'client',
                'params' => array($contact_id),
            ),
        ));
        $c->orderBy('created', 'DESC');

        $count = $c->count();
        $offset = max(0, $page - 1) * helpdeskConfig::ROWS_PER_PAGE;
        $pages_count = ceil($count / helpdeskConfig::ROWS_PER_PAGE);

        $requests = helpdeskRequest::prepareRequests($c->limit($offset, helpdeskConfig::ROWS_PER_PAGE)->getRequests());
        $link_tpl = wa()->getAppUrl('helpdesk').'#/request/%id%/';
        $this->view->assign('requests', $requests);
        $this->view->assign('link_tpl', $link_tpl);
        $this->view->assign('pages_count', $pages_count);
        $this->view->assign('total_count', $count);
    }
}
