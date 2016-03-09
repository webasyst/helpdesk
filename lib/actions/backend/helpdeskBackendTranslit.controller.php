<?php
/**
 * Transliterates a string into alphanumeric with underscores, suitable for various IDs.
 */
class helpdeskBackendTranslitController extends helpdeskJsonController
{
    public function execute()
    {
        $str = waLocale::transliterate(waRequest::request('str'));
        foreach (waLocale::getAll() as $lang) {
            $str = waLocale::transliterate($str, $lang);
        }
        $this->response = preg_replace('~[^a-z0-9]+~u', '_', preg_replace('~[`\'"]~', '', strtolower($str)));
        $this->response = trim($this->response, '_');
        if (!$this->response && waRequest::request('prefix', '', waRequest::TYPE_STRING_TRIM)) {
            $this->response = waRequest::request('prefix', '', waRequest::TYPE_STRING_TRIM) .
                    substr(md5(waLocale::transliterate(waRequest::request('str'))), 0, 6);
        }
    }
}

