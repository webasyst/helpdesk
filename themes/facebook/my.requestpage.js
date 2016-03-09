;function initRequestpage(data) {

    // show/hide blockquotes
    var toggle_quote = $('<div class="show-quote"></div>').html(data.display_original_message).click(function () {
        if ($(this).hasClass('open')) {
            $(this).next().hide();
            $(this).html(data.display_original_message).removeClass('open');
        } else {
            $(this).next().show();
            $(this).html(data.hide_original_message).addClass('open');
        }
    });
    $("#ticket .log-text blockquote").each(function () {
        $(this).hide();
        toggle_quote.clone(true).insertBefore(this);
    });

    // action buttons click handler
    $('#ticket .ticket-buttons input').click(function() {
        var self = $(this);
        self.after('<i class="icon16 loading"></i>');
        $.get(data.form_url.replace("%ACTION_ID%", self.attr('name')), function(response) {
            self.parent().find('.loading').remove();
            $('.ticket-buttons').hide();
            $('#action-form-wrapper').empty().show().html(response);
        });
        return false;
    });

    // action buttons click colors
    $('#ticket .ticket-buttons input').each(function() {
        var _color = $(this).attr('data-action-color');
        if (_color)
            $(this).css( { 'color' : _color } );
    });

    makeLinksClickable($('#request-and-log')[0]);
    $('#request-and-log .details.text a:not(.same-tab)').attr('target', '_blank').filter(function() {
        return !$(this).find('i.icon16.new-window').length;
    }).append('<i class="icon16 new-window"></i>');

    $('#ticket .request-params-changed-link').click(function() {
        $('#ticket .request-changed-params').toggle();
    });

    /**
     * Turn plain text links in given node into clickable <a> links.
     */
    function makeLinksClickable (start_node) {
        // Characters that are considered as URL finishers
        var linkEndchar = {
            ' ': true,
            '\n': true,
            '\t': true,
            '\r': true
        };
        linkEndchar[$("<div/>").html('&nbsp;').text()] = true; // non-breakable space

        // Punctuation to ignore at the end of links.
        var linkIgnoreEndchar = {
            '.': true,
            ',': true,
            ':': true,
            ';': true,
            '(': true,
            ')': true
        };

        // List of text nodes to parse for URLs
        var toParse = [];

        // Helper to look for text nodes and put them into toParse array
        var goDeep = function(node) {
            var i, c = node.childNodes.length;
            for (i = 0; i < c; ++i) {
                var child = node.childNodes[i];
                if (child.nodeType == 3) {
                    var text = child.nodeValue;
                    if( text.indexOf('http://') != -1 || text.indexOf('https://') != -1 || text.indexOf('www.') != -1 ) {
                        toParse.push(child);
                    }
                } else {
                    switch(child.nodeName.toLowerCase()) {
                        case 'a': case 'input': case 'button': case 'select': case 'option':case 'textarea': case 'style': case 'script':
                        break;
                        default:
                            goDeep(child);
                        break;
                    }
                }
            }
        };

        // Put all promising textNodes into toParse array
        if (!(start_node instanceof $)) {
            start_node = $(start_node || document.body);
        }
        for(var i = 0; i < start_node.length; i++) {
            goDeep(start_node[i]);
        }

        // Process every node in toParse replacing plain text URLs with <a> nodes
        var child, text, url_prepend, target, prev_link_end, lastLink, link_start, url, link, textNode, viewed_end, link_end;
        for (i = 0; i < toParse.length; i++) {
            child = toParse[i];
            text = child.nodeValue;

            link = null;
            target = null;
            prev_link_end = 0;
            lastLink = null;
            for (link_start = 0; link_start < text.length; link_start++) {

                // Is there a URL starting at current position? If not, then skip it.
                if(text.substr(link_start, 7) == 'http://' ) {
                    url_prepend = '';
                } else if(text.substr(link_start, 8) == 'https://' ) {
                    url_prepend = '';
                } else if(text.substr(link_start, 4) == 'www.' ) {
                    url_prepend = 'http://';
                } else {
                    continue;
                }

                // Find the end of a link that started at current position
                viewed_end = link_end = link_start;
                while(viewed_end < text.length) {
                    if(linkEndchar[text.charAt(viewed_end)]) {
                        break;
                    }
                    if (!linkIgnoreEndchar[text.charAt(viewed_end)]) {
                        link_end = viewed_end + 1;
                    }
                    ++viewed_end;
                }
                link_end = Math.min(text.length, link_end);

                // Get link URL and check if it is correct
                url = text.substr(link_start, link_end - link_start);
                if (url === 'http://' || url === 'https://' || url === 'www') {
                    link_start = link_end;
                    continue;
                }

                // Create a new <a> element
                link = $('<a></a>');
                link.attr('href', url_prepend + url);
                link.text(url);
                link = link[0];

                // Cut target text node in half, leaving only part before the url
                if (!target) {
                    child.nodeValue = text.substr(0, link_start);
                    target = child;
                } else {
                    textNode = document.createTextNode(text.substr(prev_link_end, link_start - prev_link_end));
                    child.parentNode.insertBefore(textNode, lastLink.nextSibling);
                    target = textNode;
                }

                // Insert <a> with the URL
                child.parentNode.insertBefore(link, target.nextSibling);

                prev_link_end = link_end;
                link_start = link_end;
                lastLink = link;
            }

            // Insert back the part of a text after the last link
            if(link && text.substr(link_end)) {
                child.parentNode.insertBefore(document.createTextNode(text.substr(link_end)), link.nextSibling);
            }
        }
    }
};
