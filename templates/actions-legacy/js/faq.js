(function($) { "use strict";

    $.helpdesk_faq = {

        // helpers:
        onChangeInput: function(input, onChange) {
            var val = input.val();
            input.change(function() {
                onChange.call(input);
            });
            var timer = null;
            input.keydown(function() {
                if (timer) {
                    clearTimeout(timer);
                }
                timer = setTimeout(function() {
                    if (val !== input.val()) {
                        val = input.val();
                        onChange.call(input);
                    }
                }, 250);
            });
        },

        translitInput: function(dst_input, src_input, onChange) {
            var timer = null;
            if (!dst_input.val()) {
                src_input.off('keydown').on('keydown', function() {
                    var input = $(this);
                    if (!dst_input.data('edited') && $('input[name="is_public"]:checked').val() === '1' && input.val()) {
                        var loading = dst_input.next('.loading');
                        if (!loading.length) {
                            loading = $('<i class="icon16 loading"></i>').insertAfter(dst_input);
                        }
                        timer && clearTimeout(timer);
                        timer = setTimeout(function () {
                            if (!dst_input.data('edited')) {
                                $.get('?action=translit', { str: input.val(), prefix: 'url_' }, function(r) {
                                    loading.remove();
                                    if (r.status === 'ok') {
                                        if (!dst_input.data('edited')) {
                                            dst_input.val(r.data);
                                            if (typeof onChange === 'function') {
                                                onChange(dst_input, src_input);
                                            }
                                        } else {
                                            timer && clearTimeout(timer);
                                        }
                                    }
                                }, 'json');
                            } else {
                                timer && clearTimeout(timer);
                                loading.remove();
                            }
                        }, 300);
                    }
                });
            }
        },

        changeButton: function(button, back) {
            if (back) {
                button.removeClass('yellow').addClass('green');
            } else {
                button.removeClass('green').addClass('yellow');
            }
        },

        sticky: function(el, parent) {
            el.css({
                position: 'fixed',
                bottom: 0,
                left: 0,
                right: 0,
                zIndex: 1054
            });
            el.find('.button').css({
                marginLeft: 420
            });
            /*
            $(window).unbind('resize.h-faq-sticky').bind('resize.h-faq-sticky', function() {
                if (el && el.length && el.parent().length) {
                    el.width(el.parent().width() - 10);
                } else {
                    $(this).unbind('.h-faq-sticky');
                }
            });*/
        },

        updateCounters: function(counters) {
            var elems = $('#h-faq-categories .h-category');
            $.each(counters || [], function(i, el) {
                elems.filter('[data-category-id="' + el.id + '"]').find('.count').text(el.count);
                if (el.id == 0 && el.count > 0) {
                    $('.h-faq-none').show();
                }
            });
        },

        getCategoryId: function() {
            var hash = $.wa.helpdesk_controller.getHash();
            var match = hash.match(/^#\/faq\/category\/(\d+|none)\/$/);
            if (!match) {
                return null;
            }
            if (match[1] === 'none') {
                return 0;
            }
            return parseInt(match[1], 10) || null;
        },

        initFaqCategoriesDroppable: function() {
            $('#h-faq-categories li[data-category-id]').not('.selected')
                .droppable("destroy")       // old droppable destroy
                .droppable({
                    tolerance: 'touch',
                    over: function(event, ui) {
                        if ($(ui.draggable).is('[data-category-id]')) {
                            return;
                        }
                        var self = $(this);
                        var category_id = $.helpdesk_faq.getCategoryId();
                        if (category_id === self.data('categoryId')) {
                            return;
                        }
                        self.addClass('active');
                    },
                    out: function() {
                        $(this).removeClass('active');
                    },
                    drop: function(event, ui) {
                        var self = $(this);
                        if (self.hasClass('selected')) {
                            return;
                        }
                        self.removeClass('active');
                        if ($(ui.draggable).is('[data-category-id]')) {
                            return;
                        }
                        var category_id = self.data('categoryId');
                        var id = $(ui.draggable).data('id');
                        ui.draggable.next().remove();
                        ui.draggable.remove();
                        $.post('?module=faq&action=move&to_category=1', { id: id, category_id: category_id }, function(r) {
                            self.find('.count').text((parseInt(self.find('.count').text(), 10) || 0) + 1);
                            var item = $('#h-faq-categories li[data-category-id].selected');
                            var count = (parseInt(item.find('.count').text(), 10) || 0) - 1;
                            item.find('.count').text(count);
                            if (count <= 0 && item.hasClass('h-faq-none')) {
                                item.show();
                            }
                        }, 'json');
                    }
                });
        }

    };

})(jQuery);