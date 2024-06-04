(function($) { "use strict";

    $.wa.helpdesk_history = {
        data: null,
        updateHistory: function (historyData) {
            $.wa.helpdesk_controller.cleanHash(location.hash);
            this.data = historyData;
            var li;
            var searchUl = $('#hd-search-history').empty();
            for (var i = 0; i < historyData.length; i++) {
                var h = historyData[i];
                h.hash = $.wa.helpdesk_controller.cleanHash(h.hash);
                li = $('<li rel="' + h.id + '">' +
                    (h.cnt >= 0 ? '<span class="count">' + h.cnt + '</span>' : '') +
                    '<a href="' + h.hash + '"></a>' +
                    '</li>');
                li.children('a').prepend($('<span />').text(h.name));
                searchUl.append(li);
            }

            // Link to clear history
            if (historyData.length > 0) {
                li = $('<li rel="' + h.id + '">' +
                    '<a href="javascript:void(0)" class="small text-gray"></a>' +
                    '</li>');
                li.children('a').text($_('Clear')).prepend('<i class="fas fa-times-circle"></i>').on('click', function () {
                    $.wa.helpdesk_history.clear('search');
                });
                searchUl.append(li);
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
            if (!ul.children().length) {
                link.hide();
            } else {
                link.show();
            }
        }
    };
})(jQuery);
