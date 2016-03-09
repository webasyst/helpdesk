<?php
/**
 * Change follow status of one or several requests
 */
class helpdeskRequestsFollowController extends helpdeskJsonController
{
    public function execute()
    {
        $ids = waRequest::request('ids');
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }
        $ids = array_filter($ids, 'wa_is_int');

        $fm = new helpdeskFollowModel();
        $status = waRequest::request('status');
        if ($status) {
            $fm->add($ids);
            $message = _w('%d request has been marked as Follow', '%d requests have been marked as Follow', count($ids));
        } else {
            $fm->deleteByField('request_id', $ids);
            $message = _w('%d request has been removed from the Follow list', '%d requests have been removed from the Follow list', count($ids));
        }

        $this->response = array(
            'follow_count' => $fm->countByContact(),
            'message' => $message
        );
        
        if (waRequest::request('follow_not_show_message')) {
            wa()->getUser()->setSettings('helpdesk', 'follow_not_show_message', '1');
        } else {
            wa()->getUser()->delSettings('helpdesk', 'follow_not_show_message');
        }
    }
}

