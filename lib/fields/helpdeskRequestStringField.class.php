<?php

class helpdeskRequestStringField extends helpdeskRequestField
{
    public function getInfo()
    {
        $info = parent::getInfo();
        $info['input_height'] = $this->getParameter('input_height');
        return $info;
    }

    public function getParameter($p)
    {
        if ($p == 'input_height') {
            if (!isset($this->options['input_height'])) {
                $this->options['input_height'] = 1;
            }
            return $this->options['input_height'];
        }
        return parent::getParameter($p);
    }

    public function setParameter($p, $value)
    {
        if ($p == 'input_height') {
            $value = (int) $value;
            if ($value < 1) {
                $value = 1;
            } else if ($value > 5) {
                $value = 5;
            }
            $this->options['input_height'] = $value;
        } else {
            parent::setParameter($p, $value);
        }
    }

    public function getHtmlOne($params = array(), $attrs = '')
    {
        if ($this->getParameter('input_height') <= 1) {
            return parent::getHtmlOne($params, $attrs);
        }

        $value = isset($params['value']) ? $params['value'] : '';
        return '<textarea '.$attrs.' name="'.$this->getHTMLName($params).'">'.htmlspecialchars($value).'</textarea>';
    }
}

// EOF