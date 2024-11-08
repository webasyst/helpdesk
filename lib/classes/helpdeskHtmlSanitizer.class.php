<?php

class helpdeskHtmlSanitizer
{
    /**
     * @var array
     */
    protected $options;

    /**
     * @var string
     */
    protected $attr_end;

    /**
     * @var string
     */
    protected $attr_start;

    /**
     * helpdeskHtmlSanitizer constructor
     *
     * @param array $options
     *   array $options['replace_img_src']
     *     Replace map for inline images (img with attr data-crm-file-id)
     *     Map int 'file_id' to 'src' replacing url
     */
    public function __construct($options = array())
    {
        $this->options = $options;
    }

    /**
     * Static shortcut notation
     *
     * @param string $content
     * @param array $options
     * @return mixed|string
     */
    public static function work($content, $options = array())
    {
        $sanitizer = new self($options);
        return $sanitizer->sanitize($content);
    }

    /**
     * Sanitize content
     * @param string $content
     * @return mixed|string
     */
    public function sanitize($content)
    {
        // Make sure it's a valid UTF-8 string
        $content = preg_replace('~\\xED[\\xA0-\\xBF][\\x80-\\xBF]~', '?', mb_convert_encoding($content, 'UTF-8', 'UTF-8'));

        // Remove all tags except known.
        // We don't rely on this for protection. Everything should be escaped anyway.
        // strip_tags() is here so that unknown tags do not show as escaped sequences, making the text unreadable.
        $content = strip_tags($content, '<a><q><b><i><u><pre><blockquote><p><strong><section><em><del><strike><span><ul><ol><li><div><font><br><table><thead><tbody><tfoot><tr><td><th><hr><h1><h2><h3><h4><h5><h6><figure><figcaption><img>');

        // Replace all &entities; with UTF8 chars, except for &, <, >.
        $content = str_replace(array('&amp;','&lt;','&gt;'), array('&amp;amp;','&amp;lt;','&amp;gt;'), $content);
        $content = preg_replace('/(&#*\w+)[\x00-\x20]+;/u', '$1;', $content);
        $content = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $content);
        $content = html_entity_decode($content, ENT_COMPAT, 'UTF-8');

        // Remove redactor data-attribute
        $content = preg_replace('/(<[^>]+)data-redactor[^\s>]+/uis', '$1', $content);

        // Encode everything that seems unsafe.
        $content = htmlentities($content, ENT_QUOTES, 'UTF-8');

        //
        // The plan is: to quote everything, then unquote parts that seem safe.
        //

        // A trick we use to make sure there are no tags inside attributes of other tags.
        do {
            $this->attr_start = $attr_start = str_replace('.', '', uniqid('!ATTRSTART', true).'!');
            $this->attr_end = $attr_end = str_replace('.', '', uniqid('!ATTREND', true).'!');
        } while (strpos($content, $attr_start) !== false || strpos($content, $attr_end) !== false);

        // <a href="...">
        $content = preg_replace_callback(
            '~
                &lt;
                    (
                        a\s+href
                        |
                        q\s+cite
                    )=&quot;
                        ([^"><]+?)
                    &quot;
                    (.*?)
                &gt;
            ~iuxs',
            array($this, 'sanitizeHtmlAHref'),
            $content
        );

        // <img src="...">
        $content = preg_replace_callback(
            '~
                &lt;
                    img\s+
                    .{0,800}?
                    src=&quot;
                        ([^"><]+?)
                    &quot;
                    .*?
                    /?
                &gt;
                (?:
                    \s*
                    &lt;
                        /img
                    &gt;
                )?
            ~iuxs',
            array($this, 'sanitizeHtmlImg'),
            $content
        );

        // <section data-role="c-email-signature">
        $content = preg_replace_callback(
            '~
                &lt;
                    section
                    \s+
                    data-role=&quot;
                        (c-email-signature)
                    &quot;
                    (.*?)
                &gt;
            ~iuxs',
            array($this, 'sanitizeHtmlASection'),
            $content
        );

        // Separately cut off very long style="" blocks because next step has a limit on tag length
        $content = preg_replace(
            '~
                \s+
                style
                \s*=\s*
                &quot;
                    [^&]*
                &quot;
            ~iux',
            ' ',
            $content
        );

        // Having a single-char > instead of &gt; greatly simplifies next regex.
        // We unescape all > but mark them to be escaped back later.
        do {
            $tag_end = str_replace('.', '', uniqid('!TAGEND', true).'!');
        } while (strpos($content, $tag_end) !== false);
        $content_before = $content;
        $content = str_replace('&gt;', '>'.$tag_end, $content);

        // Simple tags: <b>, <i>, <u>, <pre>, <blockquote> and closing counterparts.
        // All attributes are removed.
        $content = preg_replace(
            '~
                &lt;
                    (
                        /?(?:a|q|b|i|u|pre|blockquote|p|strong|section|em|del|strike|span|ul|ol|li|div|font|br|table|thead|tbody|tfoot|tr|td|th|hr|h1|h2|h3|h4|h5|h6|figure|figcaption)
                    )
                    (?:
                        [^a-z\-\_>]
                        [^>]{0,1500}
                    )?
                >'.preg_quote($tag_end).'
            ~iux',
            '<\1>',
            $content
        );
        if ($content === null) {
            // Regex above may fail due to complexity.
            // In this case we keep all tags escaped with lots of &lt; and &gt;
            // but it's better than empty request body.
            $content = $content_before;
        } else {
            $content = str_replace('>'.$tag_end, '&gt;', $content);
            $content = str_replace($tag_end, '', $content);
        }

        /*
        // Replace <h*> tags with a bold paragraph
        $h_patterns = array(
            '~<h[1-6]>~iux'   => '<p class="bold">',
            '~<\/h[1-6]>~iux' => '</p>',
        );
        $content = preg_replace(array_keys($h_patterns), array_values($h_patterns), $content);
        */

        // Remove $attr_start and $attr_end from legal attributes
        $content = preg_replace(
            '~
                '.preg_quote($attr_start).'
                ([^"><]*)
                '.preg_quote($attr_end).'
            ~ux',
            '\1',
            $content
        );

        $is_html = preg_match("!(^|[^\[])<(a|b|i|u|pre|blockquote|p|strong|section|em|del|strike|span|ul|ol|li|div|font|br|table|thead|tbody|tfoot|tr|td|th|hr|h1|h2|h3|h4|h5|h6)[^>@]*>!uis", $content);
        if (!$is_html) {
            // not an HTML formatted string: add some hypertext sugar
            $content = str_replace("\r\n", "\n", $content);
            $content = str_replace("\r", "\n", $content);
            $content = preg_replace('~(\n\s*){3,}~', "\n\n\n", $content);
            $content = preg_replace('~(\n\s*)+(&gt;|>)~uis', "\n>", $content); // Remove blank lines inside quotes
            $content = str_replace("  ", "&nbsp; ", nl2br($content));
        }

        // Remove illegal attributes, i.e. those where $attr_start and $attr_end are still present
        $content = preg_replace(
            '~
                '.preg_quote($attr_start).'
                .*
                '.preg_quote($attr_end).'
            ~uxUs',
            '',
            $content
        );
        $content = str_replace('&amp;', '&', $content);

        // Being paranoid... remove $attr_start and $attr_end if still present anywhere.
        // Should not ever happen.
        $content = str_replace(array($attr_start, $attr_end), '', $content);

        // Fix blockquotes
        $text_blockquotes_replaced = false;
        while (preg_match("~(\r?\n\s?(&gt;|>)[^\r\n]*)~uis", $content)) {
            $content = preg_replace_callback("~(\r?\n\s?(&gt;|>)[^\r\n]*)~uis", array($this, 'blockquote'), $content);
            $text_blockquotes_replaced = true;
        }
        if ($text_blockquotes_replaced) {
            while (preg_match('~</blockquote>[\s\r\t\n]*(<br ?/?>)?[\s\r\t\n]*<blockquote data-from-text=1>~uis', $content)) {
                $content = preg_replace('~</blockquote>[\s\r\t\n]*(<br ?/?>)?[\s\r\t\n]*<blockquote data-from-text=1>~uis', '', $content);
            }
        }

        $content = preg_replace('@<[^>]+$@usi', '', $content);
        $content = str_replace('<!--', '&lt;!--', $content);
        $content = $this->closeTags($content);

        // Remove \n around <blockquote> startting and ending tags
        $content = preg_replace('~(?U:\n\s*){0,2}<(/?blockquote)>(?U:\s*\n){0,2}~i', '<\1>', $content);

        return $content;
    }

    protected function sanitizeHtmlAHref($m)
    {
        $url = $this->sanitizeUrl(ifset($m[2]));
        if (strtolower($m[1][0]) == 'q') {
            return '<q cite="'.$this->attr_start.$url.$this->attr_end.'">';
        } else {
            return '<a href="'.$this->attr_start.$url.$this->attr_end.'" target="_blank" rel="nofollow">';
        }
    }

    protected function sanitizeHtmlImg($m)
    {
        $url = $this->sanitizeUrl(ifset($m[1]));
        if (!$url) {
            return '';
        }

        $attributes = array(
            'src' => $url,
        );

        $legal_attributes = array(
            'data-crm-file-id',
            'width',
            'height'
        );

        foreach ($legal_attributes as $attribute) {
            preg_match(
                '~
                &lt;
                    img\s+
                    .*?
                    '.$attribute.'=&quot;([^"\'><]+?)&quot;
                    .*?
                    /?
                &gt;
            ~iuxs',
                $m[0],
                $match
            );

            if ($match) {
                $val = $match[1];

                // Additional check for positive integer attributes
                if (in_array($attribute, array('data-crm-file-id', 'width', 'height'))) {
                    $val = (int) $val;
                    if ($val <= 0) {
                        continue;
                    }
                }

                $attributes[$attribute] = $val;
            }
        }

        if (isset($attributes['data-crm-file-id'])) {
            // url doesn't matter already, we use file-id link
            $attributes['src'] = '';
            $file_id = $attributes['data-crm-file-id'];
            if (isset($this->options['replace_img_src']) && isset($this->options['replace_img_src'][$file_id])) {
                $attributes['src'] = $this->options['replace_img_src'][$file_id];
            }
        }

        foreach ($attributes as $attribute => $val) {
            $attributes[$attribute] = $attribute.'="'.$this->attr_start.$val.$this->attr_end.'"';
        }

        return '<img ' . join(' ', $attributes) . '>';
    }

    // Section
    protected function sanitizeHtmlASection()
    {
        return '<section data-role="c-email-signature">';
    }

    protected function sanitizeUrl($url)
    {
        if (empty($url)) {
            return '';
        }
        if (preg_match('~^mailto:.*@~i', $url)) {
            return $url;
        }
        $url_alphanumeric = preg_replace('~&amp;[^;]+;~i', '', $url);
        $url_alphanumeric = preg_replace('~\\\\[0trn]~i', '', $url_alphanumeric);
        $url_alphanumeric = preg_replace('~[^a-z0-9:]~i', '', $url_alphanumeric);
        if (preg_match('~^(javascript|vbscript|mocha|livescript):~i', $url_alphanumeric)) {
            return '';
        }

        static $url_validator = null;
        if (!$url_validator) {
            $url_validator = new waUrlValidator();
        }

        if (!$url_validator->isValid($url)) {
            if (preg_match('~^data:image/[a-z]+;base64,[a-zA-Z0-9\/\r\n\s\+]*={0,2}$~', $url)) {
                // good base64-encoded image
            } else {
                $url = 'http://'.preg_replace('~^([^:]+:)?(//|\\\\\\\\)~', '', $url);
            }
        }

        return $url;
    }

    /**
     * Replaces plaintext-style quotes (> ...) with <blockquote>...</blockquote>
     * @param string $str
     * @return string
     */
    protected static function blockquote($str)
    {
        if (is_array($str)) {
            $str = $str[1];
        }
        $str = preg_replace("~\r?\n\s?(&gt;|>)\s*([^\r\n]*)~ui", "\n$2", $str);
        return "\n<blockquote data-from-text=1>".stripcslashes($str)."\n</blockquote>";
    }

    /**
     * Closes all open unclosed html tags.
     * @param string $content
     * @return string
     */
    protected function closeTags($content)
    {
        $content = preg_replace_callback('%(<td[^>]*><div[^>]*>.*?</td>)%uis', "helpdeskHtmlSanitizer::fixDiv", $content);
        // Fix unclosed tags
        $patt_open = "%((?<!</)(?<=<)[\s]*[^/!>\s]+(?=>|[\s]+[^>]*[^/]>)(?!/>))%is";
        $patt_close = "%((?<=</)([^>]+)(?=>))%is";
        $c_tags = $m_open = $m_close = array();
        if (preg_match_all($patt_open, $content, $matches)) {
            $m_open = $matches[1];
            if ($m_open) {
                preg_match_all($patt_close, $content, $matches2);
                $m_close = $matches2[1];
                if (count($m_open) > count($m_close)) {
                    $m_open = array_reverse($m_open);
                    foreach ($m_close as $tag) {
                        if (isset($c_tags[$tag])) {
                            $c_tags[$tag]++;
                        } else {
                            $c_tags[$tag] = 1;
                        }
                    }
                    $close_html = "";
                    foreach ($m_open as $k => $tag) {
                        if ((!isset($c_tags[$tag]) || $c_tags[$tag]-- <= 0) && !in_array(strtolower($tag), array('br', 'img'))) {
                            $close_html = $close_html.'</'.$tag.'>';
                        }
                    }
                    $content .= $close_html;
                }
            }
        }
        return $content;
    }

    /**
     * Adds missing </div> tags inside <td>...</td>
     * !!! why is this a separate case?..
     * @param string $content
     * @return string
     */
    protected static function fixDiv($content)
    {
        $content = stripcslashes($content[1]);
        if (strstr($content, '</div>') === false) {
            $content = str_replace('</td>', '</div></td>', $content);
        }
        return $content;
    }
}
