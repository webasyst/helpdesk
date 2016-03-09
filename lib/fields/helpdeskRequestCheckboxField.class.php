<?php

class helpdeskRequestCheckboxField extends helpdeskRequestField
{
    public function format($data, $format = null)
    {
        $val = parent::format($data, $format);
        return $val ? _ws('Yes') : _ws('No');
    }

    public function getHTML($params = array(), $attrs = '')
    {
        $value = isset($params['value']) ? $params['value'] : '';

        $disabled = '';
//        if (wa()->getEnv() === 'frontend' && isset($params['my_profile']) && $params['my_profile'] == '1') {
//            $disabled = 'disabled="disabled"';
//        }
        return '<input type="hidden" '.$disabled.' name="'.$this->getHTMLName($params)
            .'" value="0"><input type="checkbox"'.($value ? ' checked="checked"' : '')
            .' name="'.$this->getHTMLName($params).'" value="'.ifempty($value, '1').'" id=cb_'. $this->getHTMLName($params)
            .' '.$attrs.'>';
    }

}

