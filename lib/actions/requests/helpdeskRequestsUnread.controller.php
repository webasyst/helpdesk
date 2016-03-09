<?php
/**
 * Change read/unread status of one or several requests
 */
class helpdeskRequestsUnreadController extends helpdeskJsonController
{
    public function execute()
    {
        $ids = waRequest::request('ids');
        if (!is_array($ids)) {
            $ids = explode(',', $ids);
        }
        $ids = array_filter($ids, 'wa_is_int');

        $um = new helpdeskUnreadModel();
        if (waRequest::request('status')) {
            $ids && $um->markUnreadForContact($ids);
            $message = _w('%d request has been marked as unread', '%d requests have been marked as unread', count($ids));
        } else {
            $ids && $um->read($ids);
            $message = _w('%d request has been marked as read', '%d requests have been marked as read', count($ids));
        }

        $this->response = array(
            'unread_count' => $um->countByContact(),
            'message' => $message,
        );
    }
}

