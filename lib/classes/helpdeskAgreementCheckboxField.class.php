<?php

class helpdeskAgreementCheckboxField extends waContactCheckboxField
{
    protected $class_prefix = 'h-agreement-checkbox';
    protected $default_link_text = 'personal data protection policy';   // _w('personal data protection policy')
    protected $default_link_href_placeholder = '---INSERT A LINK HERE!---';     // _w('---INSERT A LINK HERE!---')

    public function __construct($id, $name, array $options = array())
    {
        parent::__construct($id, $name, $options);
        $this->default_link_text = _w($this->default_link_text);
        $this->default_link_href_placeholder = _w($this->default_link_href_placeholder);
    }

    public function getHTML($params = array(), $attrs = '')
    {
        $wrapper_class = "{$this->class_prefix}-wrapper";
        $wrapper_id = uniqid($wrapper_class);
        $wrapper_html = "<div class='{$wrapper_class}' id='{$wrapper_id}'>:HTML:</div>";

        $html_label = ifset($params['html_label']);
        if ($html_label === null) {
            $html_label = $this->getDefaultHtmlLabel();
        }
        $html_label = str_replace($this->default_link_href_placeholder, 'javascript:void(0);', $html_label);

        $checkbox_id = uniqid("{$this->class_prefix}-checkbox");
        $attrs .= " id='{$checkbox_id}'";

        $html = parent::getHTML($params, $attrs);
        $html .= "<label class='{$this->class_prefix}-html-label' for='{$checkbox_id}'>{$html_label}</label>";

        $html .= '<script>$(function() {
    
            var class_prefix = ":CLASS_PREFIX:",
                $wrapper = $("#:WRAPPER_ID:"),
                $label_wrapper = $wrapper.find("." + class_prefix + "-html-label"),
                $checkbox = $wrapper.find(":checkbox");
                
                if ($checkbox.is(":disabled")) {
                    $label_wrapper.addClass("h-disabled");
                    $label_wrapper.off(".:WRAPPER_ID:").on("click.:WRAPPER_ID:", "a", function(e) {
                        e.preventDefault();                      
                    });
                }

        })</script>';

        $html = str_replace(
            array(':CLASS_PREFIX:', ':WRAPPER_ID:'),
            array($this->class_prefix, $wrapper_id),
            $html
        );

        $html = str_replace(':HTML:', $html, $wrapper_html);

        return $html;
    }

    public function getDefaultHtmlLabel($href = null)
    {
        if ($href !== null) {
            if ($href === true) {
                $href = $this->default_link_href_placeholder;
            }
            $href = (string) $href;
        } else {
            $href = "javascript:void(0);";
        }
        $html = _w("I agree to <a>{$this->default_link_text}</a>");
        $html = str_replace('<a>', "<a class='{$this->class_prefix}-link' href='{$href}' target='_blank'>", $html);
        return $html;
    }

    public function getDefaultLinkHrefPlaceholder()
    {
        return $this->default_link_href_placeholder;
    }

    public function getDefaultLinkText()
    {
        return $this->default_link_text;
    }
}
