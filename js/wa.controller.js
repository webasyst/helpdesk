/**
 * This is a JS-controller responsible for url-hash-based navigation in backend.
 * Also serves as a collection of helper functions used throughout the app backend.
 *
 * Please note that in non-debug mode all JS scripts are compiled into js/compiled/*
 * and those compressed bundles are used for performance.
 *
 * If you modify this file (or any js file in bundle), then the {wa_js} compiler `should`
 * figure this out and recompile automatically. But who knows what can possibly happen
 * in the wild.
 *
 * See: wa-system/vendors/smarty-plugins/block.wa_js.php
 */

(function($) { "use strict";

$.wa.helpdesk_controller = {
    // options passed to this.init()
    options: null,

    // random number used to check ajax requests
    random: null,

    frontend_url: '/',

    backend_url: '/webasyst/',

    ignore_ajax_errors: false,

    // last request list view user has visited: {title: "...", hash: "..."}
    lastView: null,

    /** Kinda constructor. All the initialization stuff.
      * Called from default layout. */
    init: function (options) {
        // Initialize "persistent" storage
        $.storage = new $.store();

        // class variables
        this.options = options;
        this.frontend_url = (options && options.url) || '/';
        this.backend_url = (options && options.backend_url) || '/webasyst/';
        this.random = null;

        // Set up AJAX to never use cache
        $.ajaxSetup({
            cache: false
        });

        // auto close menus onclick
        $.wa.dropdownsCloseEnable();

        // call dispatch when hash changes
        if (typeof($.History) != "undefined") {
            $.History.bind(function (hash) {
                $.wa.helpdesk_controller.dispatch(hash);
            });
        }

        // Ignore ajax errors that are a result of XHRs being canceled
        // when user leaves page.
        $(window).on('beforeunload', function() {
            $.wa.helpdesk_controller.ignore_ajax_errors = true;
            // beforeunload can be canceled, so set this thing back just in case...
            setTimeout(function() {
                $.wa.helpdesk_controller.ignore_ajax_errors = false;
            }, 1000);
        });

        // Set up default AJAX error handler
        $.wa.errorHandler = function() {};
        $(document).ajaxError(function(e, xhr, settings, exception) {
            // Ignore 502 error in background process
            if (xhr.status === 502 && exception == 'abort' || (settings.url && settings.url.indexOf('background_process') >= 0) || (settings.data && settings.data.indexOf('background_process') >= 0)) {
                console && console.log && console.log('Notice: XHR failed on load: '+ settings.url);
                return;
            }

            // Session timeout? Show login page.
            if (xhr.getResponseHeader('wa-session-expired')) {
                window.location.reload();
                return;
            }

            // Never save pages causing an error as last hashes
            $.storage.del('helpdesk/last-hash');
            $.wa.helpdesk_controller.currentHash = null;
            $.wa.helpdesk_controller.stopDispatch(1);
            window.location.hash = '';

            // Show error page
            var page = $('body > .error-page.template').clone().appendTo($("#c-core-content").empty());
            var p = page.find('.if-'+xhr.status);
            if (p.length <= 0) {
                p = page.find('.otherwise');
            }
            p.show();
            page.removeClass('hidden template');

            // Show error details in a nice safe iframe
            var place_for_iframe = page.find('.place-for-iframe');
            if (place_for_iframe.length) {
                var iframe = $('<iframe src="about:blank" style="width:100%;height:auto;min-height:500px;"></iframe>').appendTo(place_for_iframe);
                var ifrm = (iframe[0].contentWindow) ? iframe[0].contentWindow : (iframe[0].contentDocument.document) ? iframe[0].contentDocument.document : iframe[0].contentDocument;
                ifrm.document.open();
                ifrm.document.write(xhr.responseText || $_('Empty response from server'));
                ifrm.document.close();
                console && console.log && console.log('XHR error', xhr, settings, exception);
            }

            // Close all existing dialogs
            $('.dialog:visible').trigger('close').remove();
        });

        // Auto update current grid view once a minute
        this.gridInterval = setInterval(function() {
            if ($.wa.helpdesk_controller.currentGrid) {
                $.wa.helpdesk_controller.currentGrid.update();
            }
        }, 60000);

        // Collapsible sidebar sections
        var toggleCollapse = function () {
            $.wa.helpdesk_controller.collapseSidebarSection(this, 'toggle');
        };
        $(".collapse-handler", $('#wa-app')).die('click').live('click', toggleCollapse);

        this.restoreCollapsibleStatusInSidebar();
        $.wa.helpdesk_controller.restoreSidebarCounts();

        // do not show (!) signs in helpdesk app header in helpdesk itself
        $(document).bind('wa.appcount', function() {
            $('#wa-app-helpdesk .indicator').remove();
        });
        $('#wa-app-helpdesk .indicator').remove();

        // Records per page selector
        // Since 'change' does not propagate in IE, we cannot use it with live events.
        // In IE have to use 'click' instead.
        $('#records-per-page').die($.browser.msie ? 'click' : 'change');
        $('#records-per-page').live($.browser.msie ? 'click' : 'change', function() {
            var grid = $.wa.helpdesk_controller.currentGrid;
            if (!grid || !grid.isActive()) {
                return;
            }
            $.wa.setHash(($.wa.helpdesk_controller.hashBase || '#/') + $(this).val());
        });

        // development hotkeys for redispatch and sidebar reloading
        $(document).keypress(function(e) {
            if ((e.which == 10 || e.which == 13) && e.shiftKey) {
                $('#wa-app .sidebar .icon16').first().attr('class', 'icon16 loading');
                $.wa.helpdesk_controller.reloadSidebar();
            }
            if ((e.which == 10 || e.which == 13) && e.ctrlKey) {
                $.wa.helpdesk_controller.redispatch();
            }
        });

        (function() {
            $.wa.helpdesk_controller.setHash = function(hash) {
                this.stopDispatch(0);
                $.wa.setHash.call($.wa, hash);
            };
        })();

        // Smart menus
        this.initSmartDropdowns();
    }, // end of init()

    initSmartDropdowns: function() {
        // Implement a delay before mouseover and showing menu contents.
        var recentlyOpened = null;
        $('#wa-app').on('mouseover', '.smart-dropdown', function() {
            var menu = $(this);
            if (animate(menu)) {
                menu.addClass('disabled').mouseover();
            }
        });

        // Open/close menu by mouse click
        $('#wa-app').on('click', '.smart-dropdown', function(e) {
            var menu = $(this);

            // do not close menu if it was just opened via mouseover
            if (recentlyOpened && !menu.hasClass('disabled')) {
                e.stopPropagation && e.stopPropagation();
                e.preventDefault && e.preventDefault();
                return false;
            }

            // do not count clicks in nested menus
            if ($(e.target).parents('ul#hd-add-request-btn ul').size() > 0) {
                return;
            }

            menu.toggleClass('disabled');
            if (!animate(menu) && recentlyOpened) {
                clearTimeout(recentlyOpened);
                recentlyOpened = null;
            }
        });

        function animate(menu) {
            if (menu.hasClass('animated')) {
                return false;
            }
            menu.addClass('animated');
            menu.hoverIntent({
                over: function() {
                    recentlyOpened = setTimeout(function() {
                        recentlyOpened = null;
                    }, 500);
                    menu.removeClass('disabled');
                },
                timeout: 0.3, // out() is called after 0.3 sec after actual mouseout
                out: function() {
                    menu.addClass('disabled');
                    if (recentlyOpened) {
                        clearTimeout(recentlyOpened);
                        recentlyOpened = null;
                    }
                }
            });
            return true;
        }
    },

    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    // *   Dispatch-related
    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *

    // if this is > 0 then this.dispatch() decrements it and ignores a call
    skipDispatch: 0,

    /** Cancel the next n automatic dispatches when window.location.hash changes */
    stopDispatch: function (n) {
        this.skipDispatch = n;
    },

    // last hash processed by this.dispatch()
    currentHash: null,

    /**
      * Called automatically when window.location.hash changes.
      * Call a corresponding handler by concatenating leading non-int parts of hash,
      * e.g. for #/aaa/bbb/ccc/111/dd/12/ee/ff
      * a method $.wa.helpdesk_controller.AaaBbbCccAction(['111', 'dd', '12', 'ee', 'ff']) will be called.
      */
    dispatch: function (hash) {
        if (this.skipDispatch > 0) {
            this.skipDispatch--;
            return false;
        }

        if (hash === undefined) {
            hash = this.getHash();
        } else {
            hash = this.cleanHash(hash);
        }

        if (this.currentHash == hash) {
            return;
        }
        var old_hash = this.currentHash;
        this.currentHash = hash;

        var e = new $.Event('wa_before_dispatched');
        $(window).trigger(e);
        if (e.isDefaultPrevented()) {
            this.currentHash = old_hash;
            window.location.hash = old_hash;
            return false;
        }

        this.currentGrid && this.currentGrid.deactivate();
        this.currentGrid = null;

        hash = hash.replace(/^[^#]*#\/*/, '');

        if (hash) {
            hash = hash.split('/');
            if (hash[0]) {
                var actionName = "";
                var attrMarker = hash.length;
                for (var i = 0; i < hash.length; i++) {
                    var h = hash[i];
                    if (i < 2) {
                        if (i === 0) {
                            actionName = h;
                        } else if (parseInt(h, 10) != h) {
                            actionName += h.substr(0,1).toUpperCase() + h.substr(1);
                        } else {
                            attrMarker = i;
                            break;
                        }
                    } else {
                        attrMarker = i;
                        break;
                    }
                }
                var attr = hash.slice(attrMarker);
                if (this[actionName + 'Action']) {
                    this[actionName + 'Action'](attr);
                } else if (this[hash[0] + 'Action']) {
                    this[hash[0] + 'Action'](hash.slice(1));
                } else if (console) {
                    console && console.log && console.log('Invalid action name: ', actionName+'Action');
                }
            } else {
                console && console.log && console.log('DefaultAction');
                this.defaultAction();
            }

            if (hash.join) {
                hash = hash.join('/');
            }

            // save last page to return to by default later
            $.storage.set('helpdesk/last-hash', hash);
        } else {
            this.defaultAction();
        }

        // Highlight current item in history, if exists
        this.highlightSidebar();

        $(window).trigger('wa-dispatched');
    },

    /** Force reload current hash-based 'page'. */
    redispatch: function() {
        this.currentHash = null;
        this.dispatch();
    },

    /** Load last page  */
    lastPage: function() {
        var hash = $.storage.get('helpdesk/last-hash');
        if (hash) {
            $.wa.setHash('#/'+hash);
        } else {
            this.defaultAction();
        }
    },

    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    // *   Actions (called by dispatch() when hash changes)
    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *

    /** Default action to use when no #/hash/ is given. */
    defaultAction: function() {
        setTimeout(function() {
            var href = $('#hd-main-filters a:visible:first').attr('href');
            if (!href) {
                href = $('#hd-common-filters a:visible:first').attr('href');
            }
            if (href) {
                window.location.hash = href;
            } else {
                $.wa.helpdesk_controller.requestsAllAction();
            }
        }, 0);
    },

    /** Single request log page by request id */
    requestAction: function(p) {
        var id = (p && p[0]) || '';
        if (id === 'new') {
            this.loadHTML('?module=requests&action=add&form_id=backend');
        } else {
            this.loadHTML('?module=requests&action=info&id='+id);
        }
    },

    /** Single request log page by request log id */
    requestLogAction: function(p) {
        var id = (p && p[0]) || '';
        this.loadHTML('?module=requests&action=info&log_id='+id);
    },

    /** Create request page */
    requestAddAction: function(p) {
        var id = (p && p[0]) || '';
        this.loadHTML('?module=requests&action=add&form_id='+id);
    },

    requestsAction: function(p) {
        if (!p[0]) {
            this.requestsAllAction();
        } else {
            this.requestsSearchAction(['nosearch', p[0]]);
        }
    },

    /** All requests with no filtering. */
    requestsAllAction: function(hash) {
        this.loadGrid({
            hash: hash,
            prehash: '#/requests/all/',
            header: $_('All requests'),
            afterLoad: function(r) {
                $.wa.helpdesk_controller.initMenuAboveList(r);
                if (r.admin) {
                    $('.search-result-header-menu.template').children('div:first').clone()
                    .find('.h-search-result-header-menu').remove().end()
                    .find('.h-filter-settings-toggle').show()
                        .on('click', function() {
                                $.wa.helpdesk_controller.filterSettings(r);
                            })
                        .end()
                    .prependTo($('#c-core-content .h-header-block').addClass('h-h1-inline'));
                }
            }
        });
    },

    requestsFilterAction: function(id) {
        return this.requestsSearchAction(id);
    },

    requestsAdd_filterAction: function() {
        delete $.wa.helpdesk_controller.filters_hash;
        this.requestsSearchAction([], 'add_filter');
    },

    /** Advanced search */
    requestsSearchAction: function(hash, env) {

        hash = hash || [];
        var prehash = ['requests', 'search'];

        var is_search = true;
        while(true) {
            if (hash[0] == 'nosearch') {
                is_search = false;
                prehash.push(hash[0]);
                hash = hash.slice(1);
            }  else {
                break;
            }
        }

        var string = hash[0];
        hash = hash.slice(1); // rest of hash goes to grid as paging parameters
        prehash.push(string);

        // Remove header from browser URL, if needed
        prehash = '#/'+prehash.join('/')+'/';

        if (!string) {
            this.loadHTML('?module=requests&action=search', {
                filters_hash: $.wa.helpdesk_controller.filters_hash
            }, null, function() {
                if (env === 'add_filter') {
                    $('#h-adv-search-fields .h-add-filter-text').show();
                }
                if ($.wa.helpdesk_controller.filters_hash) {
                    $('#h-adv-search-fields .h-clear').show().click(function() {
                        delete $.wa.helpdesk_controller.filters_hash;
                        $.wa.helpdesk_controller.redispatch();
                    });
                }
            });
        } else {
            this.loadGrid({
                hash: hash,
                prehash: prehash,
                filters: string,
                afterLoad: function(r) {
                    $.wa.helpdesk_controller.initMenuAboveList(r, is_search);
                    if (r.filters_hash === 'unread') {
                        var link = $('.search-result-header-menu.template').find('.h-filter-settings-toggle').clone().show()
                            .css({
                                marginTop: 7
                            })
                            .click(function() {
                                $(this).hide();
                                $.wa.helpdesk_controller.unreadSettings(r);
                            });
                        link.removeClass('h-filter-settings-toggle').addClass('h-unread-settings-toggle');
                        link.prependTo($('#c-core-content .h-header-block').addClass('h-h1-inline'));
                    }
                    if (r.filters_hash === 'follow') {
                        $('<a href="javascript:void(0)" class="float-right">' + $_('What is this?') + '</a>')
                            .css({
                                marginTop: 7
                            })
                            .click(function() {
                                var d = $('<div><h1>' + $_('Follow mark') +
                                        ' <i class="icon16 binocular" style="margin-top:8px;"></i></h1>' +
                                            '<p>' + $_('After you set "Follow" mark, next time someone performs an action with this request it will appear as "Unread" for you and you will receive email notification about this action. So you will be able to follow all further activity related to this request.') +
                                                '</p></div>')
                                    .waDialog({
                                        width: 500,
                                        height: 220,
                                        'min-height': 220,
                                        buttons: $('<div><input type="submit" class="button gray" value="' + $_('OK') + '"></div>'),
                                        onSubmit: function() {
                                            d.trigger('close');
                                            return false;
                                        }
                                    });
                            })
                            .prependTo($('#c-core-content .h-header-block').addClass('h-h1-inline'));
                    }
                    if (is_search) {
                        var menu = $('.search-result-header-menu.template')
                            .children('div:first').clone()
                            .on('click', '.h-save-as-filter', function() {
                                $.wa.helpdesk_controller.filterSettings(r);
                            })
                            .on('click', '.h-change-search-conditions', function() {
                                $.wa.helpdesk_controller.filters_hash = r.filters_hash;
                                $.wa.setHash('#/requests/search/');
                            });
                        if (r.f.id) {
                            menu.find('.h-filter-settings-toggle')
                                .show().on('click', function() {
                                    $(this).hide();
                                    $.wa.helpdesk_controller.filterSettings(r);
                                });
                            menu.find('.h-search-result-header-menu').remove();
                            if (!r.admin && r.shared == '1') {
                                menu.find('.h-filter-settings-toggle').remove();
                            }
                        } else {
                            menu.find('.h-search-result-header-menu').show();
                            menu.find('.h-filter-settings-toggle').remove();
                        }
                        menu.prependTo($('#c-core-content .h-header-block').addClass('h-h1-inline'));
                    }
                },
                is_search: is_search
            });
        }
    },

    designAction: function(params) {
        if (params) {
            if ($('#wa-design-container').length) {
                waDesignLoad();
            } else {
                this.loadHTML('?module=design', {}, null, function() {
                    waDesignLoad(params.join('&'));
                });
            }
        } else {
            this.loadHTML('?module=design', {}, null, function() {
                waDesignLoad('');
            });
        }

    },

    pagesAction: function (id) {
        if ($('#wa-page-container').length) {
            waLoadPage(id);
        } else {
            this.loadHTML('?module=pages');
        }
    },

    designThemesAction: function(params) {
        if ($('#wa-design-container').length) {
            waDesignLoad();
        } else {
            this.loadHTML('?module=design', {}, null, function() {
                waDesignLoad();
            });
        }
    },

    /** Sources editor */
    sourcesEditAction: function(p) {
        var id = (p && p[0]) || '';
        var wf_id = (p && p[1]) || '';
        this.loadHTML('?module=sources&action=editor&workflow_id='+wf_id+'&id='+id);
    },

    /** Sources editor: new source */
    sourcesCreateAction: function(p) {
        var st = (p && p[0]) || '';
        var wf_id = (p && p[1]) || '';
        this.loadHTML('?module=sources&action=editor&workflow_id='+wf_id+'&st='+st);
    },

    fconstructorAction: function() {
        this.loadHTML('?module=constructor');
    },

    faqAction: function(p) {
        p = p || {};
        this.loadFaqLayout(function() {
            if (!p[0]) {
                var li = $('#h-faq-categories li[data-category-id]:first');
                if (li.length) {
                    // for prevent one yet confirm (see calling of confirmLeave in templates)
                    $('.h-category-settings :submit').removeClass('yellow').addClass('green');
                    var category_id = li.data('categoryId');
                    if (category_id) {
                        $.wa.setHash('#/faq/category/' + category_id);
                        $('#h-faq-categories').find('.h-faq-categories-message').hide();
                    } else {
                        $('#h-faq-categories').find('.h-faq-categories-message').show();
                    }
                } else {
                    $('.h-faq-content-no-categories-template').find('.loading').remove();
                    $('#h-faq-content').html($('.h-faq-content-no-categories-template').html());
                }
            } else {
                var params = {
                    id: p[0],
                    category_id: null
                };
                if (p[0] === 'new') {
                    var li = $('#h-faq-categories li[data-category-id].selected');
                    if (li.length) {
                        params.category_id = li.data('categoryId');
                    }
                }
                this.loadHTMLInto('#h-faq-content', '?module=faq', params);
            }
        });
    },

    loadFaqLayout: function(after) {
        if (!$('#h-faq-content').length) {
            this.loadHTML('?module=faqLayout', {}, null, after);
        } else {
            if (typeof after === 'function') {
                after.call(this);
            }
        }
    },

    faqCategoryAction: function(p) {
        if (p[0]) {
            this.loadFaqLayout(function() {
                if (p[0] === 'none') {
                    p[0] = 0;
                }
                this.loadHTMLInto('#h-faq-content', '?module=faqCategory&id=' + p[0], {}, null, function() {
                    $.wa.helpdesk_controller.faq_context = {
                        type: 'category',
                        query: p[0]
                    };
                });
            });
        } else {
            this.faqAction();
        }
    },

    faqSearchAction: function(p) {
        this.loadFaqLayout(function() {
            this.loadHTMLInto('#h-faq-content', '?module=faqSearch', { query: p[0] || '' }, null, function() {
                $.wa.helpdesk_controller.faq_context = {
                    type: 'search',
                    query: p[0]
                };
                $('i.icon16.loading').remove();
           });
        });
    },

    /** Workflow graph */
    settingsWorkflowAction: function(p) {
        if (p[0] === 'add') {
            this.workflowEdit();
        } else {
            var id = (p && p[0]) || '';
            this.loadHTML('?module=settings&action=workflow&id='+id);
        }
    },

    workflowEdit: function(id) {
        var showDialog = function (d) {
            d.waDialog({
                onLoad: function () {
                },
                disableButtonsOnSubmit: true,
                onSubmit: function () {
                    $.post($(this).attr('action'), $(this).serializeArray(), function(r) {
                        if (r.status === 'ok') {
                            var wfid = r.data.workflow.id;
                            $.wa.setHash('#/settings/workflow/' + wfid);
                            $.wa.helpdesk_controller.reloadSidebar();
                        }
                        d.trigger('close');
                    }, 'json');
                    return false;
                }
            });
        };

        var d = $('#h-edit-workflow-dialog'), p;
        if (!d.length) {
            p = $('<div></div>').appendTo('body');
        } else {
            p = d.parent();
        }
        p.load('?module=settings&action=workflowEdit', { id: id || 0 },
            function () {
                showDialog($('#h-edit-workflow-dialog'));
            });
    },

    /** Workflow settings (graph) */
    settingsSidebarAction: function(p) {
        this.loadHTML('?module=settings&action=sidebar');
    },

    /** Cron settings page */
    settingsCronAction: function(p) {
        this.loadHTML('?module=settings&action=cron');
    },

    /** Customer portal settings page */
    settingsPortalAction: function(p) {
        this.loadHTML('?module=settings&action=portal');
    },

    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    // *   Other UI-related stuff: dialogs, form submissions, etc.
    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *

    /** Simple search submit. Compiles filters and redirects to requestsFilterAction. */
    simpleSearch: function() {
        var input = $('#search-text');
        if (!input.val()) {
            return;
        }

        var s = input.val();//.replace(/\\/g, '\\\\').replace(/%/g, '\\%').replace(/_/g, '\\_').replace(/&/g, '\\&').replace(/\+/g, '%2B').replace(/\//g, '%2F');
        var filters;
        if (s.indexOf('@') >= 0) {
            filters = [
                {name: 'c_email', param: s}
            ];
        } else {
            filters = [
                {name: 'c_name_id', param: s}
            ];
        }
        var grid = this.currentGrid || new $.wa.Grid({
            paginator_type: this.options.paginator_type
        });
        $.wa.setHash('#/requests/search/' + grid.compileFilters(filters)+'/');
    },

    /** Collapse sections in sidebar according to status previously set in $.storage */
    restoreCollapsibleStatusInSidebar: function() {
        // collapsibles
        $("#wa-app .collapse-handler").each(function(i,el) {
            $.wa.helpdesk_controller.collapseSidebarSection(el, 'restore');
        });
        // 'more view options'
        $.wa.helpdesk_controller.sidebarMoreViewOptions('workflows', 'restore');
        $.wa.helpdesk_controller.sidebarMoreViewOptions('states', 'restore');
        $.wa.helpdesk_controller.sidebarMoreViewOptions('sources', 'restore');
        $.wa.helpdesk_controller.sidebarMoreViewOptions('assignments', 'restore');
    },

    sidebarMoreViewOptions: function(type, action) {
        switch (action) {
            case 'toggle':
                if ($('#hd-by-'+type).is(':visible')) {
                    $.wa.helpdesk_controller.sidebarMoreViewOptions(type, 'hide');
                } else {
                    $.wa.helpdesk_controller.sidebarMoreViewOptions(type, 'show');
                }
                break;
            case 'restore':
                var status = $.storage.get('helpdesk/mvo/'+type);
                if (status != 'restore') {
                    $.wa.helpdesk_controller.sidebarMoreViewOptions(type, status);
                }
                break;
            case 'show':
                $('#hd-by-'+type).show();
                var ul = $('#hd-more-view-options-'+type).parent().hide().parent();
                if (ul.children(':visible').size() <= 0) {
                    ul.parents('.sidebar .block').hide();
                }
                $.storage.set('helpdesk/mvo/'+type, 'show');
                break;
            default:
                $('#hd-by-'+type).hide();
                $('#hd-more-view-options-'+type).parent().show().parents('.sidebar .block').show();
                $.storage.set('helpdesk/mvo/'+type, 'hide');
                break;
        }
    },

    /** Collapse/uncollapse section in sidebar. */
    collapseSidebarSection: function(el, action) {
        if (!action) {
            action = 'collapse';
        }
        el = $(el);
        if(el.size() <= 0) {
            return;
        }

        var arr = el.find('.darr, .rarr');
        if (arr.size() <= 0) {
            arr = $('<i class="icon16 darr">');
            el.prepend(arr);
        }
        var newStatus;
        var id = el.attr('id') || el.data('collapsibleId');
        var oldStatus = arr.hasClass('darr') ? 'shown' : 'hidden';

        var hide = function() {
            var contents = el.nextAll('.collapsible, .collapsible1').first();
            if (action == 'restore') {
                contents.hide();
            } else {
                contents.slideUp(200);
            }
            arr.removeClass('darr').addClass('rarr');
            newStatus = 'hidden';
        };
        var show = function() {
            var contents = el.nextAll('.collapsible, .collapsible1').first();
            if (action == 'restore') {
                contents.show();
            } else {
                contents.slideDown(200);
            }
            arr.removeClass('rarr').addClass('darr');
            newStatus = 'shown';
        };

        switch(action) {
            case 'toggle':
                if (oldStatus == 'shown') {
                    hide();
                } else {
                    show();
                }
                break;
            case 'restore':
                if (id) {
                    var status = $.storage.get('helpdesk/collapsible/'+id);
                    if (status == 'hidden') {
                        hide();
                    } else {
                        show();
                    }
                }
                break;
            case 'uncollapse':
                show();
                break;
            //case 'collapse':
            default:
                hide();
                break;
        }

        // save status in persistent storage
        if (id && newStatus) {
            $.storage.set('helpdesk/collapsible/'+id, newStatus);
        }
    },

    unreadSettings: function(data) {
        if (!this.currentGrid) {
            return false;
        }
        var block = $('.h-header-block');
        var status = 'closed';
        var closeSettings = function() {
            $('.h-unread-settings-toggle', block).show();
            $('.h-unread-settings', block).hide();
            status = 'closed';
        };
        var openSettings = function() {
            $('.h-unread-settings-toggle', block).hide();
            $('.h-unread-settings', block).show();
            status = 'opened';
        };
        var settings_block = $('.h-unread-settings', block);
        if (settings_block.length && settings_block.data('id') !== data.id) {
            settings_block.remove();
        }
        if (!settings_block.length) {
            block.append(tmpl('h-template-unread-settings', data));
            $('.buttons', block)
                .find('.cancel').click(function() {
                    closeSettings();
                });
            $('.h-unread-settings-toggle').click(function() {
                if (status === 'opened') {
                    closeSettings();
                } else {
                    openSettings();
                }
            });
            $('.h-unread-settings').submit(function() {
                $.post("?module=settings&action=unreadSave", $(this).serialize(), function() {
                    closeSettings();
                    $.wa.helpdesk_controller.reloadSidebar();
                    $.wa.helpdesk_controller.redispatch();
                });
                return false;
            });
            $('.h-unread-settings').find('input[type=checkbox]').change(function() {
                var el = $(this);
                var name = el.attr('name').replace('settings[', '').replace(']', '');
                var children = $('.h-unread-settings').find('input[type=checkbox][data-parent="' + name + '"]');
                if (children.length) {
                    if (this.checked) {
                        children.attr('disabled', false);
                    } else {
                        children.attr('disabled', true).attr('checked', false);
                    }
                }
            });
            $('.h-unread-settings').find('.h-clear-all').click(function() {
                $.post('?module=requestsRead', {
                    hash: '@all'
                }, function() {
                    $.wa.helpdesk_controller.redispatch();
                });
                return false;
            });
        }
        openSettings();

        return false;
    },

    /** Open panel for saving current search as filter */
    filterSettings: function(data) {
        if (!this.currentGrid) {
            return false;
        }

        var block = $('.h-header-block');
        var status = 'closed';
        var closeSettings = function() {
            $('.h-search-result-header-menu', block).show();
            $('.h-filter-settings-toggle', block).show();
            block.find('h1').show();
            $('.h-filter-settings', block).hide();
            status = 'closed';
        };
        var openSettings = function() {
            $('.h-search-result-header-menu', block).hide();
            if (data.f.hash !== '@all') {
                block.find('h1').hide();
                $('.h-filter-settings-toggle', block).hide();
            }
            $('.h-filter-settings', block).show();
            status = 'opened';
        };

        var settings_block = $('.h-filter-settings', block);
        if (settings_block.length && settings_block.data('id') !== data.id) {
            settings_block.remove();
        }
        if (!settings_block.length) {
            block.append(tmpl(data.f.hash === '@all' ? 'h-template-all-records-filter-settings' : 'h-template-filter-settings', data));
            $('.buttons', block)
                .find('.cancel').click(function() {
                    closeSettings();
                });
            $('.h-filter-settings-icons', block).on('click', 'a', function() {
                $('.h-filter-settings-icons', block).find('.selected').removeClass('selected');
                $(this).closest('li').addClass('selected');
                return false;
            });
            $('.h-filter-settings').submit(function() {
                var params = $(this).serializeArray();
                params.push({
                    name: 'icon',
                    value: $('.h-filter-settings-icons', block).find('.selected').closest('li').data('icon')
                });
                $.post("?module=filters&action=save", params, function(r) {
                    closeSettings();
                    $.wa.helpdesk_controller.reloadSidebar();
                    if (data.f.id != r.data) {
                        $.wa.setHash('#/requests/filter/' + r.data);
                    } else {
                        $.wa.helpdesk_controller.redispatch();
                    }
                }, 'json');
                return false;
            });
            $('.h-filter-delete').click(function() {
                if (confirm($_('Are you sure?'))) {
                    $(this).replaceWith('<i class="icon16 loading float-right"></i>');
                    if (data.f.hash === '@all') {
                        $.wa.helpdesk_controller.deleteFilter('@all');
                        closeSettings();
                    } else {
                        $.wa.helpdesk_controller.deleteFilter();
                    }
                }
                return false;
            });
            $('.h-filter-settings-toggle').click(function() {
                if (status === 'opened') {
                    closeSettings();
                } else {
                    openSettings();
                }
            });
        }

        openSettings();

        return false;
    },

    /** Delete current filter view */
    deleteFilter: function(h) {
        var id = h;
        if (!h) {
            // get filter id from hash
            var hash = this.getHash().replace(/^[^#]*#\/*/, '').split('/');
            if (hash[0] != 'requests' || hash[1] != 'filter' || ''+parseInt(hash[2], 10) !== hash[2]) {
                return;
            }
            var id = hash[2];
        }

        //this.showLoading();
        $.post('?module=filters&action=delete', { id: id }, function() {
            $.wa.setHash('#/');
            $.wa.helpdesk_controller.reloadSidebar();
        });
    },

    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
    // *   Helper functions
    // * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *

    //
    // Helpers related to list view (wa.grid)
    //

    /**
      * Load data for $.wa.Grid, format accordingly and put it into specified element.
      * options is an object with keys as follows:
      * - hash, filters, url: optional parameters to pass to $.wa.Grid, default is `undefined`.
      * - header: string to show inside <h1> tag in page header.
      * - is_search: boolean true to save in search history,
      * - paginator_type: string type of paginator (page, range). Default from $.wa.helpdesk_controller.options
      * - targetElement: element to insert generated content into. Defaults to $('#c-core-content').
      * - beforeLoad: callback to call when this.currentGrid is ready but no DOM manipulations had been made yet.
      * - afterLoad: callback to call when generated DOM is ready.
      */
    loadGrid: function(options) {
        options = options || {};
        options.targetElement = options.targetElement || $('#c-core-content');

        this.showLoading();

        var r = Math.random();
        this.random = r;
        this.hashBase = options.prehash || '#/';

        this.currentGrid = new $.wa.Grid({
            hash: options.hash,
            filters: options.filters,
            url: options.url,
            is_search: options.is_search,
            header: options.header,
            paginator_type: options.paginator_type || this.options.paginator_type
        });

        this.currentGrid.load(function(result) {
            if (this.random != r) {
                // too late: user clicked something else.
                return;
            }

            if (!options.header && result.header) {
                options.header = result.header;
            }

            this.lastView = $.extend(this.lastView || {}, {
                title: options.header,
                hash: window.location.hash.toString()
            });

            if (typeof options.beforeLoad == 'function') {
                options.beforeLoad.call(this, result);
            }

            var wrapper = $('<div class="topmost-grid-wrapper"></div>');
            options.targetElement.empty().append(wrapper);
            $('body > .dialog:hidden:not(.persistent)').not(options.targetElement.parents('.dialog')).remove();

            // header
            var header_block = $('<div class="block h-header-block"></div>');
            wrapper.append(header_block);

            // Title
            header_block.append($('<h1></h1>').text(options.header));
            $.wa.helpdesk_controller.setBrowserTitle(options.header);

            // table generated by $.wa.Grid
            wrapper.append($('<div class="support-content"></div>').append(this.currentGrid.getTable()));
            $('html,body').animate({scrollTop:0}, 200);

            // update count in sidebar for current view
            $.wa.helpdesk_controller.highlightSidebar();

            if (typeof options.afterLoad == 'function') {
                options.afterLoad.call(this, result);
            }
        });
    },

    /** Helper to set browser window title. */
    setBrowserTitle: function(title) {
        document.title = title + ' — ' + this.options.accountName;
    },

    /** Update link visibility and number of unread messages in sidebar */
    updateUnreadCount: function(new_count) {
        var el = $('#sb-unread-count').html(new_count).toggleClass('bold highlighted', new_count > 0);
        if (new_count > 0) {
            el.closest('li').show(200);
        }
    },

    updateFollowCount: function(new_count) {
        var el = $('#sb-follow-count').html(new_count).toggleClass('bold highlighted', new_count > 0);
        if (new_count > 0) {
            el.closest('li').show(200);
        }
    },

    /** Trigger 'helpdesk_list_result' on $(document) when data comes from list view. */
    listResultEvent: function(r) {
        // Hook for plugins
        var e = new $.Event('helpdesk_list_result');
        e.list_result = r;
        $(document).trigger(e);

        // Unread count in sidebar
        if (r.unread_count !== undefined) {
            $.wa.helpdesk_controller.updateUnreadCount(r.unread_count);
        }

        // errors getting data from source
        $.wa.helpdesk_controller.updateWorkflowErrors(r.workflows_errors, r.sources_errors);

        // history
        if (r.history) {
            $.wa.helpdesk_history.updateHistory(r.history);
        }

        // Ids for next/prev request traversal
        if (r.request_ids && !r.is_update) {
            $.wa.helpdesk_controller.lastView = $.extend($.wa.helpdesk_controller.lastView || {}, {
                ids: r.request_ids,
                offset: r.ids_offset,
                count: r.count
            });
        }
    },

    /** Helper to show dropdown menus above request lists */
    initMenuAboveList: function(r, is_search) {
        is_search = is_search && !r.filters;
        $('#c-core-content .header-above-requests-table').prepend(tmpl('h-template-requests-menu', r));
        var ul = $('#c-core-content .header-above-requests-table .menu-above-list');

        var topmost_grid_wrapper = getRequestsTable().closest('.topmost-grid-wrapper');
        topmost_grid_wrapper.on('change', 'td :checkbox, th :checkbox, .header-above-requests-table', function() {
            setTimeout(updateDisabled, 0);
        });
        updateDisabled();

        // Mark read/unread links
        ul.on('click', '.mass-mark-as-read,.mass-mark-as-unread,.mass-unmark-as-follow,.mass-mark-as-follow', function() {
            var a = $(this);
            if (a.parents('.disabled').length) {
                return false;
            }
            $.wa.helpdesk_controller.showLoading();

            var type = '';
            var status = '';
            if (a.hasClass('mass-mark-as-unread')) {
                type = 'unread';
                status = 1;
            } else if (a.hasClass('mass-mark-as-read')) {
                type = 'unread';
                status = '';
            } else if (a.hasClass('mass-mark-as-follow')) {
                type = 'follow';
                status = 1;
            } else {        // mass-unmark-as-follow
                type = 'follow';
                status = '';
            }

            $.post(type === 'unread' ? '?module=requests&action=unread' : '?module=requests&action=follow', {
                status: status,
                ids: topmost_grid_wrapper.find('td :checkbox:checked').map(function() {
                    return $(this).closest('tr').attr('rel');
                }).get()
            }, function(r) {
                var grid = $.wa.helpdesk_controller.currentGrid;

                if (type === 'unread') {
                    $.wa.helpdesk_controller.updateUnreadCount(r.data.unread_count);
                } else {
                    $.wa.helpdesk_controller.updateFollowCount(r.data.follow_count);
                }

                if (grid && grid.isActive()) {
                    grid.reload(function() {
                        if ($.wa.helpdesk_controller.currentGrid && $.wa.helpdesk_controller.currentGrid === grid) {
                            $.wa.helpdesk_controller.hideLoading();
                            var block = ul.closest('.block');
                            block.find('.notice-above-requests-list').remove();
                            block.append(
                                $.wa.helpdesk_controller.createClosableNotice('<i class="icon16 yes"></i>'+r.data.message)
                            );
                        }
                    });
                }
            }, 'json');
            return false;
        });

        // Bulk operations with multiple requests
        ul.on('click', '.mass-change-status,.mass-change-assignment,.mass-delete-requests', function() {
            var a = $(this);
            if (a.parents('.disabled').length) {
                return false;
            }
            var action_type = false;
            if (a.hasClass('mass-change-status')) {
                action_type = 'state';
            } else if (a.hasClass('mass-change-assignment')) {
                action_type = 'assignment';
            } else if (a.hasClass('mass-delete-requests')) {
                action_type = 'delete';
            }

            if (action_type) {
                $.wa.helpdesk_controller.showLoading();
                var hidden_wrapper = $('<div class="hidden"></div>').insertAfter(getRequestsTable());
                $.get('?action=bulk', { action_type: action_type }, function(r) {
                    $.wa.helpdesk_controller.hideLoading();
                    hidden_wrapper.html(r);
                });
            }
            return false;
        });

        // Toggles disabled/enabled state of menu items depending on number of requests selected
        function updateDisabled() {
            var count_selected = topmost_grid_wrapper.find('td :checkbox:checked').length;
            count_selected = count_selected || 0;
            ul.find('.selected-count').html(''+count_selected);
            if (count_selected > 0) {
                ul.find('.requests-operation').parent().removeClass('disabled');
            } else {
                ul.find('.requests-operation').parent().addClass('disabled');
            }
        }

        function getRequestsTable() {
            if ($.wa.helpdesk_controller.currentGrid && $.wa.helpdesk_controller.currentGrid.isActive()) {
                return $.wa.helpdesk_controller.currentGrid.getTable();
            }
            return $('<div></div>');
        }
    },

    /** Helper to show above the requests list results of operation */
    createClosableNotice: function(content) {
        var w = $('<div class="notice-above-requests-list"></div>');
        if (content) {
            if (typeof content == 'string') {
                w.html(content);
            } else {
                w.append(content);
            }
        }
        w.prepend('<i class="icon16 close float-right" style="cursor:pointer"></i>').on('click', 'i.close', function() {
            w.remove();
        });
        return w;
    },

    /** When there are errors checking email sources, show message above the layout, and (!) indicators in sidebar. */
    updateWorkflowErrors: function(wf_ids, sources) {
        $('#wa-app > .sidebar a[href^="#/settings/workflow/"] .error.indicator').remove();
        if (wf_ids) {
            $.each(wf_ids, function(i, v) {
                $('#wa-app > .sidebar a[href="#/settings/workflow/'+v+'"] b').append('<span class="error indicator inline">!</span>');
            });
        }

        if (sources) {
            var source_names = [];
            $.each(sources, function(source_id, source_name) {
                source_names.push(source_name);
            });

            var ha = $('#hd-announcement').empty();
            if (!source_names.length) {
                ha.remove();
                return;
            }

            if(!ha.size()) {
                ha = $('<div id="hd-announcement"></div>').prependTo('#wa-app');
            }

            ha.append($('<a href="javascript:void(0)" class="hd-announcement-close"><i class="icon10 close"></i></a>').click(function() {
                ha.remove();
                $.post("?action=closeError");
            }));

            ha.append($('<p>').text($_('Error delivering messages from:')+' '+source_names.join(', ')));
        }
    },

    //
    // Miscellaneous helpers
    //

    /**
     * Load HTML content from url and put it into main content block - #c-core-content.
     * Params are passed to url as get parameters.
     * beforeLoadCallback(html), if present, has a chance to modify response before it's shown in elem.
     * */
    loadHTML: function (url, params, beforeLoadCallback, afterLoadCallback) {
        this.loadHTMLInto("#c-core-content", url, params, beforeLoadCallback, afterLoadCallback);
    },

    /**
     * Load HTML content from url and put it into elem.
     * Params are passed to url as get parameters.
     * beforeLoadCallback(html), if present, has a chance to modify response before it's shown in elem.
     * */
    loadHTMLInto: function(elem, url, params, beforeLoadCallback, afterLoadCallback) {
        this.showLoading();

        var elem = elem || "#c-core-content";
        params = params || null;

        var r = Math.random();
        this.random = r;
        $.get(url, params, function (response) {
            if ($.wa.helpdesk_controller.random != r) {
                // too late: user clicked something else.
                return;
            }
            elem = $(elem);
            $('body > .dialog:hidden:not(.persistent)').not(elem.parents('.dialog')).remove();
            elem.trigger('helpdesk_before_load', [response]);
            if (beforeLoadCallback) {
                response = beforeLoadCallback.call($.wa.helpdesk_controller, response) || response;
            }
            $.wa.helpdesk_controller.hideLoading();
            elem.html(response);
            if ($(window).scrollTop()) {
                $('html,body').animate({
                    scrollTop:0
                }, 200);
            }
            var title = '';
            if (elem.find('h1:first .h-header-text').length) {
                title = elem.find('h1:first .h-header-text').text() || $('#wa-app .sidebar .selected:first').text() || _w('Helpdesk');
            } else {
                title = elem.find('h1:first').text() || $('#wa-app .sidebar .selected:first').text() || _w('Helpdesk');
            }
            title = title.trim();
            if (title) {
                $.wa.helpdesk_controller.setBrowserTitle(title);
            }
            if (afterLoadCallback) {
                afterLoadCallback.call($.wa.helpdesk_controller, elem);
            }
            elem.trigger('helpdesk_after_load', [response]);
        });
    },

    /** Show loading indicator in the header */
    showLoading: function() {
        var heading_loading = $('#c-core-content .h-header-loading');
        if (heading_loading.length) {
            if (!heading_loading.data('ignore')) {
                heading_loading.show();
            }
        } else {
            var h1 = $('h1:visible');
            if(h1.size() <= 0) {
                $('#c-core-content .block').first().prepend('<i class="icon16 loading"></i>');
                return;
            }
            h1 = $(h1[0]);
            if (h1.find('.loading').show().size() > 0) {
                return;
            }
            h1.append('<i class="icon16 loading"></i>');
        }
    },

    /** Hide all loading indicators in h1 headers */
    hideLoading: function() {
        $('h1 .loading').hide();
    },

    /** Gracefully reload sidebar. */
    reloadSidebar: function() {
        $.get("?module=backend&action=sidebar", null, function (response) {
            var sb = $("#wa-app > .sidebar");
            sb.css('height', sb.height()+'px').html(response).css('height', ''); // prevents blinking in some browsers
            $.wa.helpdesk_controller.highlightSidebar();
            $.wa.helpdesk_controller.restoreSidebarCounts();
            $.wa.helpdesk_controller.restoreCollapsibleStatusInSidebar();
        });
    },

    /** Add .selected css class to li with <a> whose href attribute matches current hash.
      * If no such <a> found, then the first partial match is highlighted.
      * Hashes are compared after this.cleanHash() applied to them. */
    highlightSidebar: function(hash) {
        var currentHash = this.cleanHash(hash || location.hash);
        var partialMatch = false;
        var partialMatchLength = 2;
        var match = false;

        $('#wa-app .sidebar li a').each(function(k, v) {
            v = $(v);
            if (!v.attr('href')) {
                return;
            }
            var h = $.wa.helpdesk_controller.cleanHash(v.attr('href'));

            // Perfect match?
            if (h == currentHash) {
                match = v;
                return false;
            }

            // Partial match? (e.g. for urls that differ in paging only)
            if (h.length > partialMatchLength && currentHash.substr(0, h.length) === h) {
                partialMatch = v;
                partialMatchLength = h.length;
            }
        });

        if (!match && partialMatch) {
            match = partialMatch;
        }

        if (!match) {
            // When no match found, try to highlight based on last view
            if (!hash && this.lastView && this.lastView.hash) {
                this.highlightSidebar(this.lastView.hash);
            }
            return;
        }

        // Matching <a> has been found. Remove old selection.
        $('#wa-app .sidebar .selected').removeClass('selected');

        // Only highlight items that are outside of dropdown menus
        if (match.closest('ul.dropdown').length > 0) {
            return;
        }

        // Highlight matching element
        var p = match.closest('li').addClass('selected');

        // Update grid count in localStorage and in sidebar
        var skey = 'helpdesk/count/'+$.wa.helpdesk_controller.options.user_id+'/'+p.children('a').attr('href');
        if (!$.wa.helpdesk_controller.currentGrid) {
            $.storage.del(skey);
            return;
        }
        if ($.wa.helpdesk_controller.currentGrid.isActive() && !p.hasClass('no-count')) {

            var count = $.wa.helpdesk_controller.currentGrid.getTotal();
            $.storage.set(skey, count);

            var cnt = p.children('span.count');
            if (cnt.size() <= 0) {
                p.prepend('<span class="count">'+count+'</span>');
            } else if (!cnt.hasClass('no-autoupdate')) {
                cnt.text(''+count);
            }
        }
    },

    /** Restore sidebar counts from local storage */
    restoreSidebarCounts: function() {
        $('#wa-app .sidebar li a').each(function(k, el) {
            el = $(el);
            var skey = 'helpdesk/count/'+$.wa.helpdesk_controller.options.user_id+'/'+el.attr('href');
            var n = $.storage.get(skey) || '';
            if (n !== null) {
                var cnt = el.parent().children('span.count');
                if (cnt.size() <= 0) {
                    el.parent().prepend('<span class="count">'+n+'</span>');
                } else if (!cnt.hasClass('no-autorestore')) {
                    cnt.text(n);
                }
            }
        });
    },

    /** Turn plain text links in given node into clickable <a> links. */
    makeLinksClickable: function (start_node) {
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
    },

    initClickableMenu: function(menu) {
        var menu_id = menu.attr('id') || ('' + Math.random()).slice(2);
        menu.find('a:first').unbind('click').bind('click', function() {
            $(this).closest('.clickable').find('ul').toggle();
            return false;
        });
        $(window).unbind('click.clickable-hide-' + menu_id).bind('click.clickable-hide-' + menu_id, function() {
            var p = menu.parent();
            if (p && p.length) {
                $('.clickable ul').hide();
            } else {
                $(this).unbind('.clickable-hide-' + menu_id);
            }
        });
    },

    /** Initialize a WYSIWYG editor on top of given textarea. */
    initWYSIWYG: function(textarea, options, csrf) {
        options = $.extend({
            focus: true,
            buttons: ['format', 'bold', 'italic', 'underline', 'deleted', 'lists', 'link', 'image', 'horizontalrule'],
            plugins: ['codeblock', 'faq'],
            minHeight: 200,
            source: false,
            uploadImage: true,
            linkify: false
        }, options || {});

        var $textarea = $(textarea);
        if (!$textarea.data('redactor')) {
            if (options.uploadImage) {
                options = $.extend(options, {
                    imageUpload: '?module=files&action=uploadimage&r=2',
                    imageUploadFields: {
                        '_csrf': csrf
                    }
                });
            }
            $textarea.redactor(options);
        }

        return $textarea;
    },

    initEditor: function(textarea, options) {
        var $textarea = $(textarea);
        $textarea.waEditor($.extend({}, options || {}, {
            buttons: ['format', 'bold', 'italic', 'underline', 'deleted', 'lists', 'link', 'image', 'horizontalrule'],
            plugins: ['codeblock'],
            imageUpload: '?module=files&action=uploadimage&r=2',
            imageUploadFields: {
                '_csrf': options._csrf || ''
            },
            //keydownCallback: function(event) { }, // without this waEditor intercents Ctrl+S event in Redactor
            callbacks: {
                change: function () {
                    $textarea.closest('form').find(':submit').removeClass('green').addClass('yellow');
                }
            }
        }));

        // Make sure sticky bottom buttons behave correctly when user switches between editors
        $textarea.closest('.h-editor').find('.html,.wysiwyg').click(function() {
            $(window).resize();
        });
        return $textarea;
    },

    renderActionSettings: function(html, options, serialize_data) {

        options = options || {};
        var url = options.url || '';
        var are_you_sure = options.are_you_sure || '';
        var action_id = options.action_id || '';
        var state_id = options.state_id || '';
        var workflow_id = options.workflow_id || '';
        var delete_this_action = options.delete_this_action || '';
        var save = options.save || '';
        var confirm = typeof options.confirm !== 'undefined' ? options.confirm : true;
        serialize_data = serialize_data || [];

        var buttons = $('<div class="block">');
        if (are_you_sure) {
            are_you_sure = buttons.html(are_you_sure).text();
            buttons.empty();
        }

        if (action_id) {
            buttons.append($('<a href="javascript:void(0)" style="float:right;display:inline-block;line-height:30px;color:red;margin-right:12px;">'+(delete_this_action||'')+'</a>').click(function() {
                if (are_you_sure && typeof confirm === 'function' && !confirm(are_you_sure)) {
                    return false;
                }
                var self = $(this);
                self.parent().append('<i class="icon16 loading"></i>').find(':submit').attr('disabled', true);
                $.post('?module=editor&action=delaction&workflow_id='+workflow_id+'&action_id='+action_id+'&state_id='+state_id, {}, function() {
                    $.wa.helpdesk_controller.redispatch();
                });
            }));
        }

        buttons.append($('<input type="submit" class="button green" id="h-save-action" value="'+save+'">'));
        var wrapper = $('#h-action-settings')
            .html('<form>' + html + '</form>');
        wrapper.prepend(
                '<div class="block">' +
                    '<a class="cancel no-underline" href="javascript:void(0);"><i class="icon10 larr"></i> ' +
                            $_('Back to workflow customizing page') +
                    '</a> <i class="icon16 loading h-header-loading" style="display:none;"></i>' +
                '</div>'
            );


        $('.cancel', wrapper).click(function() {
            $.wa.helpdesk_controller.redispatch();
        });
        buttons.find(':submit').parent().append('<i class="icon16 loading" style="display:none;"></i>');

        var button = buttons.find(':submit');
        var form = $('form', wrapper);
        $.each(serialize_data, function(i, item) {
            var name = item.name;
            var value = item.value;
            var el = form.find(':input[name="' + name +'"]');
            if (el.length) {
                if (el.is(':checkbox')) {
                    el.attr('checked', true);
                } else if (el.is(':radio')) {
                    el = el.filter('[value="' + value + '"]');
                    el.attr('checked', true);
                } else if (el.is('select')) {
                    el.find('option[value="' + value + '"]').attr('selected', true);
                } else if (el.is('input')) {
                    el.val(value);
                }
                el.trigger('change');
            }
        });

        form.append(buttons).submit(function(html) {
            var form = $(this);
            var serialize_data = form.serializeArray();
            button.siblings('.loading').show();
            form.find('input, select, textarea').trigger('beforesubmit');
            $.post(url, form.serialize(), function(html) {
                wrapper.removeClass('modified');
                button.removeClass('yellow').addClass('green');
                button.siblings('.loading').hide();
                button.parent().append(
                    $('<span><i class="icon16 yes after-button"></i> ' + $_('Saved') + '</span>').animate({ opacity: 0 }, 1000, function() {
                        $(this).remove();
                    })
                );
                $.wa.helpdesk_controller.renderActionSettings(
                        html,
                        $.extend({}, options, { confirm: false }),
                        serialize_data
                );
            });
            return false;
        });

        buttons.sticky({
            fixed_css: { bottom: 0, 'z-index': 101, background: 'white' },
            fixed_class: 'sticky-bottom-shadow',
            showFixed: function(e) {
                e.element.css('min-height', e.element.height());
                e.fixed_clone.empty().append(e.element.children());
            },
            hideFixed: function(e) {
                e.fixed_clone.children().appendTo(e.element);
            },
            updateFixed: function(e, o) {
                this.width(e.element.width());
            }
        });

        setTimeout(function() {
            wrapper.on('change', 'input,textarea,select', function() {
                if (button.hasClass('green')) {
                    wrapper.addClass('modified');
                    button.removeClass('green').addClass('yellow');
                }
            });
            wrapper.on('keyup', 'input:text,textarea', function() {
                if (button.hasClass('green')) {
                    wrapper.addClass('modified');
                    button.removeClass('green').addClass('yellow');
                }
            });
        }, 0);

        if (confirm) {
            // Confirmation before user leaves the page
            $.wa.helpdesk_controller.confirmLeave(
                function() {
                    return $('#h-action-settings').hasClass('modified');
                },
                $_('Unsaved changes will be lost if you leave this page now.'),
                $_("Are you sure?"),
                function() {
                    return !$('#h-action-settings').length;
                },
                'h-action-settings'
            );
        }
    },

    showActionSettings: function(workflow_id, state_id, action_id, action_class, save_text, or, cancel, delete_this_action, are_you_sure, callback) {
        $('#c-core-content').children().wrapAll('<div id="h-content-above-action-settings"></div>');
        $('#c-core-content').append('<div id="h-action-settings"></div>');
        $('#h-content-above-action-settings').hide();

        var url = '?module=editor&action=action&wid='+workflow_id+'&action_id='+action_id+'&state_id='+state_id+'&action_class='+action_class;
        $.get(url, function(html) {
            $.wa.helpdesk_controller.renderActionSettings(html, {
                url: url,
                are_you_sure: are_you_sure,
                action_id: action_id,
                save: save_text,
                state_id: state_id,
                workflow_id: workflow_id,
                delete_this_action: delete_this_action
            });
        });
    },

    /** Helper to initialize iButtons */
    iButton: function($checkboxes, options) {
        options = options || {};
        return $checkboxes.each(function() {
            var cb = $(this);
            var id = cb.attr('id');
            if (!id) {
                do {
                    id = 'cb'+Date.now()+'-'+(((1+Math.random())*0x10000)|0).toString(16).substring(1);
                } while (document.getElementById(id));
                cb.attr('id', id);
            }
            if (!options.inside_labels) {
                $($.parseHTML(
                    '<ul class="menu-h ibutton-wrapper">'+
                        '<li><label class="gray" for="'+id+'">'+(options.labelOff||'')+'</label></li>'+
                        '<li class="ibutton-li"></li>'+
                        '<li><label for="'+id+'">'+(options.labelOn||'')+'</label></li>'+
                    '</ul>'
                )).insertAfter(cb).find('.ibutton-li').append(cb);
            }
        }).iButton($.extend({
            className: 'mini'
        }, options, options.inside_labels ? {} : {
            labelOn : "",
            labelOff : ""
        }));
    },

    /** Helper to insert text into textarea */
    insertAtCursor: function(myField, myValue) {
        // IE support
        if (document.selection) {
            myField.focus();
            var sel = document.selection.createRange();
            sel.text = myValue;
        }
        // MOZILLA and others
        else if (myField.selectionStart || myField.selectionStart == '0') {
            var startPos = myField.selectionStart;
            var endPos = myField.selectionEnd;
            myField.value = myField.value.substring(0, startPos)
                + myValue
                + myField.value.substring(endPos, myField.value.length);
            myField.selectionStart = startPos + myValue.length;
            myField.selectionEnd = startPos + myValue.length;
        } else {
            myField.value += myValue;
        }
    },

    /** Create a checklist dropdown from ul.menu-h.dropdown (optionally .no-click-close) */
    animateChecklist: function(checklist) {
        // Element to show list of currently selected items
        var selected_items_span = checklist.find('.selected-items');

        // initial text, usually something like "Please select"
        var initial_text = selected_items_span.text();

        // Click on a closed checklist closes/opens the checklist dropdown
        selected_items_span.click(function() {
            var menu = checklist.find('.hidden.menu-v').toggle();
            if (!menu.hasClass('no-mouseleave') && menu.is(':visible')) {
                checklist.mouseleave(function() {
                    checklist.find('.hidden.menu-v').hide();
                });
            }
        });

        // Dropdown checkbox change changes the visible description in selected_items_span
        $('input:checkbox', checklist[0]).live('change', function() {
            var str = [];
            checklist.find('input:checkbox').each(function() {
                var cb = $(this);
                if (cb.is(':checked:not(:disabled)')) {
                    cb.parent().addClass('bold');
                    str.push($.trim($(this).parent().text()));
                } else {
                    cb.parent().removeClass('bold');
                }
            });
            if (str.length > 0) {
                selected_items_span.text(str.join(', '));
            } else {
                selected_items_span.text(initial_text);
            }
            return false;
        }).change();

        return checklist;
    },

    /** Shows a confirmation dialog when user tries to navigate away from current page or current hash. */
    confirmLeave: function(is_relevant, warning_message, confirm_question, stop_listen, ns) {
        var h, h2, $window = $(window);
        var event_id = ('' + Math.random()).slice(2);
        if (ns) {
            event_id = ns;
        }

        this.confirmLeaveStop(event_id);

        $window.on('beforeunload.' + event_id, h = function(e) {
            if (typeof stop_listen === 'function' && stop_listen()) {
                $window.off('.' + event_id);
                return;
            }
            if (is_relevant()) {
                return warning_message;
            }
        });

        $window.on('wa_before_dispatched.' + event_id, h2 = function(e) {
            if (typeof stop_listen === 'function' && stop_listen()) {
                $.wa.helpdesk_controller.confirmLeaveStop(event_id);
                return;
            }
            if (!is_relevant()) {
                $window.off('wa_before_dispatched', h2);
                return;
            }
            if (!confirm(warning_message + " " + confirm_question)) {
                e.preventDefault();
            }
        });

        return event_id;

    },

    confirmLeaveStop: function(confirm_id) {
        $(window).off('.' + confirm_id);
    },

    /** Current hash */
    getHash: function () {
        return this.cleanHash();
    },

    /** Make sure hash has a # in the begining and exactly one / at the end.
      * For empty hashes (including #, #/, #// etc.) return an empty string.
      * Otherwise, return the cleaned hash.
      * When hash is not specified, current hash is used. */
    cleanHash: function (hash) {
        if(typeof hash == 'undefined') {
            hash = window.location.hash.toString();
        }

        if (!hash.length) {
            hash = ''+hash;
        }
        while (hash.length > 0 && hash[hash.length-1] === '/') {
            hash = hash.substr(0, hash.length-1);
        }
        hash += '/';

        if (hash[0] != '#') {
            if (hash[0] != '/') {
                hash = '/' + hash;
            }
            hash = '#' + hash;
        } else if (hash[1] && hash[1] != '/') {
            hash = '#/' + hash.substr(1);
        }

        if(hash == '#/') {
            return '';
        }

        try {
            // Fixes behaviour of Safari and possibly other browsers
            hash = decodeURIComponent(hash);
        } catch (e) {
        }

        return hash;
    }


};

})(jQuery);
