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
                    this.$container = $container;
                    this.$img_link = $img_link;
                    this.$img = null;

                    this.inner_indent = inner_indent || 74;
                    this.esc = !!esc;

                    this.max_scale = (typeof max_scale === 'undefined' || max_scale < 2 ? 6 : max_scale);
                    this.min_scale = 1;
                    this.transformation = {
                        originX: 0,
                        originY: 0,
                        translateX: 0,
                        translateY: 0,
                        scale: this.min_scale
                    };
                    this.prev_position = {
                        x: null,
                        y: null
                    };

                    this.init();
                }

                init () {
                    const that = this;

                    that.$img_link.on('click', function (e) {
                        e.preventDefault();

                        $('body').css('overflow', 'hidden');

                        const $self = $(this);
                        that.$img = $self.prop('tagName') === 'IMG' ? $self.clone() : $self.find('img').clone();
                        that.$img.addClass('img-view__img').prop('draggable', false);
                        that.$container.prepend('<div class="img-view"><div class="img-view__box" /></div>');
                        const $img_view = that.$container.find('.img-view');

                        const href = $self.prop('tagName') === 'A' ? $self.prop('href') : that.$img.prop('src');
                        $img_view.append(`
                            <div class="img-view-controls">
                                <a href="javascript:void(0);" class="img-view-zoom-in"><i class="fas fa-search-plus"></i></a>
                                <a href="javascript:void(0);" class="img-view-zoom-out"><i class="fas fa-search-minus"></i></a>
                                <a href="${href}" class="img-view-download" download><i class="fas fa-cloud-download-alt"></i></a>
                                <a href="javascript:void(0);" class="img-view-close"><i class="fas fa-times"></i></a>
                            </div>`);
                        $img_view.find('.img-view__box').append(that.$img);

                        const closeView = () => {
                            that.clearPosition();
                            $img_view.remove();
                            $('body').css('overflow', 'auto');
                            that.$container.off('wheel');
                            that.$img_link.blur();
                        };

                        that.resizeImage();

                        // EVENTS

                        if (that.esc) {
                            $(document).off('keyup.image_viewer').one('keyup.image_viewer', (e) => {
                                if (e.key === 'Escape') {
                                    closeView();
                                }
                            });
                        }

                        $img_view.find('.img-view-close').one('click', closeView);

                        // Zoom In
                        $img_view.find('.img-view-zoom-in').on('click', function (e) {
                            e.preventDefault();
                            that.startZoom();

                            const prev_scale = that.transformation.scale;
                            that.transformation.scale += 1;
                            that.transformation.scale = Math.min(that.transformation.scale, that.max_scale);

                            // находим центральную точку изображения
                            if (that.prev_position.x === null) {
                                const rect = that.$img[0].getBoundingClientRect();
                                that.prev_position.x = (rect.right + rect.left)/that.transformation.scale;
                                that.prev_position.y = (rect.bottom + rect.top)/that.transformation.scale;
                            }

                            that.updateTransformWithOrigin({ ...that.prev_position,  prev_scale });
                        });

                        // Zoom Out
                        $img_view.find('.img-view-zoom-out').on('click', function (e) {
                            e.preventDefault();
                            that.startZoom();

                            that.transformation.scale -= 1;
                            that.transformation.scale = Math.max(that.transformation.scale, that.min_scale);

                            that.updateTransform();
                        });

                        that.initMoveImage();
                    });
                }

                initMoveImage () {
                    const that = this;

                    // Обработка колесика мыши для увеличения и уменьшения с центровкой
                    that.$container.on('wheel', function(e) {
                        if (!e.ctrlKey) {
                            return;
                        }
                        e.preventDefault();

                        that.startZoom();

                        const prev_scale = that.transformation.scale;
                        const delta = e.originalEvent.deltaY;
                        if (delta < 0) {
                            that.transformation.scale += 0.1;
                            that.transformation.scale = Math.min(that.transformation.scale, that.max_scale);
                        } else {
                            that.transformation.scale -= 0.1
                            that.transformation.scale = Math.max(that.transformation.scale, that.min_scale);
                        }

                        that.updateTransformWithOrigin({ ...that.getCoords(e), prev_scale });
                    })

                    that.$img.on('mousedown touchstart', (e) => {
                        e.preventDefault();
                        if (this.transformation.scale === this.min_scale) {
                            return false;
                        }

                        that.prev_position = { x: null, y: null };
                        const previous_position = this.getCoords(e);

                        that.$container.on('mousemove touchmove', (e) => {
                            const { x, y } = that.getCoords(e);
                            const originX = that.prev_position.x === null ? previous_position.x - x : x - that.prev_position.x; // originX: e.originalEvent.movementX,
                            const originY = that.prev_position.y === null ? previous_position.y - y : y - that.prev_position.y; // originY: e.originalEvent.movementY,

                            that.transformation.translateX += originX;
                            that.transformation.translateY += originY;
                            that.updateTransform();

                            that.prev_position.x = x;
                            that.prev_position.y = y;
                        });

                        that.$img.one('mouseup touchend', (e) => {
                            e.preventDefault();
                            that.$container.off('mousemove touchmove');
                        });
                    });
                }

                resizeImage () {
                    this.$img.css({
                        'max-height': (window.innerHeight - this.inner_indent) + 'px',
                        'max-width': '100vw'
                    });
                }

                updateTransform () {
                    if (this.transformation.scale === this.min_scale) {
                        this.finishZoom();
                        this.clearPosition();
                    }

                    const { scale, translateX, translateY } = this.transformation;
                    this.$img[0].style.transform = `matrix(${scale}, 0, 0, ${scale}, ${translateX}, ${translateY})`;
                };

                updateTransformWithOrigin ({ x, y, prev_scale }) {
                    const img = this.$img[0];
                    const rect = img.getBoundingClientRect();

                    const originX = x - rect.left;
                    const originY = y - rect.top;

                    // Корректируем позицию, чтобы центрировать масштабирование
                    const newOriginX = originX / prev_scale;
                    const newOriginY = originY / prev_scale;
                    // Перемещаем размер изображения так, чтобы курсор оставался на месте
                    img.style.transformOrigin = `${newOriginX}px ${newOriginY}px`;

                    const translate = this.getTranslate(prev_scale);
                    this.transformation.translateX = translate({ pos: originX, prevPos: this.transformation.originX, translate: this.transformation.translateX });
                    this.transformation.translateY = translate({ pos: originY, prevPos: this.transformation.originY, translate: this.transformation.translateY });

                    this.transformation.originX = newOriginX;
                    this.transformation.originY = newOriginY;
                    this.updateTransform();
                }

                getTranslate (scale) {
                    const valueInRange = (scale) => scale <= this.max_scale && scale >= this.min_scale;

                    return ({ pos, prevPos, translate }) => {
                        return valueInRange(scale) && pos !== prevPos
                            ? translate + (pos - prevPos * scale) * (1 - 1 / scale)
                            : translate;
                    }
                }

                clearPosition () {
                    this.transformation = {
                        originX: 0,
                        originY: 0,
                        translateX: 0,
                        translateY: 0,
                        scale: this.min_scale
                    };
                    this.prev_position = { x: null, y: null };
                    this.$img[0].style.transformOrigin = '50% 50%';
                }

                getCoords(e) {
                    e = e.touches ? e.touches[0] : e;
                    return {
                        x: e.clientX,
                        y: e.clientY
                    }
                }

                startZoom () {
                    this.$img.addClass('img-view__img--zoom');
                }

                finishZoom () {
                    setTimeout(() => this.$img.removeClass('img-view__img--zoom'));
                }

            };

            return new ImageViewer(options);
        }
    }
})(jQuery);
