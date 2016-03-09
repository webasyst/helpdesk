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
        if (typeof state == 'undefined' || state == '') {
            var title = ' <span class="hint tmp" style="color: red;">[`To select an action you should select state first (above).`]</span>'
            var disabled = true;
        } else {
            var title = ' <span class="hint tmp">[`Only actions for the selected state (above) are available.`]</span>'
            var disabled = false;
        }
        $('form.source-settings-form span.tmp').remove();
        var select = $('form.source-settings-form select[name="params[new_request_action_id]"]');
        var selected = select.find('option:selected').val();
        select.attr('disabled', disabled).html(html).after(title);
        select = $('form.source-settings-form select[name="params[new_request_action_id]"]');
        select.find('option[value="' + selected + '"]').attr('selected', true);
        select.trigger('change');
    }
};