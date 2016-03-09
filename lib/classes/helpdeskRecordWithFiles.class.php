<?php
/**
 * Extension for helpdeskRecordWithParams allowing to save attachments and assets
 * along with database row in main table.
 *
 * Attachments are accessable through $this->attachments[id] == array(
 *      'file' => full path,
 *      'name' => original filename,
 *      'cid' => id to refer to this file inside message text (optional),
 * ).
 *
 * Assets are accessable through $this->assets[name] = full path to file.
 *
 * File paths are exposed and accepted as full paths.
 * However, note that values in DB are stored as paths relative
 * to $this->getAttachmentsDir() and $this->getAssetsDir() respectively.
 */
abstract class helpdeskRecordWithFiles extends helpdeskRecordWithParams
{
    // *** *** *** *** *** *** *** *** *** *** *** *** ***
    // Abstract functions
    // *** *** *** *** *** *** *** *** *** *** *** *** ***

    /**
     * Path to directory to save attachments. To be overriden in subclasses.
     * Dir must be safe to delete after record is deleted from DB.
     * $this->id is guaranteed to contain actual db id.
     * @return string
     */
    abstract protected function attachmentsDir();

    /**
     * Path to directory to save assets. To be overriden in subclasses.
     * Dir must be safe to delete after record is deleted from DB.
     * $this->id is guaranteed to contain actual db id.
     * @return string
     */
    abstract protected function assetsDir();

    // *** *** *** *** *** *** *** *** *** *** *** *** ***
    // Public functions
    // *** *** *** *** *** *** *** *** *** *** *** *** ***

    /**
     * Same as $this->attachments[] = array('file' => $file, 'name' => $name, 'cid' => $cid)
     * @param string $file path to temporary file that is safe to be moved to attach dir
     * @param string $name original filename
     * @param string $content_id (optional) id to refer to this file inside message text
     * @return $this
     */
    public function attach($file, $name, $content_id=null)
    {
        $arr = array(
            'file' => $file,
            'name' => $name,
        );
        if ($content_id) {
            $arr['cid'] = $content_id;
        }
        $this->attachments[] = $arr;
        return $this;
    }

    /**
     * Same as $this->assets[$name] = $file
     * @param string $file path to temporary file that is safe to be moved to assets dir
     * @param string $name filename to save file with
     * @return $this
     */
    public function asset($file, $name)
    {
        $this->assets[$name] = $file;
        return $this;
    }

    // *** *** *** *** *** *** *** *** *** *** *** *** ***
    // Overriden protected functions
    // *** *** *** *** *** *** *** *** *** *** *** *** ***

    protected function getDefaultValues()
    {
        return array(
            'assets' => array(),
            'attachments' => array(),
        ) + parent::getDefaultValues();
    }

    protected function getLoadableKeys()
    {
        $k = parent::getLoadableKeys();
        $k[] = 'assets';
        $k[] = 'attachments';
        return $k;
    }

    /** Helper for $this->afterSave() */
    protected function saveAttachments()
    {
        // save attachments (don't bother if user didn't even requested them)
        if (isset($this->rec_data['attachments'])) {

            // Make sure $this['attachments'] is empty or is waArrayObjectDiff
            if (!$this['attachments'] instanceof waArrayObjectDiff && $this['attachments'] instanceof waArrayObject) {
                if ($this['attachments']->stub()) {
                    unset($this['attachments']);
                } else {
                    $data = $this['attachments'];
                    $this['attachments'] = new waArrayObjectDiff();
                    $this['attachments']->setAll($data);
                }
            }

            if ($this['attachments'] instanceof waArrayObjectDiff && $this['attachments']->count() > 0) {
                $attach_dir = $this->attachmentsDir();
                waFiles::create($attach_dir);
                if (! ( $attach_dir = realpath($attach_dir))) {
                    throw new waException('Unable to create dir '.$this->attachmentsDir());
                }
                $attach_dir = rtrim($attach_dir, '\/').'/';

                // save new attachments
                foreach($this['attachments']->diff() as $fid => $data) {
                    // attach didn't change?
                    if (!$data) {
                        continue;
                    }

                    // get all values from data array in case e,g, only the filename changed
                    $data = $this['attachments'][$fid];

                    // sanity checks
                    if (!isset($data['file']) || !file_exists($data['file'])) {
                        throw new waException('Attachment file not found: '.(isset($data['file']) ? $data['file'] : '<no filename>'));
                    }
                    if (!isset($data['name'])) {
                        $data['name'] = $fid;
                    }

                    // old and new filenames to compare
                    $file = realpath($data['file']);
                    $dest = $attach_dir.$fid;

                    // file already in attachments dir?
                    if ($file === realpath($dest)) {
                        continue;
                    }

                    // move file
                    rename($file, $dest);
                    $data['file'] = $dest;
                }

                // delete removed attachments
                foreach($this['attachments']->diff(false) as $fid => $data) {
                    if (!isset($this['attachments'][$fid])) {
                        unlink($attach_dir.$fid);
                        unset($this['attachments'][$fid]);
                    }
                }
            }

            // update attachments record in params
            $db_value = array();
            foreach($this['attachments'] as $fid => $data) {
                $db_value[$fid] = $data->toArray();
                $db_value[$fid]['file'] = basename($data['file']);
            }
            if ($db_value) {
                $this['params']['attachments'] = $db_value;
            } else {
                unset($this['params']['attachments']);
            }
        }
    }

    /** Helper for $this->afterSave() */
    protected function saveAssets()
    {
        if (isset($this->rec_data['assets'])) {
            if (!$this['assets'] instanceof waArrayObjectDiff && $this['assets'] instanceof waArrayObject) {
                if ($this['assets']->stub()) {
                    unset($this['assets']);
                } else {
                    $data = $this['assets'];
                    $this['assets'] = new waArrayObjectDiff();
                    $this['assets']->setAll($data);
                }
            }
            if ($this['assets'] instanceof waArrayObjectDiff && $this['assets']->count() > 0) {
                $assets_dir = $this->assetsDir();
                waFiles::create($assets_dir);
                if (! ( $assets_dir = realpath($assets_dir))) {
                    throw new waException('Unable to create dir '.$this->assetsDir());
                }
                $assets_dir = rtrim($assets_dir, '\/').'/';

                // save new assets
                $new_assets = array();
                foreach($this['assets']->diff() as $name => $file) {
                    // asset didn't change?
                    if (!$file) {
                        continue;
                    }
                    // sanity check
                    if (!file_exists($file)) {
                        throw new waException('Asset file not found: '.$file);
                    }

                    // old and new filenames to compare
                    $file = realpath($file);
                    $dest = $assets_dir.$name;

                    // file already in assets dir?
                    if ($file === realpath($dest)) {
                        continue;
                    }

                    // move file
                    waFiles::create($assets_dir);
                    rename($file, $dest);
                    $this['assets'][$name] = $dest;
                }

                // delete removed assets
                foreach($this['assets']->diff(false) as $name => $file) {
                    if (!isset($this['assets'][$name])) {
                        unlink($file);
                        unset($this['assets'][$name]);
                    }
                }
            }

            // update assets record in params
            $db_value = array();
            foreach($this['assets'] as $name => $file) {
                $db_value[$name] = basename($file);
            }
            if ($db_value) {
                $this['params']['assets'] = $db_value;
            } else {
                unset($this['params']['assets']);
            }
        }
    }

    protected function afterSave()
    {
        try {
            $this->saveAttachments();
        } catch (Exception $e) {
            waLog::log('Unable to save attachments: '.$e->getMessage(), 'helpdesk.log');
            unset($this['attachments']);
        }

        try {
            $this->saveAssets();
        } catch (Exception $e) {
            waLog::log('Unable to save assets: '.$e->getMessage(), 'helpdesk.log');
            unset($this['assets']);
        }

        // save params to DB
        parent::afterSave();
    }

    protected function doLoad($field_or_db_row = null)
    {
        // attachments and assets cannot be loaded from array
        if (is_array($field_or_db_row)) {
            return parent::doLoad($field_or_db_row);
        }

        // when not specifically asked for attachments or assets, don't bother
        if($field_or_db_row && $field_or_db_row != 'attachments' && $field_or_db_row != 'assets') {
            return parent::doLoad($field_or_db_row);
        }

        if($field_or_db_row) {
            // ensure that params are loaded
            $this->load('params');
        } else {
            parent::doLoad();
        }

        // load attachments
        if (!$field_or_db_row || $field_or_db_row == 'attachments') {
            $attachments = array();
            if (isset($this->persistent['params']['attachments']) && $this->persistent['params']['attachments'] instanceof waArrayObject) {
                $attachments = $this->persistent['params']['attachments']->toArray();
            }

            if ($attachments) {
                $attach_dir = rtrim(realpath($this->attachmentsDir()), '\/').'/';
                foreach($attachments as $fid => &$data) {
                    $data['file'] = $attach_dir.$data['file'];
                }
            }
            $this->persistent['attachments'] = $attachments;
            $this->restorePersistentInvariant();
        }

        // load assets
        if (!$field_or_db_row || $field_or_db_row == 'assets') {
            $assets = array();

            if (isset($this->persistent['params']['assets']) && $this->persistent['params']['assets'] instanceof waArrayObject) {
                $assets = $this->persistent['params']['assets']->toArray();
            }

            if ($assets) {
                $assets_dir = rtrim(realpath($this->assetsDir()), '\/').'/';
                foreach($assets as $name => &$value) {
                    $value = $assets_dir.$value;
                }
            }
            $this->persistent['assets'] = $assets;
            $this->restorePersistentInvariant();
        }
    }

    protected function afterDelete()
    {
        parent::afterDelete();
        foreach(array($this->attachmentsDir(), $this->assetsDir()) as $dir) {
            if (file_exists($dir)) {
                waFiles::delete($dir);
            }
        }
    }
}

