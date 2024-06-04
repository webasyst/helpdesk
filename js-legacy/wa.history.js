(function($) { "use strict";

    $.wa.helpdesk_history = {
        data: null,
        updateHistory: function (historyData) {
            var li;
            this.data = historyData;
            var searchUl = $('#hd-search-history').empty();
            var currentHash = $.wa.helpdesk_controller.cleanHash(location.hash);
            for (var i = 0; i < historyData.length; i++) {
                var h = historyData[i];
                h.hash = $.wa.helpdesk_controller.cleanHash(h.hash);
                li = $('<li rel="' + h.id + '" class="break-words">' +
                    (h.cnt >= 0 ? '<span class="count">' + h.cnt + '</span>' : '') +
                    '<a href="' + h.hash + '"></a>' +
                    '</li>');
                li.children('a').text(h.name);
                searchUl.append(li);
            }

            // Link to clear history
            if (historyData.length > 0) {
                li = $('<li rel="' + h.id + '">' +
                    '<a href="javascript:void(0)" style="font-size:10px;color:#888;text-align:center;"></a>' +
                    '</li>');
                li.children('a').text($_('Clear')).prepend('<i class="icon10 delete-bw" style="margin-top:1px"></i>').click(function () {
                    $.wa.helpdesk_history.clear('search');
                });
                searchUl.append(li);
            }

            var lists = [searchUl];
            for (var l = 0; l < lists.length; l++) {
                var ul = lists[l];
                if (ul.children().size() > 0) {
                    ul.parents('.block.wrapper').show();
                } else {
                    ul.parents('.block.wrapper').hide();
                }
            }

            $.wa.helpdesk_history.updateVisibility();
            $.wa.helpdesk_controller.highlightSidebar();
            $.wa.helpdesk_controller.restoreSidebarCounts();
        },
        clear: function (type) {
            if (!type || type == 'search') {
                type = '&ctype=' + type
            } else if (type && type == 'creation') {
                type = '&ctype[]=import&ctype[]=add';
            } else {
                type = '';
            }

            $('#hd-search-history').empty();
            $.wa.helpdesk_history.updateVisibility();
            $.get('?action=history&clear=1' + type);
            return false;
        },
        updateVisibility: function () {
            var ul = $('#hd-search-history');
            var link = $('#hd-search-history-link');
            ul.parent().hide();
            if (ul.children().length <= 0) {
                link.hide();
            } else {
                link.show();
            }
        }
    };
})(jQuery);