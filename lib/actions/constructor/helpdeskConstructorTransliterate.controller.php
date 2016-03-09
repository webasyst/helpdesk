<?php

class helpdeskConstructorTransliterateController extends waJsonController
{
    public function execute()
    {
        if (!wa()->getUser()->isAdmin()) {
            throw new waRightsException(_w('Access denied.'));
        }
        
        $name = $this->getRequest()->post('name', "");
        if (empty($name)) {
            $this->response = "";
        } else {
            $this->response = self::transliterate($name);
        }
    }
    
    public static function transliterate($str, $strict = true)
    {
        if (empty($str)) {
            return "";
        }
        $str = preg_replace('/\s+/u', '_', $str);
        if ($str) {
            foreach (waLocale::getAll() as $lang) {
                $str = waLocale::transliterate($str, $lang);
            }
        }
        $str = preg_replace('/[^a-zA-Z0-9_-]+/', '', $str);
        if ($strict && !$str) {
            $str = date('Ymd');
        }
        return strtolower($str);
    }

}