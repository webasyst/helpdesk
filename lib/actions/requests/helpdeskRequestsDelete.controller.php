<?php
/**
 * Bulk delete for requests list.
 */
class helpdeskRequestsDeleteController extends helpdeskJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $ids = explode(',', (string) waRequest::post('ids'));

        // Delete from database
        $rm = new helpdeskRequestModel();
        $rm->delete($ids);

        // Delete files
        foreach($ids as $id) {
            $dir = helpdeskRequest::getAssetsDir($id);
            if (is_writable($dir)) {
                waFiles::delete($dir);
            }
        }

        $this->response = _w('Deleted %s request', 'Deleted %s requests', count($ids));
    }
}

