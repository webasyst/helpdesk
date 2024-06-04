if (!RedactorPlugins) var RedactorPlugins = {};
(function () {
    let loading_html = '<i class="fas fa-spinner wa-animation-spin custom-mx-4"></i>';
    RedactorPlugins.faq = function() {
        return {
            init: function() {
                loading_html = `<div class="custom-mx-4">${loading_html} ${$_('Loading')}</div>`;
                var button = this.button.add('faq', $_('Questions & Answers'));
                this.button.setIcon(button, '<i class="fa fa-question-circle"></i>');
                this.button.addCallback(button, this.faq.show);
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
                        $('#h-faq-modal .h-faq-list').html(loading_html);
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
                        $('#h-faq-modal .h-faq-list').html(loading_html);
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
                var str = '<div id="h-faq-modal" style="height: 420px; overflow-y: auto;">' +
                          '<section class="h-section">'+loading_html+'</section>' +
                          '</div>' +
                          '<footer style="margin-top: 12px;">' +
                          '<button id="redactor-modal-button-cancel">' + $_('Close') + '</button>' +
                          '</footer>';
                return str;
            },
            insertSnippet: function(title, body) {
                this.modal.close();
                this.selection.restore();
                $('.h-faq-question-settings').find('.h-name').val(title);
                this.insert.html($.trim(body).replace(/<span class=['"]h-faq-highlighted['"]>([\s\S]+?)<\/span>/mg, '$1'));
            }
        };
    };
})();
