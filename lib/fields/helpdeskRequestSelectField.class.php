<?php

class helpdeskRequestSelectField extends helpdeskRequestField
{
    
    public function getOptions($id = null)
    {
        $options = array();
        
        if (is_string($this->options['options'])) {
            $options = array(_w($this->options['options']));
        }
        
        if (empty($this->options['options']) || !is_array($this->options['options'])) {
            $options = array();
        } else {
            $options = $this->options['options'];
        }
        

        foreach ($options as &$o) {
            $o = _w($o);
        }
        unset($o);
        
        if ($id !== null) {
            if (!isset($options[$id])) {
                return null;
            } else {
                return $options[$id];
            }
        }
        return $options;
    }

    public function getInfo()
    {
        $data = parent::getInfo();
        $data['options'] = $this->getOptions();

        $data['oOrder'] = array_keys($data['options']);
        $data['defaultOption'] = _ws($this->getParameter('defaultOption'));
        return $data;
    }

    /**
     * Return 'Select' type, unless redefined in subclasses
     * @return string
     */
    public function getType()
    {
        return 'Select';
    }

    public function getHtmlOne($params = array(), $attrs = '')
    {
        $value = isset($params['value']) ? $params['value'] : '';
        $html = '';
        if (!empty($params['view']) && $params['view'] === 'radio') {
            foreach ($this->getOptions() as $k => $v) {
                $html .= '<label><input type="radio" '.$attrs.
                                ' name="'.$this->getHTMLName($params).'" '.
                                    ((string)$k === (string)$value ? ' checked="checked"' : '').
                                ' value="'.$k.'"> '.
                                    htmlspecialchars($v).
                            '</label>';
            }
        } else {
            $html .= '<select '.$attrs.' name="'.$this->getHTMLName($params).'"><option value=""></option>';
            foreach ($this->getOptions() as $k => $v) {
                $html .= '<option'.((string)$k === (string)$value ? ' selected="selected"' : '').' value="'.$k.'">'.htmlspecialchars($v).'</option>';
            }
            $html .= '</select>';
        }
        return $html;
    }
    
    protected function _format($data, $format = null) {
        $options = $this->getOptions();
        $val = parent::_format($data, $format);
        if (isset($options[$val])) {
            return $options[$val];
        } else {
            return $val;
        }
    }
}
