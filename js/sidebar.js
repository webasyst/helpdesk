(function ($) {
    $.sidebar = {

        /**
         * @var {jQuery} Jquery object of sidebar
         */
        sidebar: null,

        options: {},

        init: function (options) {
            this.options = options || {};
            this.sidebar = $('#wa').find('.js-app-sidebar:first');

            // DOM
            this.$window = $(window);
            this.$skeleton = this.sidebar.find('.js-helpdesk-sidebar-skeleton');
            this.$sidebarBody = this.sidebar.find('.js-helpdesk-sidebar-body');

            // init events
            this.loadedEvent = $.Event('wa_sidebar_loaded');
            this.helpdeskLoadedEvent = $.Event('wa_sidebar_helpdesk_loaded');
            this.helpdeskUnLoadedEvent = $.Event('wa_sidebar_helpdesk_unloaded');

            this.bindEvents();

            // trigger loaded event
            this.$window.trigger(this.loadedEvent);
        },

        bindEvents: function() {
            this.$window.on('wa_sidebar_loaded.helpdesk', () => {
                this.initMobileSidebar()
                this.initMobileSidebarToggle();
            });
            this.$window.on('resize.helpdesk', () => {
                this.initMobileSidebar()
                this.initMobileSidebarToggle();
            });

            this.$window.on('wa_loaded.helpdesk', $.proxy(this.removeSkeleton, this));

            this.sidebar.on('click.helpdesk', '.menu li a, .js-mobile-collapse-sidebar, .bricks a', $.proxy(this.slideUpSidebar, this));
            this.$window.on('wa_loaded.helpdesk', $.proxy(this.removeSkeleton, this));
        },

        initMobileSidebar: function() {
            if (this.$window.width() > 768) {
                if (this.sidebarData) {
                    this.sidebar.removeData('sidebar');
                    this.sidebarData.unbindEvents();
                    delete this.sidebarData;
                }

                return;
            }

            if (!this.sidebarData) {
                this.sidebar.waShowSidebar();
                this.sidebarData = this.sidebar.data('sidebar');
            }
        },

        slideUpSidebar: function() {
            if (!this.sidebarData) {
                return;
            }

            this.sidebarData.$sidebar_content.slideUp(200);
            this.sidebarData.is_open = false;
        },

        initMobileSidebarToggle: function () {
            const sidebar_data_name= 'mobile-sidebar';
            const sidebar_selector= `[data-${sidebar_data_name}]`;
            const content_selector= '[data-mobile-content]';
            const hidden_sidebar_class= '--mobile-friendly';

            if (this.$window.width() > 760) {
                $(sidebar_selector).addClass(hidden_sidebar_class).show();
                $(content_selector).show();
                return;
            }

            const backToSidebar = function (e) {
                e.preventDefault();

                $(content_selector).hide();
                $(sidebar_selector).add($('.js-mobile-hide-with-sidebar'))
                    .removeClass('desktop-and-tablet-only')
                    // .show('slide', { direction: 'left' }, 250)
            };
            const show = () => {
                $(content_selector).show();
                $(sidebar_selector).add($('.js-mobile-hide-with-sidebar'))
                    // .hide()
                    // .addClass(hidden_sidebar_class);
                    .addClass('desktop-and-tablet-only');
            };
            const showContent = function () {
                let $self = $(this);
                if ($self.closest('.js-mobile-not-hide-sidebar').length) {
                    return true;
                }

                let href = $self.attr('href');
                if ($self.prop('tagName') === 'TR') {
                    href = $self.find('a:first').attr('href');
                }

                if (!href) {
                    return true;
                }

                if (window.location.hash.includes(href)) {
                    show();
                } else {
                    $(document).one('wa_loaded.helpdesk', show)
                }
            };
            const initialVisiblity = function () {
                const $sidebar = $(sidebar_selector);
                if ($sidebar.length) {
                    if ($sidebar.data(sidebar_data_name) === 'init') {
                        $sidebar.removeClass(hidden_sidebar_class);
                        $(content_selector).hide();
                    }
                }
            };

            $(document)
                .off('wa_content_sidebar_loaded.helpdesk')
                .on('wa_content_sidebar_loaded.helpdesk', initialVisiblity)
                .off('click.wa_content_sidebar_mobile_back')
                .on('click.wa_content_sidebar_mobile_back', '.js-mobile-back', backToSidebar)
                .off('click.wa_content_sidebar_show_content')
                .on('click.wa_content_sidebar_show_content', `${sidebar_selector} a, ${sidebar_selector} tr`, showContent)
                .off('show_mobile_content.helpdesk')
                .on('show_mobile_content.helpdesk', show);
        },

        removeSkeleton: function() {
            this.$sidebarBody.removeClass('hidden');
            this.$skeleton.addClass('hidden');
        },

        unbindEvents: function() {
            this.sidebar.off('.helpdesk');
            this.$window.off('.helpdesk');

            this.sidebar.removeData('sidebar');
            delete this.sidebarData;
        }

    };
})(jQuery);
