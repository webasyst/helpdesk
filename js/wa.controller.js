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

    currentView: null,

    waLoading: null,

    // will be a function to destroy an existing title shadow watcher
    headerShadowedDestroy: null,

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
        this.waLoading = $.waLoading();

        // Set up AJAX to never use cache
        $.ajaxSetup({
            cache: false
        });

        // auto close menus onclick

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
            if (xhr.status === 502 && exception == 'abort' || (settings.url && settings.url.indexOf('background_process') >= 0) || (settings.data && typeof settings.data === 'string' && settings.data.indexOf('background_process') >= 0)) {
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

        if ($.wa.helpers.isMacintosh()) {
            document.documentElement.classList.add('is-mac');
        }

        // Auto update current grid view once a minute
        this.gridInterval = setInterval(function() {
            if ($.wa.helpdesk_controller.currentGrid) {
                $.wa.helpdesk_controller.currentGrid.update();
            }
        }, 60000);

        $.wa.helpdesk_controller.bindToggleCollapseMenuSidebar();

        this.restoreCollapsibleStatusInSidebar();
        $.wa.helpdesk_controller.restoreSidebarCounts();

        // do not show (!) signs in helpdesk app header in helpdesk itself
        $(document).bind('wa.appcount', function() {
            $('#wa-app-helpdesk .indicator').remove();
        });
        $('#wa-app-helpdesk .indicator').remove();

        // development hotkeys for redispatch and sidebar reloading
        $(document).keypress(function(e) {
            const key = (e.key === 'Enter' || e.key === '\n');
            if (!key) {
                return;
            }
            if (e.shiftKey) {
                $.wa.helpdesk_controller.showLoading();
                $.wa.helpdesk_controller.reloadSidebar();
            }
            if (e.ctrlKey) {
                $.wa.helpdesk_controller.redispatch();
            }
        });

        (function() {
            $.wa.helpdesk_controller.setHash = function(hash) {
                this.stopDispatch(0);
                $.wa.setHash.call($.wa, hash);
            };
        })();
    }, // end of init()

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
            if (old_hash) {
                this.currentHash = old_hash;
                window.location.hash = old_hash;
            }
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
            var href = $('.hd-main-filters a:visible:first').attr('href');
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
    requestsAllAction: function(hash, gridOptions, onAfterLoad) {
        gridOptions = $.isEmptyObject(gridOptions) ? {} : gridOptions;
        this.loadGrid({
            ...gridOptions,
            hash: hash,
            prehash: '#/requests/all/',
            header: $_('All requests'),
            afterLoad: function(r) {
                $.wa.helpdesk_controller.initMenuAboveList(r);
                if (r.admin) {
                    $($('#h-search-result-header-menu-template').html())
                    .find('.h-search-result-header-menu').remove().end()
                    .find('.h-filter-settings-toggle').on('click', function() {
                        $.wa.helpdesk_controller.filterSettings(r);
                    })
                    .insertAfter($('#c-core-content .h-header-block-top h1'));
                }

                if (typeof onAfterLoad === 'function') {
                    onAfterLoad.call(this, null);
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
    requestsSearchAction: function(hash, env, gridOptions, onAfterLoad) {
        gridOptions = $.isEmptyObject(gridOptions) ? {} : gridOptions;
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
                ...gridOptions,
                hash: hash,
                prehash: prehash,
                filters: string,
                afterLoad: function(r) {
                    $.wa.helpdesk_controller.initMenuAboveList(r, is_search);
                    if (r.filters_hash === 'unread') {
                        var link = $($('#h-search-result-header-menu-template').html())
                            .find('.h-search-result-header-menu').remove()
                            .end()
                            .on('click', function() {
                                $(this).hide();
                                $.wa.helpdesk_controller.unreadSettings(r);
                            });
                        link.removeClass('h-filter-settings-toggle').addClass('h-unread-settings-toggle');
                        link.insertAfter($('#c-core-content .h-header-block-top h1'));
                    }
                    if (r.filters_hash === 'follow') {
                        $(`<span class="back" id="tooltip-what-is-this" data-wa-tooltip-template="#tooltip-template-tooltip-what-is-this" data-title="${$_('What is this?')}">
                                <i class="fas fa-question-circle"></i>
                                </span>
                                <div class="wa-tooltip-template" id="tooltip-template-tooltip-what-is-this">
                                    <div class="box">
                                        <h3>${$_('“Follow” mark')} <i class="fas fa-binoculars text-gray" style="font-size:1rem"></i></h3>
                                        <p>${$_('After you set the “Follow” mark, the next time someone performs an action with this request it will appear as “Unread” for you and you will receive email notification about this action. So you will be able to follow all further activity related to this request.')}</p>
                                    </div>
                                </div>
                            <script>
                            (function($) {
                                $("#tooltip-what-is-this").waTooltip({trigger:'click'});
                            })(jQuery);
                        </script>`)
                        .insertAfter($('#c-core-content .h-header-block-top h1'));
                    }
                    if (is_search) {
                        var menu = $($('#h-search-result-header-menu-template').html())
                            .on('click', '.h-save-as-filter', function() {
                                $.wa.helpdesk_controller.filterSettings(r);
                            })
                            .on('click', '.h-change-search-conditions', function() {
                                $.wa.helpdesk_controller.filters_hash = r.filters_hash;
                                $.wa.setHash('#/requests/search/');
                            });
                        if (r.f.id) {
                            menu.find('.h-filter-settings-toggle')
                                .on('click', function() {
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
                        menu.insertAfter($('#c-core-content .h-header-block-top h1'));
                    }

                    if (typeof onAfterLoad === 'function') {
                        onAfterLoad.call(this, null);
                    }
                },
                is_search: is_search
            });
        }
    },

    designAction: function(params) {
        if (Array.isArray(params) && params.length) {
            if ($('#wa-design-container').length) {
                waDesignLoad()
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
            this.loadHTML('?module=pages', null, (html) => {
                return `<div class="flexbox">${html}</div>`;
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

    pluginsAction: function() {
        this.loadHTML('?module=plugins');
    },

    workflowEdit: function(id) {
        var showDialog = function ($wrapper) {
            $.waDialog({
                html: $('<div />').append($wrapper).html(),
                onOpen: function (_, d) {
                    d.$block.on('submit', function (e) {
                        e.preventDefault();
                        const loadingSubmit = $.wa.helpers.createLoadingSubmit($(this).find(':submit')).show();
                        $.post($(this).attr('action'), $(this).serializeArray(), function(r) {
                            loadingSubmit.hide();
                            if (r.status === 'ok') {
                                var wfid = r.data.workflow.id;
                                $.wa.setHash('#/settings/workflow/' + wfid);
                                $.wa.helpdesk_controller.reloadSidebar();
                                d.close();
                            }
                        }, 'json');
                    })
                },
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
        if(!el.size()) {
            return;
        }

        var arrow = el.find('.fa-caret-down, .fa-caret-right');
        if (!arrow.size()) {
            arrow = $('<i class="fas fa-caret-right">');
            el.find('.caret').append(arrow);
        }
        var newStatus;
        var id = el.attr('id') || el.data('collapsibleId');
        var oldStatus = arrow.hasClass('fa-caret-down') ? 'shown' : 'hidden';

        var hide = function() {
            var contents = el.nextAll('.collapsible, .collapsible1').first();
            if (action == 'restore') {
                contents.hide();
            } else {
                contents.slideUp(200);
            }
            arrow.removeClass('fa-caret-down').addClass('fa-caret-right');
            newStatus = 'hidden';
        };
        var show = function() {
            var contents = el.nextAll('.collapsible, .collapsible1').first();
            if (action == 'restore') {
                contents.show();
            } else {
                contents.slideDown(200);
            }
            arrow.removeClass('fa-caret-right').addClass('fa-caret-down');
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
        var closeSettings = function() {
            $('.h-unread-settings-toggle', block).show();
            $('.h-unread-settings', block).hide();
        };
        var openSettings = function() {
            $('.h-unread-settings-toggle', block).hide();
            $('.h-unread-settings', block).show();
        };
        var settings_block = $('.h-unread-settings', block);
        if (settings_block.length && settings_block.data('id') !== data.id) {
            settings_block.remove();
        }
        if (!settings_block.length) {
            openSettings();

            const dialog_html = tmpl('h-template-unread-settings-dialog', data);
            $.waDialog({
                html: dialog_html,
                onOpen: function (_, dialog) {
                    const $form = dialog.$block;
                    $.wa.helpers.watchChangeForm($form);
                    $form.find('input[type=checkbox]').change(function() {
                        var el = $(this);
                        var name = el.attr('name').replace('settings[', '').replace(']', '');
                        var children = $('.h-unread-settings').find('input[type=checkbox][data-parent="' + name + '"]');
                        if (children.length) {
                            if (this.checked) {
                                children.prop('disabled', false);
                            } else {
                                children.prop('disabled', true).prop('checked', false);
                            }
                        }
                    });
                    $form.on('submit', function() {
                        const submitWithLoading = $.wa.helpers.createLoadingSubmit($(this).find(':submit'));
                        submitWithLoading.show();
                        $.post("?module=settings&action=unreadSave", $(this).serialize(), function() {
                            closeSettings();
                            submitWithLoading.hide();
                            $.wa.helpdesk_controller.reloadSidebar();
                            $.wa.helpdesk_controller.redispatch();
                            dialog.close();
                        });
                        return false;
                    });
                    $form.find('.h-clear-all').on('click', function() {
                        const submitWithLoading = $.wa.helpers.createLoadingSubmit($(this));
                        submitWithLoading.show();
                        $.post('?module=requestsRead', {
                            hash: '@all'
                        }, function() {
                            submitWithLoading.hide();
                            $.wa.helpdesk_controller.redispatch();
                            dialog.close();
                        });
                        return false;
                    });
                },
                onClose: function () {
                    closeSettings();
                }
            })
        }

        return false;
    },

    /** Open panel for saving current search as filter */
    filterSettings: function(data) {
        if (!this.currentGrid) {
            return false;
        }

        var block = $('.h-header-block');
        var closeSettings = function() {
            $('.h-search-result-header-menu', block).show();
            block.find('h1').show();
            $('.h-filter-settings', block).hide();
        };
        var openSettings = function() {
            $('.h-search-result-header-menu', block).hide();
            $('.h-filter-settings', block).show();
        };

        var settings_block = $('.h-filter-settings', block);
        if (settings_block.length && settings_block.data('id') !== data.id) {
            settings_block.remove();
        }
        var filter_dialog = null;
        if (!settings_block.length) {
            if (data.f.hash === '@all') {
                block.append(tmpl('h-template-all-records-filter-settings', data));
            } else {
                const dialog_html = tmpl('h-template-filter-settings-dialog', data);
                filter_dialog = $.waDialog({
                    html: dialog_html,
                    onOpen: function (_, dialog) {
                        const $form = dialog.$block;
                        let formChanger = null;
                        if ($form.find('input[name="id"]').val()) {
                            // if exist filter
                            formChanger = $.wa.helpers.watchChangeForm($form);
                        }

                        const $filter_icons = $('.h-filter-settings-icons', $form);
                        $filter_icons.on('click', 'a', function() {
                            $filter_icons.find('.selected').removeClass('selected');
                            $(this).closest('li').addClass('selected');
                            if (formChanger) {
                                formChanger.change();
                            }
                            return false;
                        });
                        $form.submit(function() {
                            const submitWithLoading = $.wa.helpers.createLoadingSubmit($(this).find(':submit'));
                            submitWithLoading.show();
                            var params = $(this).serializeArray();
                            params.push({
                                name: 'icon',
                                value: $filter_icons.find('.selected').closest('li').data('icon')
                            });
                            $.post("?module=filters&action=save", params, function(r) {
                                submitWithLoading.hide();
                                $.wa.helpdesk_controller.reloadSidebar();
                                if (data.f.id != r.data) {
                                    $.wa.setHash('#/requests/filter/' + r.data);
                                } else {
                                    $.wa.helpdesk_controller.redispatch();
                                }

                                dialog.close();
                            }, 'json');
                            return false;
                        });
                    },
                    onClose: function () {
                        closeSettings();
                    }
                });
            }

            $('.buttons', block).find('.cancel').click(function() {
                closeSettings();
            });

            $('.h-filter-delete').click(function() {
                $.waDialog.confirm({
                    title: $_('Are you sure?'),
                    success_button_title: $_('Delete'),
                    success_button_class: 'danger',
                    cancel_button_title: $_('Cancel'),
                    cancel_button_class: 'light-gray',
                    onSuccess: function(d) {
                        const submit_loading = $.wa.helpers.createLoadingSubmit(d.$body.find('.js-success-action')).show();
                        const filter_id = data.f.hash === '@all' ? '@all' : null;
                        $.wa.helpdesk_controller.deleteFilter(filter_id)
                            .then(() => {
                                if (filter_id) {
                                    closeSettings();
                                }
                                submit_loading.hide();
                                filter_dialog && filter_dialog.hide();
                            });
                    }
                });
                return false;
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

        return $.post('?module=filters&action=delete', { id: id }, function() {
            $.wa.helpdesk_controller.reloadSidebar().then(() => {
                $.wa.helpdesk_controller.defaultAction();
            });
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
            if (typeof this.headerShadowedDestroy === 'function') {
                this.headerShadowedDestroy();
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

            this.currentView = $.wa.helpdesk_controller.currentGrid.getSettings().view;

            var wrapper = $(`<div class="h-requests topmost-grid-wrapper" data-view="${this.currentView}"></div>`);
            options.targetElement.empty().append(wrapper);
            $('body > .dialog:hidden:not(.persistent)').not(options.targetElement.parents('.dialog')).remove();

            // header
            var header_block = $(
                `<div id="hd-requests-header-top"></div>
                 <div id="hd-requests-header" class="h-header box break-word js-mobile-not-hide-sidebar">
                    <div class="h-header-block">
                        <div class="h-header-block-top h-h1-inline" />
                    </div>
                </div>`);
            wrapper.append(header_block);

            // Title
            header_block.find('.h-header-block-top').append($('<h1 class="text-ellipsis" />').text(options.header));
            $.wa.helpdesk_controller.setBrowserTitle(options.header);

            // table generated by $.wa.Grid
            wrapper.append($('<div id="hd-support-content" class="support-content"></div>').append(this.currentGrid.getTable()));
            $('html,body').animate({scrollTop:0}, 200);
            if (this.currentView === 'split') {
                $('#h-request-sidebar').prepend(header_block.detach());
            }

            // is shadowed header
            setTimeout(() => {
                const header_top = document.querySelector("#hd-requests-header-top");
                if (!header_top) {
                    return;
                }

                const sticky_observer = new IntersectionObserver(function(entries) {
                    $("#hd-requests-header").toggleClass("h-shadowed", entries[0].intersectionRatio === 0);
                }, { threshold: [0, 1] });

                sticky_observer.observe(document.querySelector("#hd-requests-header-top"));
                this.headerShadowedDestroy = () => sticky_observer.disconnect();
            });

            // update count in sidebar for current views
            $.wa.helpdesk_controller.highlightSidebar();

            if (typeof options.afterLoad == 'function') {
                options.afterLoad.call(this, result);
            }
            $.wa.helpdesk_controller.hideLoading();
        });
    },

    /** Helper to set browser window title. */
    setBrowserTitle: function(title) {
        document.title = title + ' — ' + this.options.accountName;
    },

    /** Update link visibility and number of unread messages in sidebar */
    updateUnreadCount: function(new_count) {
        this.updateMainCount('#sb-unread-count', new_count);
    },

    updateFollowCount: function(new_count) {
        this.updateMainCount('#sb-follow-count', new_count);
    },

    updateMainCount: function (selector, new_count) {
        const el = $(selector).html(new_count)
        const $li = el.closest('li,.brick');

        if (new_count > 0) {
            $li.show(200);
        } else {
            $li.hide();
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
        const $dropdowns_above_requests = $('#hd-requests-header .h-header-above-requests').prepend(
            tmpl('h-requests-menu-template', Object.assign(r, { view: this.currentView }))
        );
        const $bulk_actions = $dropdowns_above_requests.find('#h-requests-actions');

        if (this.currentView !== 'split') {
            $('#hd-requests-header .h-header-block-top').append($bulk_actions.detach());
        }
        $bulk_actions.removeClass('hidden');

        var $requests_actions = $('#c-core-content #h-requests-actions');
        const $bulk_toggler = $requests_actions.find('.js-bulk-toggle');
        const $topic_actions = $('.js-topic-actions');
        $bulk_toggler.on('click', function (e) {
            e.preventDefault();

            $topic_actions.add($('.js-topic-control')).toggleClass('hidden');
            const is_hidden  = $topic_actions.hasClass('hidden');
            const $table = $('table.requests-table');
            $table.toggleClass('is-bulk-select', !is_hidden);

            const selection_mode_event_name = 'click.helpdesk_selection_mode';
            const preventDefaultLinks = function (e) {
                if ($(e.target).is(':checkbox')) {
                    return true;
                }
                const $checkbox = $(this).find('.checkboxes-col :checkbox');
                const new_state = !$checkbox.prop('checked');
                $checkbox.prop('checked', new_state).trigger('change');
                return false;
            };
            $table.find('tr').off(selection_mode_event_name).on(selection_mode_event_name, preventDefaultLinks);

            if (is_hidden) {
                const $tr = $table.find('tr.selected');
                if ($tr.length) {
                    $tr.find('.checkboxes-col :checkbox').prop('checked', false).trigger('change');
                    $tr.removeClass('selected');
                }
                $table.find('tr').off(selection_mode_event_name);
            }
        });
        $(document).off('close_bulk.helpdesk').on('close_bulk.helpdesk', function () {
            if ($topic_actions.is(':visible')) {
                $bulk_toggler.last().trigger('click');
            }
        });

        var topmost_grid_wrapper = getRequestsTable().closest('.topmost-grid-wrapper');
        topmost_grid_wrapper.on('change', 'td :checkbox, th :checkbox, .h-header-above-requests', function() {
            setTimeout(updateDisabled, 0);
        });
        updateDisabled();

        // init dropdowns
        $("#h-requests-action-dropdown").waDropdown();
        $("#h-requests-sort-dropdown").waDropdown();

        // Mark read/unread links
        $requests_actions.on('click', '.mass-mark-as-read,.mass-mark-as-unread,.mass-unmark-as-follow,.mass-mark-as-follow', function() {
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
                            var $header = $('#hd-requests-header');
                            $header.find('.notice-above-requests-list').remove();
                            $header.append($.wa.helpdesk_controller.createClosableNotice(r.data.message, 'success'));
                        }
                    });
                }
                $(document).trigger('close_bulk.helpdesk');
            }, 'json');
            return false;
        });

        // Bulk operations with multiple requests
        $requests_actions.on('click', '.mass-change-status,.mass-change-assignment,.mass-delete-requests', function() {
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
                $('#js-mass-action-sandbox').remove();
                var hidden_wrapper = $('<script id="js-mass-action-sandbox" type="text/html"></script>').insertAfter(getRequestsTable());
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
            $requests_actions.find('.js-selected-count')
                .removeClass('blue gray')
                .addClass(count_selected > 0 ? 'blue' : 'gray')
                .text(count_selected);
            if (count_selected > 0) {
                $requests_actions.find('.requests-operation').parent().removeClass('disabled');
            } else {
                $requests_actions.find('.requests-operation').parent().addClass('disabled');
            }
        }

        function getRequestsTable() {
            if ($.wa.helpdesk_controller.currentGrid && $.wa.helpdesk_controller.currentGrid.isActive()) {
                return $.wa.helpdesk_controller.currentGrid.getTable();
            }
            return $('<div></div>');
        }
    },

    /**
     * Helper to show above the requests list results of operation
     * @param {*} content
     * @param {String} status info, success, warning, danger
     */
    createClosableNotice: function(content, status) {
        var w = $(`<div class="notice-above-requests-list alert light ${status} flexbox space-4 custom-m-0 small"></div>`);

        var $alert_icon = '';
        if (status === 'success') {
            $alert_icon = '<i class="fas fa-check-circle" />'
        }

        if (content) {
            if (typeof content === 'string') {
                w.html(content);
            } else {
                w.append(content);
            }

            if ($alert_icon) {
                w.prepend($('<span class="custom-mr-8" />').append($alert_icon));
            }

            w.append('<a href="javascript:void(0)" class="alert-close"><i class="fas fa-times" /></a>').on('click', '.alert-close', function() {
                w.remove();
            });
        }
        return w;
    },

    /** When there are errors checking email sources, show message above the layout, and (!) indicators in sidebar. */
    updateWorkflowErrors: function(wf_ids, sources) {
        $('#wa-app .js-app-sidebar a[href^="#/settings/workflow/"] .error.indicator').remove();
        if (wf_ids) {
            $.each(wf_ids, function(i, v) {
                $('#wa-app .js-app-sidebar a[href="#/settings/workflow/'+v+'"] .no-autorestore').append('<span class="error badge indicator">!</span>');
            });
        }

        if (Array.isArray(sources) && sources.length) {
            var source_names = [];
            $.each(sources, function(source_id, source_name) {
                source_names.push(source_name);
            });

            $('.content:not(#s-core) > #hd-announcement').remove();
            var ha = $('#hd-announcement').empty();
            if(!ha.size()) {
                ha = $('<div id="hd-announcement" class="alert warning custom-m-16 custom-mb-0"></div>').prependTo('#s-core');
            }
            if (this.currentHash.includes('/split/')) {
                ha.hide();
            }

            ha.append($('<a href="#" class="hd-announcement-close alert-close"><i class="fas fa-times"></i></a>').click(function() {
                ha.remove();
                $.post("?action=closeError");
            }));

            ha.append($('<div/>').text($_('Error delivering messages from:')+' '+source_names.join(', ')));
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
        const wrapper_selector = '#c-core-content';
        this.loadHTMLInto(wrapper_selector, url, params, beforeLoadCallback, afterLoadCallback);
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
                title = elem.find('h1:first .h-header-text').text() || $('#wa-app .js-app-sidebar .selected:first').text() || _w('Helpdesk');
            } else {
                title = elem.find('h1:first').text() || $('#wa-app .js-app-sidebar .selected:first').text() || _w('Helpdesk');
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
        this.waLoading.show();
        this.waLoading.animate(10000, 95, false);
    },
    progressLoading: function (xhr_event) {
        const percent = (xhr_event.loaded / xhr_event.total) * 100;
        this.waLoading.set(percent);
    },
    /** Hide all loading indicators in h1 headers */
    hideLoading: function() {
        this.waLoading.done();
        $(document).trigger('wa_loaded.helpdesk');
    },
    abortLoading: function () {
        this.waLoading.abort();
    },

    /** Gracefully reload sidebar. */
    reloadSidebar: function() {
        return $.get("?module=backend&action=sidebar", null, function (response) {
            var $sb = $("#wa-app .js-app-sidebar");
            $sb.css('height', $sb.height()+'px').html(response).css('height', ''); // prevents blinking in some browsers
            $.wa.helpdesk_controller.highlightSidebar();
            $.wa.helpdesk_controller.restoreSidebarCounts();
            $.wa.helpdesk_controller.restoreCollapsibleStatusInSidebar();
            $.wa.helpdesk_controller.bindToggleCollapseMenuSidebar();
            $.wa.helpdesk_controller.hideLoading();
            $(window).trigger('wa_sidebar_loaded.helpdesk');
        });
    },

    /** Collapsible sidebar sections */
    bindToggleCollapseMenuSidebar: function () {
        var toggleCollapse = function (e) {
            if (e.target.closest('.js-ignore-collapse')) {
                return true;
            }
            $.wa.helpdesk_controller.collapseSidebarSection(this, 'toggle');
        };
        $(".collapse-handler", $('#wa-app')).off('click').on('click', toggleCollapse);
    },

    /** Add .selected css class to li with <a> whose href attribute matches current hash.
      * If no such <a> found, then the first partial match is highlighted.
      * Hashes are compared after this.cleanHash() applied to them. */
    highlightSidebar: function(hash) {
        var currentHash = this.cleanHash(hash || location.hash);
        var partialMatch = false;
        var partialMatchLength = 2;
        var match = false;
        var $sidebar = $('#wa-app .js-app-sidebar');
        $sidebar.find('li a, .brick').each(function(k, v) {
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
        $sidebar.find('.selected').removeClass('selected');

        // Only highlight items that are outside of dropdown menus
        if (match.closest('ul.dropdown').length > 0) {
            return;
        }

        // Highlight matching element
        var p = (match.is('.brick') ? match : match.closest('li')).addClass('selected');

        // Update grid count in localStorage and in sidebar
        var $link = p.prop('tagName') === 'A' ? p : p.children('a');
        var skey = 'helpdesk/count/'+$.wa.helpdesk_controller.options.user_id+'/'+$link.attr('href');
        if (!$.wa.helpdesk_controller.currentGrid) {
            $.storage.del(skey);
            return;
        }
        if ($.wa.helpdesk_controller.currentGrid.isActive() && !$link.hasClass('no-count')) {

            var count = $.wa.helpdesk_controller.currentGrid.getTotal();
            $.storage.set(skey, count);

            this.updateItemCountSidebar($link, count);
        }
    },

    /** Restore sidebar counts from local storage */
    restoreSidebarCounts: function() {
        const self = this;
        const $sidebar = $('#wa-app .js-app-sidebar');
        $sidebar.find('li a, .brick').each(function() {
            var $el = $(this);
            var skey = 'helpdesk/count/'+$.wa.helpdesk_controller.options.user_id+'/'+$el.attr('href');
            var n = $.storage.get(skey);
            if (n !== null && !$el.hasClass('no-count')) {
                self.updateItemCountSidebar($el, n);
            }
        });
    },

    updateItemCountSidebar: function ($el, count) {
        const $cnt = $el.find('.count');
        if (!$cnt.length) {
            $el.append('<span class="count">'+count+'</span>');
        } else if (!$cnt.hasClass('no-autorestore')) {
            $cnt.text(count);
        }
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
                    $textarea.closest('form').find(':submit').addClass('yellow');
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

        var wrapper = $('#h-action-settings')
            .html('<form class="fields flexbox vertical space-8">' + html + '</form>');
        wrapper.prepend(
            `<a href="javascript:void(0);" class="back flexbox middle space-8" id="hd-last-view">
                <span class="icon size-24"><i class="fas fa-arrow-circle-left"></i></span>
                <div class="h-paging-top-title text-gray">${$_('Back to workflow customizing page')}</div>
                <i class="spinner h-header-loading custom-mt-4" style="display:none;"></i>
            </a>`
        );
        var buttons = $('<div class="bottombar h-fixed-bottombar sticky flexbox middle width-100 custom-px-12 custom-mt-16" />');
        if (are_you_sure) {
            are_you_sure = buttons.html(are_you_sure).text();
            buttons.empty();
        }
        buttons.append($('<button type="submit" class="button green" id="h-save-action">'+save+'</button>'));

        if (action_id) {
            buttons.append($('<button type="button" class="light-gray small custom-ml-auto"><i class="fas fa-trash-alt text-red"></i> <span class="desktop-and-tablet-only">'+(delete_this_action||'')+'</span></button>')
            .click(function() {
                if (are_you_sure && typeof confirm === 'function' && !confirm(are_you_sure)) {
                    return false;
                }
                $.wa.helpers.createLoadingSubmit($(this), { mr: 0 }).show();
                $.post('?module=editor&action=delaction&workflow_id='+workflow_id+'&action_id='+action_id+'&state_id='+state_id, {}, function() {
                    $.wa.helpdesk_controller.redispatch();
                });
            }));
        }

        $('.back', wrapper).click(function() {
            $.wa.helpdesk_controller.redispatch();
        });

        var form = $('form', wrapper);
        var button = buttons.find(':submit');
        $.each(serialize_data, function(i, item) {
            var name = item.name;
            var value = item.value;
            var el = form.find(':input[name="' + name +'"]');
            if (el.length) {
                if (el.is(':checkbox')) {
                    el.prop('checked', true);
                } else if (el.is(':radio')) {
                    el = el.filter('[value="' + value + '"]');
                    el.prop('checked', true);
                } else if (el.is('select')) {
                    el.find('option[value="' + value + '"]').prop('selected', true);
                } else if (el.is('input')) {
                    el.val(value);
                }
                el.trigger('change');
            }
        });

        form.append(buttons).submit(function() {
            var serialize_data = form.serializeArray();
            const loadingSubmit = $.wa.helpers.createLoadingSubmit(button).show();
            form.find('input, select, textarea').trigger('beforesubmit');
            $.post(url, form.serialize(), function(html) {
                wrapper.removeClass('modified');
                button.removeClass('yellow').addClass('green');
                loadingSubmit.hide();
                $('<span><i class="fas fa-check-circle text-green"></i> ' + $_('Saved') + '</span>').animate({ opacity: 0 }, 1000, function() {
                    $(this).remove();
                }).insertAfter(button)

                html = String(html).trim();
                if (html === 'ok') {
                    $.wa.helpdesk_controller.redispatch();
                } else if (html) {
                    $.wa.helpdesk_controller.renderActionSettings(
                        html,
                        $.extend({}, options, { confirm: false }),
                        serialize_data
                    );
                }
            });
            return false;
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

    showActionSettings: function(workflow_id, state_id, action_id, action_class, save_text, or, cancel, delete_this_action, are_you_sure) {
        $('#c-core-content').children().wrapAll('<div id="h-content-above-action-settings"></div>');
        $('#c-core-content').append('<div class="height-100 not-blank"><div class="article"><div id="h-action-settings" class="article-body"></div></div></div>');
        $('#h-content-above-action-settings').hide();

        const dfd = $.Deferred();
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
            dfd.resolve();
        });

        return dfd.promise();
    },

    /**
     * Helper to initialize attachments and adds new file input field for attachments
     *
     * Made on the basis of waUpload
     *
     * @param {String | jQuery} $wrapper
     */
    initAttachments: function ($wrapper, input_name) {
        if (!$wrapper || !input_name) { throw new Error('init files wrapper') }

        if (typeof $wrapper === 'string') {
            $wrapper = $($wrapper);
        }

        $wrapper = $wrapper.append('<div class="h-attachments" />').children();

        const addNewFile = () => {
            $wrapper.append(
                `<div class="h-attach"><div class="upload">
                    <label class="link">
                        <i class="fas fa-file-upload"></i>
                        <span>${$_('Attach file')}</span>
                        <input name="${input_name}" type="file" autocomplete="off">
                    </label>
                </div></div>`
            );

            queueMicrotask(() => {
                const $attach =  $wrapper.find('.h-attach:last-child');
                $attach.waUpload({ is_uploadbox: true });

                // when user selects an attachment, add another field
                let h;
                $attach.on('change drop', h = function(e) {
                    const $self = $(this);
                    if (e.type === 'drop') {
                        $attach.find(':file')[0].files = e.originalEvent.dataTransfer.files;
                    }

                    $attach
                        .find('label.link').hide()
                        .end().find('.filename').removeClass('hint')
                        .append(`<a href="javascript:void(0)" class="remove-attach back custom-ml-8" title="${$_('remove')}"><i class="icon middle fas fa-times-circle" /></a>`);

                    // attachment removal
                    $attach.find('a.remove-attach').off('click').on('click', function() {
                        $self.closest('.h-attach')
                            .off('change drop', h)
                            .remove();
                        return false;
                    });

                    if ($self.index() === $wrapper.find('.h-attach').length - 1) {
                        addNewFile();
                    }
                });
            });
        };
        addNewFile();
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
                $.wa.helpdesk_controller.confirmLeaveStop(event_id);
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

            e.preventDefault();

            var current_hash = $.wa.helpdesk_controller.getHash();
            $.waDialog.confirm({
                title: warning_message,
                text: confirm_question,
                success_button_title: $_('Leave'),
                success_button_class: 'danger',
                cancel_button_title: $_('Cancel'),
                cancel_button_class: 'light-gray',
                onSuccess () {
                    $.wa.helpdesk_controller.confirmLeaveStop(event_id);
                    if (window.location.hash === current_hash) {
                        $.wa.helpdesk_controller.redispatch();
                    } else {
                        $.wa.setHash(current_hash);
                    }

                }
            });
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
// methods for overridde
$.wa.helpdesk_controller._loadGrid = (() => $.wa.helpdesk_controller.loadGrid)();
$.wa.helpdesk_controller._requestsAllAction = (() => $.wa.helpdesk_controller.requestsAllAction)();
$.wa.helpdesk_controller._requestsSearchAction = (() => $.wa.helpdesk_controller.requestsSearchAction)();

})(jQuery);
