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

        initImageViewer (options) {
            class ImageViewer {
                constructor ({ $container, $img_link, max_scale, inner_indent, esc }) {
                    this.$container_viewport = $container;
                    this.$img_link = $img_link;
                    this.max_scale = max_scale || 2;
                    this.inner_indent = inner_indent || 74;

                    this.$current_img = null;
                    this.isZoomIn = false;
                    this.esc = !!esc;

                    this.bindEvents();
                }

                bindEvents () {
                    const that = this;
                    this.$img_link.on('click', function (e) {
                        e.preventDefault();

                        const $self = $(this);
                        that.$current_img = $self.prop('tagName') === 'IMG' ? $self.clone() : $self.find('img').clone();
                        const href = $self.prop('tagName') === 'A' ? $self.prop('href') : that.$current_img.prop('src');

                        that.$current_img.addClass('img-view__img').prop('draggable', false);
                        that.$container_viewport.prepend('<div class="img-view"><div class="img-view__box" /></div>');

                        $('body').css('overflow', 'hidden');

                        const $img_view = that.$container_viewport.find('.img-view');
                        $img_view.append(`
                            <div class="img-view-controls">
                                <a href="javascript:void(0);" class="img-view-zoom-in"><i class="fas fa-search-plus"></i></a>
                                <a href="javascript:void(0);" class="img-view-zoom-out hidden"><i class="fas fa-search-minus"></i></a>
                                <a href="${href}" class="img-view-download" download><i class="fas fa-cloud-download-alt"></i></a>
                                <a href="javascript:void(0);" class="img-view-close"><i class="fas fa-times"></i></a>
                            </div>`);
                        $img_view.find('.img-view__box').append(that.$current_img);

                        const resizeImage = that.resizeImage.bind(that);
                        $(window).on('resize', resizeImage);
                        resizeImage();

                        const closeView = () => {
                            $img_view.remove();
                            $(window).off('resize', resizeImage);
                            $('body').css('overflow', 'auto');
                        };
                        $img_view.find('.img-view-close').one('click', closeView);
                        if (that.esc) {
                            $(document).off('keyup.image_viewer').on('keyup.image_viewer', (e) => {
                                if (e.key === 'Escape') {
                                    closeView();
                                }
                            });
                        }

                        $img_view.find('.img-view-zoom-in').on('click', function () {
                            that.$current_img.css('transform', `matrix(${that.max_scale}, 0, 0, ${that.max_scale}, 0, 0)`);
                            setTimeout(() => {
                                that.$current_img.addClass('img-view__img--zoom-in');
                            }, 300);

                            $(this).addClass('hidden');
                            $img_view.find('.img-view-zoom-out').removeClass('hidden');

                            that.isZoomIn = true;
                            that.initMoveImg();
                        });
                        $img_view.find('.img-view-zoom-out').on('click', function () {
                            that.$current_img.css('transform', 'matrix(1, 0, 0, 1, 0, 0)');
                            that.$current_img.removeClass('img-view__img--zoom-in');

                            $(this).addClass('hidden');
                            $img_view.find('.img-view-zoom-in').removeClass('hidden');

                            that.isZoomIn = false;
                            that.clearLastMousePosition();
                        });
                    });
                }

                initMoveImg () {
                    this.isDragging = false;
                    this.previousMousePosition = { x: 0, y: 0 };
                    this.lastMousePosition = { x: 0, y: 0 };

                    this.$current_img.on('mousedown touchstart', (e) => {
                        e.preventDefault();
                        if (!this.isZoomIn) {
                            return false;
                        }

                        this.isDragging = true;
                        const coords = this.getCoords(e);
                        this.previousMousePosition = {
                            x: coords.x - this.lastMousePosition.x,
                            y: coords.y - this.lastMousePosition.y
                        };
                    });
                    this.$current_img.on('mouseup touchend', (e) => {
                        this.isDragging = false;
                    });
                    this.$current_img.on('mousemove touchmove', (e) => {
                        const coords = this.getCoords(e);
                        if (this.isDragging) {
                            const deltaMove = {
                                x: coords.x - this.previousMousePosition.x,
                                y: coords.y - this.previousMousePosition.y
                            };

                            this.lastMousePosition.x = deltaMove.x;
                            this.lastMousePosition.y = deltaMove.y;
                            this.updateImagePosition(deltaMove);
                        }
                    });
                }

                resizeImage () {
                    this.$current_img.css({
                        'max-height': (window.innerHeight - this.inner_indent) + 'px',
                        'max-width': '100vw'
                    });
                }

                updateImagePosition(deltaMove) {
                    this.$current_img.css('transform', `matrix(${this.max_scale}, 0, 0, ${this.max_scale}, ${deltaMove.x}, ${deltaMove.y})`);
                }

                clearLastMousePosition () {
                    this.lastMousePosition.x = 0;
                    this.lastMousePosition.y = 0;
                }

                getCoords(e) {
                    e = e.touches ? e.touches[0] : e;
                    return {
                        x: e.clientX,
                        y: e.clientY
                    }
                }
            };

            return new ImageViewer(options);
        }
    }
})(jQuery);
