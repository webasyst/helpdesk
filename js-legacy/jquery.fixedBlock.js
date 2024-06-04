(function($) { "use strict";

    $.fn.fixedBlock = function(options) {

        var block = $(this);

        if (arguments[0] === 'setDummyHeight') {
            var fixedBlock = block.data('fixedBlock');
            if (!fixedBlock) {
                return block;
            }
            if (fixedBlock.options.insertDummy) {
                var dummy = $('#' + fixedBlock.dummy_id);
                dummy.height(arguments[1]);
            }
            return block;
        }

        if (arguments[0] === 'destroy') {
            var fixedBlock = block.data('fixedBlock');
            if (!fixedBlock) {
                return block;
            }

            var ns = fixedBlock.ns;

            // unfix
            block.trigger('unfix.' + ns, [true]);

            // unbind handlers
            block.unbind('.' + ns);
            $(window).unbind('.' + ns);

            // clear timer
            var timer_id = fixedBlock.timer_id;
            if (timer_id) {
                clearTimeout(timer_id);
            }

            // remove dummy
            var dummy_id = fixedBlock.dummy_id;
            $('#' + dummy_id).remove();

            // reset data
            block.data('fixedBlock', null);

            return block;
        }

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
                dummy = $('<div id="'+dummy_id+'" class="fixedBlock-dummy">').css($.extend({
                    width: width,
                    height: height
                }, options.dummyCss || {}));
                block.before(dummy);
            }
        };
        var unmakeFixed = function() {
            if (!fixed) return;
            block.css({
                left: '',
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

        var ns = 'fixedBlock' + ('' + Math.random()).slice(2);

        block.bind('fix.' + ns, function() {
            makeFixed();
        }).bind('unfix.' + ns, function(e, force) {
            if (force) {
                fixed = 1;
            }
            unmakeFixed();
        });

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
            $(window)
                .bind('scroll.' + ns, handler)
                .bind('resize.' + ns, handler);
            handler();
        }

        $(window).bind('resize.' + ns, function() {
            block.trigger('win_resize', [fixed]);
        });

        block.data('fixedBlock', {
            options: options,
            dummy_id: dummy_id,
            ns: ns,
            timer_id: timer_id
        });

        return block;
    };

})(jQuery);