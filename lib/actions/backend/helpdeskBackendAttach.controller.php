<?php

/** Attachment download for backend users. */
class helpdeskBackendAttachController extends waController
{
    public function execute()
    {
        $request_id = waRequest::get('r');
        $log_id = waRequest::get('l');
        $attach_id = waRequest::get('a');
        $r = new helpdeskRequest($request_id);

        // check access rights
        if (!$this->isAllowed($r, $log_id, $attach_id)) {
            throw new waRightsException(_w('Access denied.'));
        }

        // Check if this attachment exists
        if ($log_id) {
            $file = helpdeskRequest::getAttachmentsDir($request_id, $log_id).'/'.$attach_id;
        } else {
            $file = helpdeskRequest::getAttachmentsDir($request_id).'/'.$attach_id;
        }
        if (!file_exists($file)) {
            throw new waException('Attachment not found.', 404);
        }

        // All the other stuff is to get original filename from DB
        $attach = null;
        $filename = $attach_id;
        if ($log_id) {
            $rlpm = new helpdeskRequestLogParamsModel();
            $row = $rlpm->getByField(array(
                'request_log_id' => $log_id,
                'name' => 'attachments',
            ));
            if ($row) {
                $attach = @unserialize($row['value']);
            }
        } else {
            $attach = $r->attachments->toArray();
        }

        // db record found?
        if ($attach) {
            $a = null;
            foreach($attach as $data) {
                if (basename($data['file']) == $attach_id) {
                    $a = $data;
                    break;
                }
            }
            if ($a && isset($a['name'])) {
                $filename = $a['name'];
            }
        }

        waFiles::readFile($file, $filename);
    }

    protected function isAllowed($r, $log_id, $attach_id)
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

