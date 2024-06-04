(function($) { "use strict";
    $.wa.helpers = {
        vars: {
            change_color_class: 'yellow',
        },
        createLoadingSubmit ($button, options) {
            options = options || {};
            options = Object.assign({
                mr: 4, // 8
                timeoutDelay: 0
            }, options);
            const $loading = $(`<span class="icon size-16 custom-mr-${options.mr} js-loading"><i class="fas fa-spinner wa-animation-spin" /></span>`);
            const $existsIcon = $button.find('> .icon:not(.js-loading), > svg');
            const tagName = $button.prop('tagName');
            return {
                show: function () {
                    if ($existsIcon.length) {
                        $existsIcon.hide();
                    }

                    if (tagName === "BUTTON" || tagName === "A") {
                        $button.prepend($loading);
                    }

                    if (tagName === "INPUT") {
                        $loading.insertAfter($button);
                    }

                    if (tagName === "BUTTON" || tagName === "INPUT") {
                        $button.prop('disabled', true);
                    }

                    return this;
                },
                hide: function () {
                    setTimeout(() => {
                        $button.removeAttr('disabled');
                        $loading.remove();
                        if ($existsIcon.length) {
                            $existsIcon.show();
                        }
                    }, options.timeoutDelay)

                    return this;
                }
            }
        },
        createChangingSubmit ($button) {
            const color_class = $.wa.helpers.vars.change_color_class;
            return {
                change: function () {
                    if (!$button.prop('disabled')) {
                        $button.addClass(color_class)
                    }
                },
                reset: function () {
                    $button.removeClass(color_class)
                }
            }
        },
        watchChangeForm ($form, $button = null) {
            if (!$form || !$form.length) { return; }

            const color_class = $.wa.helpers.vars.change_color_class;
            const $btn = $button || $form.find(':submit');

            const change = () => {
                if ($btn.length && !$btn.prop('disabled')) {
                    $btn.addClass(color_class);
                }
            };
            const reset = () => {
                $btn.removeClass(color_class);
                bindEvent();
            };

            const bindEvent = () => {
                $form.data('changeform', {
                    change,
                    reset
                });
                $form.one('change input', () => {
                    change()
                });
            };
            bindEvent();

            return {
                change,
                reset
            }
        },
        onChangeInput (input, onChange) {
            var val = input.val();
            input.on('change', function() {
                onChange.call(input);
            });

            var timer = null;
            input.on('input', function() {
                if (!timer) {
                    if (val !== input.val()) {
                        val = input.val();
                        onChange.call(input);
                    }
                }

                clearTimeout(timer);
                timer = setTimeout(function() {
                    timer = null;
                }, 1000);
            });
        },

        // sortable
        cancelSortable (event) {
            const $item = $(event.item);

            $item.swap(event.oldIndex);
        },
        loadSortableJS () {
            const dfd = $.Deferred();

            const $script = $("#wa-header-js"),
                path = $script.attr('src').replace(/wa-content\/js\/jquery-wa\/wa.header.js.*$/, '');

            const urls = [
                "wa-content/js/sortable/sortable.min.js",
                "wa-content/js/sortable/jquery-sortable.min.js",
            ];

            const sortableDeferred = urls.reduce((dfd, url) => {
                return dfd.then(() => {
                    return $.ajax({
                        cache: true,
                        dataType: "script",
                        url: path + url
                    });
                });
            }, $.Deferred().resolve());

            sortableDeferred.done(() => {
                dfd.resolve();
            });

            return dfd.promise();
        },

        // scroll
        scrollToElement (wrapperOrSelector, headerOffset = 80) {
            const el = (typeof wrapperOrSelector === 'object' && wrapperOrSelector.length
                ? wrapperOrSelector[0]
                : $(wrapperOrSelector));

            const elementPosition = el.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.scrollY - headerOffset;
            window.scrollTo({
                top: offsetPosition,
                behavior: "smooth"
            });
        },

        // check OS
        getPlatform () {
            if (navigator.userAgentData) {
                return navigator.userAgentData.platform;
            }
            return navigator.platform;
        },
        isMacintosh () {
            return this.getPlatform().indexOf('Mac') > -1
        },
        isWindows () {
            return this.getPlatform().indexOf('Win') > -1
        },
    }
})(jQuery);
