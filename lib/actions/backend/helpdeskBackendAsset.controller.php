<?php

/** Asset download (e.g. original email file) for backend users. */
class helpdeskBackendAssetController extends waController
{
    public function execute()
    {
        $request_id = waRequest::get('r');
        $log_id = waRequest::get('l');
        $filename = waRequest::get('a');

        $r = new helpdeskRequest($request_id);

        // check access rights
        if (!$this->isAllowed($r, $log_id, $filename) || strpbrk($filename, '\/:')) {
            throw new waRightsException(_w('Access denied.'));
        }

        // Check if this attachment exists
        if ($log_id) {
            $file = helpdeskRequest::getAssetsDir($request_id, $log_id).'/'.$filename;
        } else {
            $file = helpdeskRequest::getAssetsDir($request_id).'/'.$filename;
        }
        if (!file_exists($file)) {
            throw new waException('Asset not found.', 404);
        }

        waFiles::readFile($file, $filename);
    }

    protected function isAllowed($r, $log_id, $filename)
    {
        try {
            if (!$r->isVisibleForUser()) {
                return false;
            }

            if ($log_id) {
                $l = new helpdeskRequestLog($log_id);
                if ($l->request_id != $r->id) {
                    return false;
                }
            }

            return true;
        } catch(Exception $e) {
            // Request or log do not exist
            return false;
        }
    }
}

