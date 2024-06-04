(function($) { "use strict";

    /**
     * jQuery plugin: makes element stick to top or bottom of the page when not in view.
     * When would be normally in view, stays as usual in its place.
     */

    $.fn.sticky = function(options) {
        var $self = this;
        var o = $.extend({
            fixed_class: 'sticky-fixed',
            isStaticVisible: defaultIsStaticVisible,
            getClone: defaultGetClone
        }, options || {});

        var getClone = function($e, o) {
            var clone = o.getClone.call($e, $e, o);
            $self.trigger('make_clone', [clone]);
            o.onMakeClone && o.onMakeClone.call($self, clone);
            return clone;
        };

        var hideFixed = function(el) {
            var args = Array.prototype.slice.call(arguments, 0);
            o.hideFixed && o.hideFixed.apply(el, args.slice(1));
            $self.trigger('hide_fixed', args);
            if ($self.get(0) !== $(el).get(0)) {
                $(el).trigger('hide_hixed', args.slice(1));
            }
        };

        var showFixed = function(el) {
            var args = Array.prototype.slice.call(arguments, 0);
            o.showFixed && o.showFixed.apply(el, args.slice(1));
            $self.trigger('show_fixed', args);
            if ($self.get(0) !== $(el).get(0)) {
                $(el).trigger('show_fixed', args.slice(1));
            }
        };

        var updateFixed = function(el) {
            var args = Array.prototype.slice.call(arguments, 0);
            o.updateFixed && o.updateFixed.apply(el, args.slice(1));
            $self.trigger('update_fixed', args);
            if ($self.get(0) !== $(el).get(0)) {
                $(el).trigger('update_fixed', args.slice(1));
            }
        };

        // Prepare data for each element we're about to initialize
        var elements = $self.map(function() {
            var $e = $(this);
            return {
                element: $e,
                fixed_clone: getClone.call($e, $e, o),
                is_fixed: false
            };
        }).get();

        $(window).on('resize scroll', ensurePosition);
        ensurePosition();
        return this;

        function ensurePosition() {
            if (!$self.closest('body').length) {
                $(window).off('resize scroll', ensurePosition);
                return;
            }

            $.each(elements, function(i, e) {
                if (o.isStaticVisible.call(e.element, e, o)) {
                    if (e.is_fixed) {
                        e.is_fixed = false;
                        e.fixed_clone.hide();
                        hideFixed(e.fixed_clone, e, o);
                    }
                } else {
                    if (!e.is_fixed) {
                        e.is_fixed = true;
                        e.fixed_clone.show();
                        showFixed(e.fixed_clone, e, o);
                    }
                    o.updateFixed && o.updateFixed.call(e.fixed_clone, e, o); // !!! use events instead?
                }
            });
        }
    };

    // in case if gonna use this method outside
    $.fn.sticky.isStaticVisible = function(el) {
        var $window = $(window);
        var window_borders = {
            top: $window.scrollTop(),
            right: $window.scrollLeft() + $window.width(),
            bottom: $window.scrollTop() + $window.height(),
            left: $window.scrollLeft()
        };

        var element_borders = el.offset();
        element_borders.right = element_borders.left + el.outerWidth();
        element_borders.bottom = element_borders.top + el.outerHeight();

        return  window_borders.top < element_borders.top &&
                window_borders.left < element_borders.left &&
                window_borders.right > element_borders.right &&
                window_borders.bottom > element_borders.bottom;
    };

    function defaultIsStaticVisible(e, o) {
        return $.fn.sticky.isStaticVisible.call(this, e.element);
    }

    function defaultGetClone($e, o) {
        return $e.clone().addClass(o.fixed_class || 'sticky-fixed').css($.extend(
            { position: 'fixed', display: 'none' },
            o.fixed_css || {}
        )).removeAttr('id').insertAfter($e);
    }

})(jQuery);