/**
 * $.wa.Grid is a constructor for objects representing request lists. Grid objects wrap
 * DOM objects and contain additional functionality to work with them, such as
 * load and update from PHP, get selected rows, etc.
 *
 * The basic use case for this class is as follows.
 *
 * $.wa.helpdesk_controller.dispatch() calls an Action depending on URL hash part before first integer.
 *
 * Action (probably consuming more URL-hash parts) creates a new $.wa.Grid object
 * and saves it as $.wa.helpdesk_controller.currentGrid for later use.
 * $.wa.Grid() constructor takes options array with keys:
 * - options.hash: array with non-consumed URL-hash parts (defaults to []),
 * - options.filters: filters to pass to PHP controller (defaults to ''),
 * - options.url: PHP controller URL (defaults to RequestsList),
 * - options.is_search: true to save in search history.
 * - options.header: string to save as a search header in sidebar.
 * - options.paginator_type: string - type of paginator (page, range)
 * Then Action calls Grid.load(callback).
 *
 * Grid.load() adds more filters according to URL-hash parts (e.g. pagination)
 * and performs an AJAX request. When response comes, Grid calls Action's callback.
 *
 * The callback gets DOM table from Grid.getTable() and puts it in appropriate place in the document.
 *
 * Inside $.wa.helpdesk_controller.currentGrid it keeps for future use:
 * - Grid-side URL-hash parts
 * - Filters from both Action and Grid
 * - PHP controller URL
 * - mark object that came from RequestList
 * - DOM table
 *
 * Once in a while Controller calls $.wa.helpdesk_controller.currentGrid.update().
 * It checks whether DOM table is still part of the document. If not, it frees memory.
 * Otherwise, it initiates an AJAX request to PHP controller with an additional filter: only show records
 * updated since last update. Response get merged with existing data according to rules specified inside Grid
 * and DOM table gets modified in place.
 */
$.wa.Grid = function(options) { "use strict";
    //
    // Private fields
    //

    options = options || {};

    /** URL-hash parts not consumed by Action */
    var hash = options.hash || [];

    /** PHP controller to request data from */
    var url = options.url || '?module=requests&action=list';

    /** Filters to pass to PHP controller */
    var filters = options.filters || '';

    /**
     * Save in search history ?
     */
    var is_search = options.is_search || false;

    /**
     * String to save as a search header in sidebar.
     */
    var header = options.header || '';

    var paginator_type = options.paginator_type || 'page';

    /** Mark from PHP controller to identify updated requests during the next update(). */
    var mark;

    /** Total number of requests in current view. */
    var total;

    /** Requests from this.load() that this.getTable() needs to show. */
    var requests;

    /** DOM object representing the list.
      * Basically a cache var for this.getTable() */
    var domElement;

    //
    // Public methods
    //

    /** Load data from URL passed to constructor and call callback (bound to $.wa.helpdesk_controller) when this Grid is ready. */
    this.load = function(callback) {
        var settings = this.getSettings();
        var self = this;
        $.post( url, {
            'order': settings.order,
            'limit': settings.limit,
            'offset': settings.offset,
            'list_view': settings.view,
            'is_search': is_search ? 1 : 0,
            'filters': compileFilters(filters),
            'header': header
        }, function(r) {
            r = r.data;
            mark = r.mark;
            total = r.count;
            requests = r.requests;
            filters = r.filters || filters;
            domElement = null;

            $.wa.helpdesk_controller.listResultEvent(r);

            if (callback && typeof callback == 'function') {
                callback.call($.wa.helpdesk_controller, r);
            }

            // Make checkbox in header toggle all checkboxes in table
            $('.h-select-all-checkbox').unbind('click').bind('click', function() {
                    if ($(this).is(':checked')) {
                        $('.requests-table td :checkbox').attr('checked', true);
                        $('.requests-table tr').addClass('selected');
                    } else {
                        $('.requests-table td :checkbox').attr('checked', false);
                        $('.requests-table tr').removeClass('selected');
                    }
                });

            // init sort menu
            $('.h-sorting-menu')
                .find('.h-sort-link').unbind('click').bind('click', function() {
                    var p = Math.ceil(parseInt(self.getSettings().offset) / parseInt(self.getSettings().limit)) + 1;
                    $.wa.setHash(self.getPagingHash(p, undefined, $(this).data('order')));
                });
            $('.h-sorting-menu').find('.h-sort-link').each(function() {
                if ($(this).data('order') === settings.order) {
                    $('.h-sorting-menu .h-name').text($(this).text());
                    return false;
                }
            });


        }, 'json');
    };

    /** Reload the whole table from server and replace existing table element in DOM. */
    this.reload = function(callback) {
        if (!this.isActive()) {
            return false;
        }

        var self = this;
        var grid_wrapper = domElement.parent();
        var old_dom_element = domElement;

        this.load(function(r) {
            if (!old_dom_element.closest('html').length) {
                return;
            }

            var new_table = self.getTable();
            var els = old_dom_element.find('.header-above-requests-table').children();
            new_table.find('.header-above-requests-table').empty().append(els);
            grid_wrapper.empty().append(new_table);
            new_table.find('.header-above-requests-table').change();

            if (callback && typeof callback == 'function') {
                callback.call($.wa.helpdesk_controller, r);
            }
        });

        return true;
    };

    var getSettings = this.getSettings = function() {
        var limit = hash[1];
        if (!limit || limit <= 0) {
            limit = parseInt($.storage.get('helpdesk/grid/limit'), 10) || 30;
        }
        $.storage.set('helpdesk/grid/limit', limit);

        var order = hash[2] || $.storage.get('helpdesk/grid/order') || 'id';
        $.storage.set('helpdesk/grid/order', order);

        var view = hash[3] || $.storage.get('helpdesk/grid/view') || 'list';
        if (!{ list: 1, table: 1, split: 1 }[view]) {
            view = 'list';
        }
        $.storage.set('helpdesk/grid/view', view);

        var request = hash[4] || 0;

        return {
            view: view,
            order: order,
            offset: hash[0] && hash[0] >= 0 ? hash[0] : 0,
            limit: limit,
            request: request
        };
    };

    /** Return DOM object representing this list.
      * Throws an error if this.load() has not been called or not ready yet. */
    this.getTable = function(from_cache) {
        if (!domElement && !from_cache) {
            var grid = this;
            if(!requests) {
                throw new Error('$.wa.Grid.getTable() can not be called before load()');
            }

            var settings = grid.getSettings();

            if (settings.view == 'table') {
                domElement =  $('<table class="full-width bottom-bordered requests-table view-'+settings.view+'">'+
                                '<tbody></tbody></table>');
            } else if (settings.view == 'split') {
                domElement =  $('<div class="sidebar left300px" id="h-request-sidebar" style="border-top: 1px solid #CCC;">' +
                                '<table class="full-width bottom-bordered requests-table view-'+settings.view+'" style="word-break: break-all;">'+
                                '<tbody></tbody></table>' +
                                '</div>' +
                                '<div class="content left300px" id="h-request-content">' +
                                '</div>');
            } else {
                domElement =  $('<table class="full-width bottom-bordered requests-table view-'+settings.view+'">'+
                                '<tbody></tbody></table>');
            }

            if (total <= 0) {
                domElement = $('<div></div>');
            }

            if (settings.view == 'table') {
                domElement.addClass('zebra single-lined');
            }
            var rs = requests, tbody = domElement.find('tbody');
            for(var i = 0; i < rs.length; i++) {
                tbody.append(getTableRow(rs[i], settings));
            }

            // Make row checkboxes select the rows
            domElement.on('change', 'td :checkbox', function() {
                var cb = $(this);
                if (cb.is(':checked')) {
                    cb.closest('tr').addClass('selected');
                } else {
                    cb.closest('tr').removeClass('selected');
                    domElement.find('th :checkbox').prop('checked', false);
                }
            });

            domElement = $('<div class="block not-padded"></div>').append(domElement);
            var p = Math.ceil(parseInt(settings.offset) / parseInt(settings.limit)) + 1;
            var paging = this.getPaging(total, true, true);
            if (settings.view == 'split' && total > 0) {
                $('<div class="block not-padded float-left">').css({
                    position: 'relative',
                    width: '100%'
                }).append(
                    paging.css({
                        left: 0,
                        right: 0
                    })
                ).appendTo(domElement.find('#h-request-sidebar'));
            } else {
                domElement.append(paging);
            }

            // Links for list views
            domElement.prepend($.parseHTML(
                '<div class="block header-above-requests-table bottom-bordered">'+
                    '<ul class="list-views menu-h float-right">'+
                        '<li rel="list"'+(settings.view == 'list' ? ' class="selected"' : '')+'><a href="'+this.getPagingHash(p, undefined, undefined, 'list')+'"><i class="icon16 view-thumb-list"></i></a></li>'+
                        '<li rel="list"'+(settings.view == 'split' ? ' class="selected"' : '')+'><a href="'+this.getPagingHash(p, undefined, undefined, 'split')+'"><i class="icon16 view-splitview"></i></a></li>'+
                        '<li rel="table"'+(settings.view == 'table' ? ' class="selected"' : '')+'><a href="'+this.getPagingHash(p, undefined, undefined, 'table')+'"><i class="icon16 view-table"></i></a></li>'+
                    '</ul>'+
                    '<div class="clear-both"></div>'+
                '</div>'
            ));

            // Current sort col
            var order_th = domElement.find('[data-order="'+settings.order.replace('!', '')+'"]');
            if (order_th.length) {
                order_th.children('a').append($('<i class="icon16"></i>'));
                if (settings.order[0] == '!') {
                    order_th.find('i.icon16').addClass('darr');
                    order_th.children('a').attr('href', this.getPagingHash(p, undefined, settings.order.substring(1)));
                } else {
                    order_th.find('i.icon16').addClass('uarr');
                }
            }

            requests = null;

            // Hook for plugins
            var e = new $.Event('helpdesk.list.element');
            e.list_element = domElement;

            if (settings.view == 'split') {
                var $request_sidebar = domElement.find('#h-request-sidebar');

                if (!settings.request && rs.length) {
                    settings.request = rs[0].id;
                }
                if (settings.request) {
                    this.loadRequestInSplit(settings.request);

                    var onSplitCellClick = function(id) {
                        var settings = grid.getSettings();
                        location.hash = $.wa.helpdesk_controller.hashBase +
                                        settings.offset + '/' +
                                        settings.limit + '/' +
                                        settings.order + '/' +
                                        settings.view + '/' +
                                        id;
                        $.wa.helpdesk_controller.stopDispatch(1);
                        hash[4] = id;
                        grid.loadRequestInSplit(id);
                    };

                    $request_sidebar.on('click', 'td', function(e) {
                        var td = $(this);
                        var t = $(e.target);
                        if (!t.is(':checkbox')) {
                            var request_id = td.find('a[data-request-id]').data('requestId');
                            onSplitCellClick(request_id);
                            if (t.is('a')) {
                                e.preventDefault();
                            }
                        }
                    });
                }
            }

            $(document).trigger(e);
        }
        return domElement;
    };

    this.loadRequestInSplit = function(request_id) {
        var $request_content = domElement.find('#h-request-content');
        var $request_sidebar = domElement.find('#h-request-sidebar');
        request_id = parseInt(request_id);
        if (request_id > 0) {
            $request_content.html('<i class="icon16 loading" style="margin: 5px;"></i>');
            $.get( '?module=requests&action=info&id=' + request_id, function (r) {
                $(window).scrollTop(0, 200);
                $request_sidebar.find('[rel="'+request_id+'"]').addClass('selected2').siblings().removeClass('selected2');
                $request_content.empty().html(r)
                    .find('.hidden.block').remove();
            });
        }
    };


    /**
     * Make paginator
     *
     * @param {Number} total
     * @param {Boolean} show_total need to show "Total contacts" area. Default: true
     * @param {Boolean} show_options need to show "Show X records on page" selector: Default: true
     * @returns {Object} jquery DOM-object
     */
    this.getPaging = function(total, show_total, show_options) {

        var paging = $('<div class="block paging">' +
                (show_total && paginator_type === 'page' ? $_('Requests:') + ' <span id="h-grid-total">' + total + '</span>' : '') +
            '</div>');

        var settings = this.getSettings();

        if (show_options) {
            var options = '', newLimit, newPage;
            var o = [30, 50, 100, 200, 500];
            if (o[0] < total) {
                for(var i = 0; i < o.length; i++) {
                    newLimit = o[i];
                    newPage = Math.floor(settings.offset/newLimit) + 1;
                    options += '<option value="'+this.getPagingHash(newPage, newLimit)+'"'+(settings.limit == o[i] ? ' selected="selected"' : '')+'>'+o[i]+'</option>';
                }
                paging.append('<div class="hd-page-num">'+$_('Show %s records on a page').replace('%s', '<select id="records-per-page">'+
                    options+
                '</select>')+'</div>');
            }
        }

        // Pagination
        var pages = Math.ceil(total / parseInt(settings.limit));
        var p = Math.ceil(parseInt(settings.offset) / parseInt(settings.limit)) + 1;
        var html = '<div class="hd-pages">';

        if (pages > 1) {

            if (paginator_type === 'page') {
                html += '<span>'+$_('Pages')+':</span>';

                var f = 0;
                for (var i = 1; i <= pages; i++) {
                    if (Math.abs(p - i) < 3 || i < 5 || pages - i < 3) {
                        html += '<a' + (i == p ? ' class="selected"' : '') + ' href="'+this.getPagingHash(i)+'">' + i + '</a>';
                        f = 0;
                    } else if (f++ < 3) {
                        html += '.';
                    }
                }
            } else {
                html += (parseInt(settings.offset, 10) + 1) + '&mdash;' + Math.min(total, (parseInt(settings.offset, 10) + parseInt(settings.limit, 10)));
                html += ' ' + $_('of') + ' '  + total;
            }

        } else if (paginator_type !== 'page') {
            if (pages <= 0) {
                html += $_('No requests.');
            } else {
                html += Math.min(parseInt(settings.offset, 10) + 1, total) + '&mdash;' + Math.min(total, (parseInt(settings.offset, 10) + parseInt(settings.limit, 10)));
                html += ' ' + $_('of') + ' '  + total;
            }
        }

        // Prev and next links
        if (p > 1) {
            html += '<a href="' + this.getPagingHash(p-1) + '" class="prevnext"><i class="icon10 larr"></i> '+$_('prev')+'</a>';
        }
        if (p < pages) {
            html += '<a href="' + this.getPagingHash(p+1) + '" class="prevnext">'+$_('next')+' <i class="icon10 rarr"></i></a>';
        }

        html += '</div>';

        paging.prepend(html);

        return paging;

    };

    this.getPagingHash = function(page, limit, order, view) {
        var settings = this.getSettings();
        limit = limit || settings.limit;
        order = order || settings.order;
        view = view || settings.view;
        return encodeURI($.wa.helpdesk_controller.hashBase) + (page-1)*limit + '/' + limit + '/' + order + '/' + view + '/';
    };

    /** Update DOM object that has been previously returned by this.getTable()
      * with new data from URL that has been passed to constructor.
      * @param callback function to call when DOM has been updated (bound to $.wa.helpdesk_controller)
      * @param append true to append rows, false to prepend (default)
      * @return boolean true if this.getTable() is part of the document, and false otherwise. */
    this.update = function(callback, append) {
        if (!this.isActive() || (this.getSettings().offset || 0) > 0) {
            return false;
        }

        var settings = this.getSettings();
        $.post(url, {
            'filters': compileFilters(filters, true),
            'list_view': settings.view,
            'background_process': 1
        }, function(r) {
            r = r.data;
            mark = r.mark;

            $.wa.helpdesk_controller.listResultEvent(r);

            var table = domElement.find('table');
            var tbody = table.find('tbody'),
                tr, selected;
            for(var i = 0; i < r.requests.length; i++) {
                selected = false;
                tr = table.find('tr[rel="'+r.requests[i].id+'"]');
                if (tr.size() > 0) {
                    selected = tr.hasClass('selected');
                    tr.remove();
                }
                if (r.requests[i].remove) {
                    continue;
                }
                if (append) {
                    tr = getTableRow(r.requests[i]).appendTo(tbody);
                } else {
                    tr = getTableRow(r.requests[i]).prependTo(tbody);
                }
                if (selected) {
                    tr.addClass('selected');
                }
            }

            // update sidebar count for current view
            $.wa.helpdesk_controller.highlightSidebar();

            if (callback && typeof callback == 'function') {
                callback.call($.wa.helpdesk_controller, r);
            }

            domElement.trigger('wa-grid-updated');
        }, 'json');

        return true;
    };

    /** @return this grid's DOM element if it is still part of the DOM; false otherwise */
    this.isActive = function() {
        if (!domElement) {
            return false;
        }
        var p = domElement;
        do {
            p = p.parent();
        } while(p.length && p[0] !== document);
        if (!p.length) {
            domElement = null;
            return false;
        }
        return true;
    };

    /** Clears cache of this.getTable() without removing old table element from the DOM.
      * Prevents subsequent update()s. */
    this.deactivate = function() {
        domElement = null;
        return this;
    };

    /** Remove table element from the DOM, if exists.
      * Clears cache of this.getTable() and stops subsequent update()s. */
    this.remove = function() {
        if (domElement) {
            domElement.remove();
            domElement = null;
        }
        return this;
    };

    /** Total number of requests in current view */
    this.getTotal = function() {
        return total;
    };


    /** Returns a string to pass to PHP controller as `filters` paarmeter value.
      * When called with no parameters, uses filters var set by constructor.
      * When update == true then an additional mark filter is added to only fetch updated rows. */
    var compileFilters = function(fltrs, update) {
        fltrs = fltrs || filters;
        if (fltrs instanceof Array) {
            var f = [];
            for(var j in fltrs) {
                if (fltrs.hasOwnProperty(j)) {
                    var fi = fltrs[j].param;
                    if (fi instanceof Array) {
                        for(var i = 0; i < fi.length; i++) {
                            fi[i] = replaceSpecials(fi[i]);
                        }
                        f.push(fltrs[j].name + (fltrs[j].op || ':') +fi.join(':'));
                    } else {
                        f.push(fltrs[j].name + (fltrs[j].op || ':') + replaceSpecials(fi));
                    }
                }
            }
            fltrs = f.join('&');
        }

        if (update) {
            return (fltrs ? fltrs + '&' : '') + 'mark:'+replaceSpecials(mark);
        } else {
            return fltrs;
        }
    };
    this.compileFilters = compileFilters;

    //
    // Private methods
    //

    /** Helper to generate table rows.
      * @param object r row data from PHP controller
      * @return jQuery object */
    var getTableRow = function(r, settings) {
        var tr;
        settings = settings || getSettings();
        if (settings.view == 'table') {
            tr = $('<tr'+(r.is_unread ? ' class="unread"' : '')+' rel="'+r.id+'"'+(r.list_row_css ? ' style="'+r.list_row_css+'"' : '')+'>'+
                    '<td class="checkboxes-col"><input type="checkbox"></td>'+
                    '<td class="id-col"><span class="request-id">'+r.id+'</span></td>'+
                    '<td class="age-col nowrap">'+r.age+'</td>'+
                    '<td class="upd-col nowrap">'+r.upd_formatted+'</td>'+
                    '<td class="client-col"><div>'+r.client_name+'<i class="shortener"></i></div></td>'+
                    '<td class="summary-col"><div><span class="h-summary">'+r.summary+'</span>' + (r.text_clean ? ' <span class="h-summary-text"></span>' : '')+'<i class="shortener"></i></div></td>'+
                    '<td class="state-col nowrap"><div class="h-hide-rest status-max-width">'+r.state+'</div></td>'+
                    '<td class="assigned-col nowrap"><div>'+r.assigned_name+'<i class="shortener"></i></div></td>'+
                '</tr>');
            tr.find('.h-summary-text').html(r.text_clean);
            tr.children(':not(.checkboxes-col)').each(function() {
                var td = $(this);
                var a = $('<a href="#/request/'+r.id+'/" style="color:inherit"></a>').html(td.html()).attr('title', td.text());
                td.empty().append(a);
            });

            var text = tr.find('.h-summary-text').html() || '';
            var summary = tr.find('.h-summary').html() || '';
            var len = 200;
            if (text.length + summary.length > len) {
                text = text.slice(0, len - summary.length > 0 ? len - summary.length : 0);
                tr.find('.h-summary-text').html(text + '...');
            }
        } else if (settings.view == 'split') {
            tr = $( '<tr' + (r.is_unread ? ' class="unread"' : '') + ' rel="' + r.id + '">' +
                        '<td class="checkboxes-col"><input type="checkbox"></td>' +
                        '<td class="avatar-col"><a data-request-id="' + r.id + '" href="#/request/' + r.id + '/" class="request-summary"><img class="userpic" src="' + (r.client_photo_url || '../../wa-content/img/userpic50.jpg') + '" width="50" height="50"></a></td>' +
                        '<td class="description-col">' +
                            '<div>' +
                            '<a data-request-id="' + r.id + '" href="#/request/' + r.id + '/" class="request-summary">' + r.summary + '</a>' +
                            '</div>' +
                            '<div>' +
                            '<span class="client-name">' + r.client_name  + '</span> ' +
                            '</div>' +
                        '</td>' +
                    '</tr>');
            tr.find('.performs-action').attr('title', tr.find('.performs-action-text').text());
        } else {
            tr = $('<tr'+(r.is_unread ? ' class="unread"' : '')+' rel="'+r.id+'">'+
                    '<td class="checkboxes-col"><input type="checkbox"></td>'+
                    '<td class="avatar-col"><a href="#/request/'+r.id+'/" class="request-summary"><img class="userpic" src="'+(r.client_photo_url||'../../wa-content/img/userpic50.jpg')+'" width="50" height="50"></a></td>'+
                    '<td class="description-col">'+
                        '<div><a href="#/request/'+r.id+'/" class="request-summary">'+r.summary+'</a>'+
                        (r.text_clean ? ' <span class="h-summary-text">&mdash; </span>' : '')+'</div>'+
                        '<div>'+
                            '<span class="client-name">' + r.client_name + '</span> '+
                            '<span class="age hint">'+r.age+' '+$_('ago')+'</span> '+
                            (r.source_class ?
                                ('<span class="via-source hint">'+$_('via')+' <i class="icon16 source-'+r.source_class+'" title="'+r.source_name+'"></i>')
                                : ''
                            )+
                        '</div>'+
                    '</td>'+
                    '<td class="info-col">'+
                        '<div class="first-row"><span class="request-id">'+formatRequestId(r.id)+'</span>'+
                        '<span class="state-name"'+(r.list_row_css ? ' style="'+r.list_row_css+'"' : '')+'>'+r.state+'</span></div>'+
                        '<div class="second-row">'+
                            '<span class="hint assigned-header">'+$_('Assigned:')+'</span>'+
                            (r.assigned_contact_id-0 == 0 ?
                                ('<strong class="assigned-name nobody"></strong>')
                                :
                                ('<strong class="assigned-name">'+r.assigned_name+'</strong>')
                            )+
                        '</div>'+
                        '<div class="third-row">'+
                        (r.last_log_id > 0 ?
                            (
                                '<span class="performs-action hint">'+$_('Last action')+' '+r.time_since_update+' '+$_('ago')+'</span> '+
                                '<span class="hidden performs-action-text">' + r.actor_name+' '+r.last_action_performs_string+'</span>'
                            )
                            :
                            '<span class="no-actions-yet">'+$_('No actions with this request.')+'</span>'
                        )+
                        '</div>'+
                    '</td>'+
                '</tr>');
            tr.find('.performs-action').attr('title', tr.find('.performs-action-text').text());
            tr.find('.h-summary-text').html(r.text_clean);

            var text = tr.find('.h-summary-text').html() || '';
            var summary = tr.find('.description-col .request-summary').html() || '';
            var len = 200;
            if (text.length + summary.length > len) {
                text = text.slice(0, len - summary.length > 0 ? len - summary.length : 0);
                tr.find('.h-summary-text').html(text + '...');
            }
        }

        return tr;
    };

    var formatRequestId = function(id) {
        return $_('#%s').replace('%s', id);
    };

    /** Escapes &, : and ` with backtick: `&, `:, ``, >=, <=. */
    var replaceSpecials = function(str) {
        return ('' + str).
                    replace(/`/g, '``').
                    replace(/\&/g, '`&').
                    replace(/:/g, '`:').
                    replace(/>=/g, '`>=').
                    replace(/<=/g, '`<=');
    };
};

