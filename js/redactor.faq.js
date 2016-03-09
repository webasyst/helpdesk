if (!RedactorPlugins) var RedactorPlugins = {};

RedactorPlugins.faq = function() {
    return {
        init: function() {
            var button = this.button.add('faq', $_('Questions & Answers'));
            this.button.addCallback(button, this.faq.show);
            this.button.setAwesome('faq', 'fa-question-circle');
        },
        show: function() {
            var self = this;
            this.modal.addTemplate('faq', this.faq.getTemplate());
            this.modal.addCallback('faq', function() {

                var loadSnippets = function() {
                    var args = Array.prototype.slice.apply(arguments);
                    args.unshift('?module=faq&action=snippets');
                    $.get.apply($, args);
                };

                loadSnippets(function(html) {
                    $('#h-faq-modal .h-section').html(html);
                });

                $('#h-faq-modal').off('click', '.h-faq-snippets .h-category').on('click', '.h-faq-snippets .h-category', function() {
                    $('.h-faq-snippets-content').removeClass('bordered-left');
                    $('#h-faq-modal .h-faq-list').html('<i class="icon16 loading"></i>' + $_('Loading'));
                    loadSnippets({
                        category_id: $(this).data('id')
                    }, function(html) {
                        $('#h-faq-modal .h-faq-snippets').html(html);
                        $('#h-faq-modal img').css({
                            maxWidth: 500
                        });
                    });
                });
                $('#h-faq-modal').off('click', '.h-faq-snippets .h-more').on('click', '.h-faq-snippets .h-more', function() {
                    $(this).closest('.h-faq-item')
                        .find('.h-truncated-text').hide()
                        .end()
                        .find('.h-full-text').show();
                    return false;
                });

                $('#h-faq-modal').off('click', '.h-faq-snippets .h-faq-item').on('click', '.h-faq-snippets .h-faq-item', function() {
                    self.faq.insertSnippet(
                        $(this).find('.h-faq-item-name').text(),
                        $(this).find('.h-full-text').html()
                    );
                });

                $('#h-faq-modal').off('submit', '.h-faq-search-form').on('submit', '.h-faq-search-form', function() {
                    var form = $(this);
                    $('.h-faq-snippets-content').removeClass('bordered-left');
                    $('#h-faq-modal .h-faq-list').html('<i class="icon16 loading"></i>' + $_('Loading'));
                    loadSnippets({
                        query: form.find('input:first').val() || ''
                    }, function(html) {
                        $('#h-faq-modal .h-faq-snippets').html(html);
                        $('#h-faq-modal img').css({
                            maxWidth: 500
                        });
                    });
                    return false;
                });

            });
            this.buffer.set();
            this.modal.load('faq', $_('Questions & Answers'), 800);
            this.selection.save();
            this.modal.show();
        },
        getTemplate: function() {
            var str = '<div id="h-faq-modal" style="height: 500px; overflow-y: auto;">' +
                    '<section class="h-section"><i class="icon16 loading"></i></section>' +
                '</div>';
            return str;
        },
        insertSnippet: function(title, body) {
            this.modal.close();
            this.selection.restore();
            $('.h-faq-question-settings').find('.h-name').val(title);
            this.insert.html($.trim(body).replace(/<span class=['"]h-faq-highlighted['"]>([\s\S]+?)<\/span>/m, '$1'));
        }
    };
};