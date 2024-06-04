var wfActionsBlock = function(wf_actions) {
    $('form.source-settings-form select[name="params[new_request_state_id]"]').change(handleActions);
    handleActions();
    function handleActions() {
        var state = $('form.source-settings-form select[name="params[new_request_state_id]"]').val(), html = '<option value=""></option>';
        if (typeof wf_actions[state] != 'undefined') {
            $.each(wf_actions[state], function(i, val) {
                html += '<option value="' + i + '">' + val.name + '</option>';
            });
        }

        $('form.source-settings-form').find('.js-state-message').remove();
        if (typeof state == 'undefined' || state == '') {
            var title = '<div class="js-state-message state-caution-hint custom-mt-4">[`To select an action you should select state first (above).`]</div>'
            var disabled = true;
        } else {
            var title = '<div class="js-state-message state-success-hint custom-mt-4">[`Only actions for the selected state (above) are available.`]</div>'
            var disabled = false;
        }

        $('form.source-settings-form span.tmp').remove();
        var select = $('form.source-settings-form select[name="params[new_request_action_id]"]');
        var selected = select.find('option:selected').val();
        select.prop('disabled', disabled).html(html).after(title);
        select = $('form.source-settings-form select[name="params[new_request_action_id]"]');
        select.find('option[value="' + selected + '"]').prop('selected', true);
        select.trigger('change');
    }
};
