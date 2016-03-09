<?php

class helpdeskRequestsImageRotateController extends helpdeskJsonController
{
    const DIRECTION_LEFT = 'left';
    const DIRECTION_RIGHT = 'right';

    public function execute()
    {
        // check rights
        $request_id = wa()->getRequest()->post('request_id', 0, waRequest::TYPE_INT);
        $log_id = wa()->getRequest()->post('log_id', 0, waRequest::TYPE_INT);
        $attach_id = wa()->getRequest()->post('attach_id', 0, waRequest::TYPE_INT);
        $direction = wa()->getRequest()->post('direction', self::DIRECTION_LEFT, waRequest::TYPE_STRING_TRIM);

        if (!in_array($direction, array(self::DIRECTION_LEFT, self::DIRECTION_RIGHT))) {
            throw new waException('Unkown direction.');
        }

        $request = new helpdeskRequest($request_id);
        if (!$request->exists()) {
            throw new waException('Unkown request.');
        }

        $edit_datetime = '';

        if (!$log_id) {
            $edit_datetime = $this->rotateImage($request, $attach_id, $direction);
        } else {
            $log = new helpdeskRequestLog($log_id);
            if (!$log->exists() && $log->request_id != $request_id) {
                throw new waException('Unknown request log.');
            }
            $edit_datetime = $this->rotateImage($log, $attach_id, $direction);
        }

        $this->response = array(
            'datetime' => $edit_datetime
        );

    }

    public function rotateImage(helpdeskRecordWithFiles $r, $attach_id, $direction)
    {
        $edit_datetime = '';
        if (!empty($r->attachments[$attach_id])) {
            $f = $r->attachments[$attach_id];
            $edit_datetime = $this->rotate($f, $direction);
            if ($edit_datetime) {
                $r->attachments[$attach_id]['datetime'] = $edit_datetime;
                $r->save();
            }
        }
        return $edit_datetime;
    }

    public function rotate($attach, $direction)
    {
        $photo_path = $attach['file'];

        $edit_datetime = '';

        $paths = array();
        try {
            $im = waImage::factory($photo_path);
            $backup_photo_path = $photo_path.'.backup';
            $result_photo_path = $photo_path.'.'.$im->getExt();
            waFiles::copy($photo_path, $result_photo_path);

            $image = waImage::factory($result_photo_path);

            $paths[] = $result_photo_path;
            $angle = $direction === self::DIRECTION_RIGHT ? '90' : '-90';
            $result = $image->rotate($angle)->save($result_photo_path);
            if ($result) {
                $count = 0;
                while(!file_exists($result_photo_path) && ++$count < 5) {
                    sleep(1);
                }
                if(!file_exists($result_photo_path)) {
                    throw new waException("Error while rotate. I/O error");
                }
                $paths[] = $backup_photo_path;
                if(!waFiles::move($result_photo_path, $photo_path)) {
                    if(!waFiles::move($backup_photo_path, $photo_path)) {
                        throw new waException("Error while rotate. Original file corupted but backuped" );
                    }
                    throw new waException("Error while rotate. Operation canceled");
                } else {
                    $edit_datetime = date('Y-m-d H:i:s');
                }
            }
            foreach($paths as $path) {
                waFiles::delete($path);
            }
        } catch(Exception $e) {
            foreach($paths as $path) {
                waFiles::delete($path);
            }
            $edit_datetime = '';
        }

        return $edit_datetime;

    }
}

