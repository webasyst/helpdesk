<?php

/**
 * Upload image using WYSIWYG toolbar button.
 */
class helpdeskFilesUploadimageController extends waUploadJsonController
{
    protected $name;

    protected function process()
    {
        $f = waRequest::file('file');
        $f->transliterateFilename();
        $this->name = $f->name;
        if ($this->processFile($f)) {
            $this->response = wa()->getDataUrl('files/'.$this->name, true, 'helpdesk', true);
            $this->response = str_replace('https://', 'http://', $this->response);
        }
    }

    public function display()
    {
        $this->getResponse()->sendHeaders();
        if (!$this->errors) {
            if (waRequest::get('filelink')) {
                echo json_encode(array('filelink' => $this->response));
            } elseif (waRequest::get('r') === '2') { // redactor 2
                echo json_encode(array('url' => $this->response));
            } else {
                $data = array('status' => 'ok', 'data' => $this->response);
                echo json_encode($data);
            }
        } else {
            if (waRequest::get('filelink')) {
                echo json_encode(array('error' => $this->errors));
            } else {
                echo json_encode(array('status' => 'fail', 'errors' => $this->errors));
            }
        }
    }

    protected function getPath()
    {
        return wa()->getDataPath('files', true);
    }

    protected function isValid($f)
    {
        $allowed = array('jpg', 'jpeg', 'png', 'gif');
        if (!in_array(strtolower($f->extension), $allowed)) {
            $this->errors[] = sprintf(_w("Files with extensions %s are allowed only."), '*.'.implode(', *.', $allowed));
            return false;
        }
        return true;
    }

    protected function save(waRequestFile $f)
    {
        if (file_exists($this->path.DIRECTORY_SEPARATOR.$f->name)) {
            $i = strrpos($f->name, '.');
            $name = substr($f->name, 0, $i);
            $ext = substr($f->name, $i + 1);
            $i = 1;
            while (file_exists($this->path.DIRECTORY_SEPARATOR.$name.'-'.$i.'.'.$ext)) {
                $i++;
            }
            $this->name = $name.'-'.$i.'.'.$ext;
            return $f->moveTo($this->path, $this->name);
        }
        return $f->moveTo($this->path, $f->name);
    }
}