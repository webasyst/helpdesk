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
            $('.js-select-all-checkbox').unbind('change').bind('change', function() {
                const is_checked = $(this).is(':checked');
                $('.requests-table td :checkbox').prop('checked', is_checked).trigger('change');
                $('.requests-table tr').toggleClass('selected', is_checked);
                $(this).prop('indeterminate', false);
            });
            $('.js-select-all-item').unbind('click').bind('click', function(e) {
                if ($(e.target).is(':checkbox')) {
                    return true;
                }
                const $checkbox = $(this).find(':checkbox');
                let checked = $checkbox.prop('checked');
                $checkbox.prop('checked', $checkbox.prop('indeterminate') ? checked : !checked).trigger('change');
            });

            // init sort menu
            $('.h-sorting-menu')
                .find('.h-sort-link').unbind('click').bind('click', function() {
                    var p = Math.ceil(parseInt(self.getSettings().offset) / parseInt(self.getSettings().limit)) + 1;
                    $.wa.setHash(self.getPagingHash(p, undefined, $(this).data('order')));
                });
            $('.h-sorting-menu').find('.h-sort-link').each(function() {
                if ($(this).data('order') === settings.order) {
                    $('.h-sorting-menu .h-choose-sorting').html($(this).html());
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

        var $old_dom_element = domElement;
        this.load((r) => {
            if (!$old_dom_element.closest('html').length) {
                return;
            }

            var $new_table = this.getTable();
            $('#hd-support-content').empty().append($new_table);
            $('.h-header-above-requests').change(); // refresh selected count

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

            domElement = $('<div class="support-content-inner"></div>');
            var settings = grid.getSettings();
            var p = Math.ceil(parseInt(settings.offset) / parseInt(settings.limit)) + 1;

            // Links for list views
            var $header = $('#hd-requests-header');
            var hasHeaderAboveRequestsTable = () => !!$header.find('.h-header-above-requests').length;
            var $bottom_header = hasHeaderAboveRequestsTable()
                ? $header.find('.h-header-above-requests')
                : $('<div class="js-mobile-hide-with-sidebar h-header-above-requests" id="list-views-toggle"><div class="h-list-views toggle small js-topic-control" /></div>');

            $bottom_header.find('.h-list-views').empty().append($.parseHTML(
                '<a rel="list"'+(settings.view == 'list' ? ' class="selected"' : '')+' href="'+this.getPagingHash(p, undefined, undefined, 'list')+'"><i class="fas fa-th-list view-thumb-list"></i></a>'+
                '<a rel="list"'+(settings.view == 'split' ? ' class="selected"' : '')+' href="'+this.getPagingHash(p, undefined, undefined, 'split')+'"><i class="fas fa-columns view-splitview"></i></a>'+
                '<a rel="table"'+(settings.view == 'table' ? ' class="selected"' : '')+' href="'+this.getPagingHash(p, undefined, undefined, 'table')+'"><i class="fas fa-list view-table"></i></a>'
            ));
            if (settings.view !== 'split') {
                $header.addClass('h-fixed')
            }

            // add header above requests table
            if (!hasHeaderAboveRequestsTable()) {
                $header.find('.h-header-block').append($bottom_header);
            }

            // dummy: empty list
            if (total == 0) {
                return domElement.append($(`
                    <div class="h-no-requests-dummy flexbox gray height-75 justify-content-center middle space-8 vertical">
                        <div class="icon size-96 text-light-gray"><i class="far fa-life-ring"></i></div>
                        <div>${$_('No requests.')}</div>
                    </div>
                `));
            }

            var domTable = null;
            if (settings.view === 'table') {
                domTable =  $('<table class="requests-table full-width view-'+settings.view+'">'+
                                '<thead></thead><tbody></tbody></table>');

            } else if (settings.view === 'split') {
                domTable =
                    $(`<div class="flexbox">
                        <div class="sidebar width-adaptive-widest blank width-100-mobile" id="h-request-sidebar" data-mobile-sidebar="init" style="overflow:auto;">
                            <table class="requests-table full-width view-${settings.view} break-word">
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="content not-blank" id="h-request-content" data-mobile-content=""></div>
                    </div>`);
            } else {
                domTable = $('<table class="requests-table full-width bottom-bordered view-'+settings.view+'">'+
                             '<tbody></tbody></table>');
            }

            if (settings.view === 'table') {
                domTable.addClass('single-lined');
                domTable.find('thead').prepend(`<tr class="nowrap uppercase small">
                    <th></th>
                    <th>${$_('Status')}</th>
                    <th></th>
                    <th>${$_('ID')}</th>
                    <th>${$_('Subject and text')}</th>
                    <th>${$_('Time has passed')}</th>
                    <th>${$_('Updated')}</th>
                    <th>${$_('From')}</th>
                    <th>${$_('Assigned')}</th>
                </tr>`);
            }
            var rs = requests, tbody = domTable.find('tbody');
            for(var i = 0; i < rs.length; i++) {
                tbody.append(getTableRow(rs[i], settings));
            }

            // Make row checkboxes select the rows
            domTable.on('change', 'td :checkbox', function() {
                var cb = $(this);
                var $select_all_checkbox = $('.js-select-all-checkbox');

                var all_checkboxes = domTable.find(`${domTable.is(':not(table)') ? '.requests-table ' : ''}td :checkbox`);
                var count_checked = all_checkboxes.filter(':checked').length;
                if (count_checked > 0) {
                    if (count_checked === all_checkboxes.length) {
                        $select_all_checkbox.prop({
                            "checked": true,
                            "indeterminate": false
                        });
                    } else {
                        $select_all_checkbox.prop({
                            "checked": false,
                            "indeterminate": true
                        });
                    }
                } else {
                    $select_all_checkbox.prop({
                        "checked": false,
                        "indeterminate": false
                    });
                }

                if (cb.is(':checked')) {
                    cb.closest('tr').addClass('selected');
                } else {
                    cb.closest('tr').removeClass('selected');
                    domTable.find('th :checkbox').prop('checked', false);
                }
            });
            domElement.toggleClass('table-scrollable-x', settings.view !== 'split').append(domTable);

            // add bottom paging
            var paging = this.getPaging(total, true, true, settings.view === 'split');
            if (settings.view === 'split' && total > 0) {
                paging.css({ 'padding-bottom': '10rem' }).appendTo(domElement.find('#h-request-sidebar'));
            } else {
                domElement.append(paging);
            }

            // Current sort col
            var order_th = domElement.find('[data-order="'+settings.order.replace('!', '')+'"]');
            if (order_th.length) {
                order_th.children('a').append($('<i class="icon16"></i>'));
                if (settings.order[0] === '!') {
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

            if (settings.view === 'split') {
                var $request_sidebar = domElement.find('#h-request-sidebar');
                $request_sidebar.prepend($header.detach());

                if (!settings.request && rs.length) {
                    const { id } = rs[0];
                    if (id) {
                        $.wa.setHash(location.hash.replace(new RegExp('/split/\\w+'), `/split/${id}`));
                    }
                    settings.request = id;
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
                        grid.changeIdInSplit(id);
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

        const self = this;
        setTimeout(() => {
            // Records per page selector
            $('#records-per-page').off('change').on('change', function() {
                if (!self.isActive()) {
                    return;
                }
                $.wa.setHash(($.wa.helpdesk_controller.hashBase || '#/') + $(this).val());
            });
            $(document).trigger('wa_content_sidebar_loaded.helpdesk');
        });

        return domElement;
    };

    this.loadRequestInSplit = function(request_id) {
        $.wa.helpdesk_controller.showLoading();
        var $request_content = domElement.find('#h-request-content');
        var $request_sidebar = domElement.find('#h-request-sidebar');
        request_id = parseInt(request_id);
        if (request_id > 0) {
            $request_content.html(`
            <div class="skeleton">
            <div class="article wide">
            <div class="article-body custom-py-20">
                <div class="content flexbox">
                    <div class="width-100">
                        <div class="flexbox middle">
                            <span class="button custom-mr-16 light-gray" style="width: 120px;height:32px;"></span>
                            <span class="skeleton-line custom-mb-0" style="width:42px;height:20px;"></span>
                        </div>

                        <div class="flexbox middle custom-mt-32">
                            <span class="skeleton-line custom-mb-8" style="width:70%;height:32px;"></span>
                        </div>

                        <span class="skeleton-list custom-m-0 custom-ml-40 custom-mt-16" style="height: 18px; width: 42%;"></span>
                        <span class="skeleton-line custom-mb-8 custom-mt-16" style="width:70%;height:56px;"></span>

                        <span class="skeleton-list custom-m-0 custom-ml-40 custom-mt-16" style="height: 14px; width: 42%;"></span>
                        <span class="skeleton-list custom-m-0 custom-ml-40 custom-mt-16" style="height: 14px; width: 30%;"></span>

                        <div class="flexbox vertical space-8 custom-ml-4 custom-mt-32">
                            <span class="skeleton-line custom-mb-8" style="width:50%;height:12px;"></span>
                            <span class="skeleton-line custom-mb-8" style="width:50%;height:12px;"></span>
                            <span class="skeleton-line custom-mb-8" style="width:50%;height:12px;"></span>
                        </div>

                        <div class="flexbox wrap custom-mt-32">
                            <span class="button custom-mr-16 custom-mt-8 rounded light-gray" style="width: 120px;height: 32px;"></span>
                            <span class="button custom-mr-16 custom-mt-8 rounded light-gray" style="width: 120px;height: 32px;"></span>
                            <span class="button custom-mr-16 custom-mt-8 rounded light-gray" style="width: 100px;height: 32px;"></span>
                        </div>
                        <span class="skeleton-line custom-mt-20" style="height: 1px;"></span>

                        <div class="box flexbox vertical custom-mt-32">
                            <span class="skeleton-custom-box" style="height: 70px; margin-bottom: 2rem;border-radius: 1rem;"></span>
                            <span class="skeleton-custom-box" style="height: 74px; margin-bottom: 2rem;border-radius: 1rem;"></span>
                            <span class="skeleton-custom-box" style="height: 94px; margin-bottom: 2rem;border-radius: 1rem;"></span>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </div>
            `);
            $.get( '?module=requests&action=info&id=' + request_id, function (r) {
                $(window).scrollTop(0, 200);
                $request_sidebar.find('[rel="'+request_id+'"]').addClass('selected2').siblings().removeClass('selected2');
                $request_content.empty().html(r);
                const $ha = $('#hd-announcement');
                if ($ha.length) {
                    $request_content.prepend($ha.detach().show());
                }

                $request_content.find('.js-mobile-back').removeClass('hidden');
                $.wa.helpdesk_controller.hideLoading();
                $(document).trigger('wa_loaded.helpdesk');
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
    this.getPaging = function(total, show_total, show_options, is_render_hidden_button) {
        if (total == 0) {
            return;
        }
        var paging = $('<div class="hd-paging"></div>');

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
                paging.append('<div class="hd-page-num small">'+$_('Show %s records on a page').replace('%s', '<select id="records-per-page">'+options+'</select>')+'</div>');
            }
        }

        // Pagination
        var type_is_page = paginator_type === 'page' && !is_render_hidden_button;
        var pages = Math.ceil(total / parseInt(settings.limit));
        var p = Math.ceil(parseInt(settings.offset) / parseInt(settings.limit)) + 1;
        var html = '<ul class="'+(type_is_page ? 'paging' : 'hd-pages list')+'">';

        const dummy_link = 'javascript:void(0)';
        const dummy_class = 'opacity-0 pointer-events-none';
        const has_prev_page = p > 1;
        if (has_prev_page || is_render_hidden_button) {
            html += `<li><a href="${has_prev_page ? this.getPagingHash(p-1) : dummy_link}" class="prevnext ${!type_is_page ? 'button nobutton small rounded' : ''} custom-mr-0 ${has_prev_page ? '' : dummy_class}">
                ${type_is_page ? '←': `<i class="fas fa-caret-left icon middle"></i> ${$_('prev')}`}
            </a></li>`;
        }

        if (pages > 1) {
            if (type_is_page) {
                var f = 0;
                for (var i = 1; i <= pages; i++) {
                    if (Math.abs(p - i) < 3 || i < 5 || pages - i < 3) {
                        html += '<li' + (i == p ? ' class="selected"' : '') + '><a href="'+this.getPagingHash(i)+'">' + i + '</a></li>';
                        f = 0;
                    } else if (f++ < 3) {
                        html += '.';
                    }
                }
            } else {
                html += `<li class="small">${(parseInt(settings.offset, 10) + 1)} &mdash; ${Math.min(total, (parseInt(settings.offset, 10) + parseInt(settings.limit, 10)))}</li>`;
                html += `<li class="small">${$_('of')} ${total}</li>`;
            }

        } else if (paginator_type !== 'page' && pages > 0) {
            html += `<li class="small">${Math.min(parseInt(settings.offset, 10) + 1, total)} &mdash; ${Math.min(total, (parseInt(settings.offset, 10) + parseInt(settings.limit, 10)))}</li>`;
            html += `<li class="small">${$_('of')} ${total}</li>`;
        }

        // Prev and next links
        const has_next_page = p < pages;
        if (has_next_page || is_render_hidden_button) {
            html += `<li><a href="${has_next_page ? this.getPagingHash(p+1) : dummy_link}" class="prevnext ${!type_is_page ? 'button nobutton small rounded' : ''} ${has_next_page ? '' : dummy_class}">
                ${type_is_page ? '→': `${$_('next')} <i class="fas fa-caret-right icon middle"></i>`}
            </a></li>`;
        }

        html += '</ul>';

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

    /** Change ID for split mode */
    this.changeIdInSplit = function (new_id) {
        hash[4] = new_id;
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
        settings = settings || getSettings();

        var truncStr = (str, max_length) => {
            if (!str) {
                return '';
            }
            if (str.length > max_length) {
                return String(str).slice(0, max_length) + '...';
            }

            return str;
        };

        var getStatusBadgeElement = (right_element) => {
            var state_name = r.state;
            var status_badge = state_name
                ? `<div class="state-wrapper"><span class="state-name badge"
                        style="${(String(r.list_row_css).trim() ? r.list_row_css.replace('color:', 'background:') : 'background:var(--light-gray);font-style:italic;')}"
                        title="${state_name}"><span>${state_name}</span>
                    </span>`
                : '';

            if (right_element) {
                status_badge += right_element;
            }
            if (state_name) {
                status_badge += '</div>';
            }

            return status_badge;
        };
        var getIndicatorsElement = () => {
            var indicators  = '<span class="indicators-list">';
            if (r.priority === 1) {
                indicators += '<i class="fas fa-exclamation-triangle text-red"></i> ';
            }
            if (r.is_presale) {
                indicators += '<i class="fas fa-wallet text-green"></i> ';
            }
            if (r.is_unread) {
                indicators += '<i class="fas fa-dot-circle text-orange"></i>';
            }
            if (r.type_is_requred) {
                indicators += `<i class="fas fa-exclamation-circle text-gray fa-sm" title="${$_('Request type must be specified')}"></i>`;
            }
            indicators += '</span>';
            return indicators;
        };
        var getSourceBadgeElement = () => {
            return r.source_class
                ? `<span class="userstatus ${r.source_bg}" title="${r.source_name}">
                        <i class="${r.source_class}"></i>
                    </span>`
                : '';
        };

        var tr;
        if (settings.view === 'table') {
            tr = $('<tr'+(r.is_unread ? ' class="unread"' : '')+' rel="'+r.id+'">'+
                    '<td class="checkboxes-col min-width"><input type="checkbox"></td>'+
                    '<td class="state-col min-width">'+getStatusBadgeElement()+'</td>'+
                    '<td class="indicators-col min-width">'+getIndicatorsElement()+'</td>'+
                    '<td class="id-col min-width"><span class="request-id">'+formatRequestId(r.id)+'</span></td>'+
                    '<td class="summary-col"><div><span class="h-summary">'+r.summary+'</span>' + (r.text_clean ? ' <span class="h-summary-text small"></span>' : '')+'<i class="shortener"></i></div></td>'+
                    '<td class="age-col nowrap">'+r.age+'</td>'+
                    '<td class="upd-col nowrap">'+r.upd_formatted+'</td>'+
                    '<td class="client-col"><div>'+r.client_name+'<i class="shortener"></i></div></td>'+
                    '<td class="assigned-col nowrap"><div>'+(r.assigned_name || '<span class="gray">'+$_('No assigned')+'</span>')+'<i class="shortener"></i></div></td>'+
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
        } else if (settings.view === 'split') {
            tr = $('<tr' + (r.is_unread ? ' class="unread"' : '') + ' rel="' + r.id + '">' +
                        '<td class="checkboxes-col"><input type="checkbox"></td>' +
                        `<td class="avatar-col">
                            <a class="userpic userpic48" data-request-id="${r.id}" href="#/request/${r.id}/" class="request-summary">
                                <img src="${(r.client_photo_url || '../../wa-content/img/userpic50.jpg')}" width="50" height="50">
                                ${getSourceBadgeElement()}
                            </a>
                        </td>` +
                        '<td class="description-col">' +
                            `<div class="description-top">${getStatusBadgeElement(getIndicatorsElement())} <div class="workflow-name text-ellipsis" title="${r.workflow}">${r.workflow}</div></div>`+
                            `<div><a data-request-id="${r.id}" href="#/request/${r.id}/" class="request-summary">${r.summary}</a></div>` +
                            `<div class="description-text text-clean small" title="${String(r.text_clean)}"><div class="text-ellipsis">${truncStr(String(r.text_clean), 64)}</div></div>` +
                            `<div class="description-bottom">
                                <span class="client-name small text-ellipsis" title="${formatRequestId(r.id)} ${r.client_name.replace(new RegExp('<\\/?strong>', 'g'),'')}"><span class="semibold custom-mr-8">${formatRequestId(r.id)}</span>${r.client_name}</span>
                                <div class="datetime-formatted hint text-ellipsis" title="${r.dt_formatted}">${r.age}</div>
                             </div>` +
                        '</td>' +
                    '</tr>');
        } else {
            // list
            tr = $('<tr'+(r.is_unread ? ' class="unread"' : '')+' rel="'+r.id+'">'+
                    '<td class="checkboxes-col"><input type="checkbox"></td>'+
                    `<td class="avatar-col">
                        <a href="#/request/${r.id}/" class="userpic userpic48" data-request-id="${r.id}" class="request-summary">
                            <img src="${(r.client_photo_url || '../../wa-content/img/userpic50.jpg')}" width="50" height="50">
                            ${getSourceBadgeElement()}
                        </a>
                    </td>` +
                    '<td class="description-col">'+
                        `<div class="description-top">
                            ${getStatusBadgeElement(getIndicatorsElement())}<a href="#/request/${r.id}/" class="request-summary">${r.summary}</a>
                        </div>`+
                        `<div class="description-text text-clean" title="${r.text_clean}">${(r.text_clean ? '<div class="h-summary-text text-ellipsis"></div>' : '')}</div>`+
                        '<div class="description-bottom">'+
                            '<span class="client-name"><span class="request-id semibold"> '+formatRequestId(r.id)+'</span> ' + r.client_name + '</span> '+
                            '<span class="age hint custom-ml-4">'+r.age+' '+$_('ago')+'</span> '+
                        '</div>'+
                    '</td>'+
                    '<td class="info-col">'+
                        `<div class="first-row assigned-header text-ellipsis"><span class="source" title="${$_('Workflow')}: ${r.workflow}"> ${r.workflow}</span></div>`+
                        '<div class="second-row">'+
                            (r.assigned_contact_id-0 == 0
                                ? `<span class="hint text-ellipsis">${$_('No assigned')}</span>`
                                : `<span class="assigned-name text-ellipsis">${r.assigned_name}</span>`
                            )+
                        '</div>'+
                        '<div class="third-row">'+
                        (r.last_log_id > 0
                            ? (
                                '<span class="performs-action text-ellipsis">'+r.time_since_update+' '+$_('ago')+'</span> '+
                                '<span class="hidden performs-action-text">'+r.actor_name+' '+r.last_action_performs_string+'</span>'
                            )
                            : '<span class="no-actions-yet hint text-ellipsis">'+$_('No actions with this request.')+'</span>'
                        )+
                        '</div>'+
                    '</td>'+
                '</tr>');
            tr.find('.assigned-name').attr('title', $_('Assigned:')+' '+tr.find('.assigned-name').text());
            tr.find('.performs-action').attr('title', $_('Last action')+' '+tr.find('.performs-action-text').text());
            tr.find('.h-summary-text').html(r.text_clean);

            var $summary_text = tr.find('.h-summary-text');
            var text = $summary_text.html() || '';
            tr.find('.h-summary-text').html(truncStr(text, 200));
        }

        return tr;
    };

    var formatRequestId = function(id) {
        return '#%s'.replace('%s', id);
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
