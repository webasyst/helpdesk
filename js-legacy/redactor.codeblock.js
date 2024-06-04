if (!RedactorPlugins) var RedactorPlugins = {};

RedactorPlugins.codeblock = function() {
    return {
        init: function() {
            var button = this.button.add('codeblock', $_('Insert code block'));
            this.button.addCallback(button, this.codeblock.show);
            this.button.setAwesome('codeblock', 'fa-code');
        },
        show: function() {
            var self = this;
            this.modal.addTemplate('codeblock', this.codeblock.getTemplate());
            this.modal.addCallback('codeblock', function() {
                $('#h-codeblock-button').click(function() {
                    self.codeblock.insertSnippet(
                        $('#h-codeblock-textarea').val()
                    );
                });
            });
            this.buffer.set();
            this.modal.load('codeblock', $_('Insert code block'), 800);
            this.selection.save();
            this.modal.show();
            $('#h-codeblock-textarea').focus();
        },
        getTemplate: function() {
            var str = '<div id="h-codeblock-modal" style="height: 350px; overflow-y: auto;">' +
                    '<section>' +
                        '<textarea id="h-codeblock-textarea" style="height: 200px"></textarea>' +
                        '<input id="h-codeblock-button" type="button" style="margin-top: 10px;" value="' + $_('Insert') + '">' +
                    '</section>' +
                '</div>';
            return str;
        },
        insertSnippet: function(body) {
            this.modal.close();
            this.selection.restore();
            this.insert.htmlWithoutClean('<p style="white-space: pre; color: #3c3c3c; font-family: Arial; font-size: 0.9em;">' + $.wa.encodeHTML($.trim(body)) + '</p>');
        }
    };
};