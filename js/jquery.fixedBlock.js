(function($) { "use strict";

    $.fn.fixedBlock = function(options) {
        var block = $(this);
        if (block.data('fixedBlock')) {
            return block;
        }
        var dummy_id = 'fixedBlock' + ('' + Math.random()).slice(2);
        var width = block.width();
        var block_offset = block.offset();
        block_offset.right = $(window).width() - (block_offset.left + width);
        var height = block.height();

        options = $.extend({
            css: {},
            offsetByWith: true,
            automatic: true,
            insertDummy: true,
            shift: 0,
            dummyCss: {}
        }, options || {});

        var fixed = 0;

        var makeFixed = function() {
            if (fixed) return;
            var css = $.extend({
                left: block_offset.left,
                top: block_offset.right
            }, options.css || {});

            css.position = 'fixed';

            if (options.offsetByWith) {
                css.width = width;
            } else {
                css.right = block_offset.right;
            }

            block.css(css);
            if (options.insertDummy) {
                insertDummy();
            }
            fixed += 1;
            block.trigger('fixed');
            if (fixed <= 1) {
                block.trigger('first_fixed');
            }
        };
        var insertDummy = function() {
            var dummy = $('#' + dummy_id).show();
            if (!dummy.length) {
                dummy = $('<div id="'+dummy_id+'">').css($.extend({
                    width: width,
                    height: height
                }, options.dummyCss || {}));
                block.before(dummy);
            }
        };
        var unmakeFixed = function() {
            if (!fixed) return;
            block.css({
                position: '',
                top: '',
                background: ''
            });
            if (options.insertDummy) {
                removeDummy();
            }
            fixed = 0;
            block.trigger('unfixed');
        };
        var removeDummy = function() {
            $('#' + dummy_id).hide();
        };


        block.bind('fix', function() {
            makeFixed();
        }).bind('unfix', function() {
            unmakeFixed();
        });

        var ns = 'fixedBlock' + ('' + Math.random()).slice(2);

        if (options.automatic) {
            var el_offset = block.offset();
            var handler = function() {
                if (!block || !block.length || !block.parent() || !block.parent().length || !block.closest('body') || !block.closest('body').length) {
                    $(window).unbind('.' + ns);
                }
                if ($(window).scrollTop() > el_offset.top + options.shift) {
                    makeFixed();
                } else {
                    unmakeFixed();
                }
            };
            var timer_id = null;
            $(window).bind('scroll.' + ns, handler).bind('resize.' + ns, handler).mousemove(function() {
                if (!timer_id) {
                    timer_id = setTimeout(function() {
                        handler();
                        clearTimeout(timer_id);
                        timer_id = null;
                    }, 250);
                }
            });
        }

        $(window).bind('resize.' + ns, function() {
            block.trigger('win_resize', [fixed]);
        });

        block.data('fixedBlock', 1);

        return block;
    };

})(jQuery);