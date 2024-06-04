<?php
/** A list of localized strings to use in JS. */
class helpdeskBackendLocController extends waController
{
    public function execute()
    {
        header('Content-type: application/x-javascript; charset=utf-8');

        $strings = array();

        // Application locale strings
        foreach(array(
            'ID', //_w('ID')
            'Created', //_w('Created')
            'Updated', //_w('Updated')
            'Workflow', //_w('Workflow')
            'State', //_w('State')
            'Status', //_w('Status')
            'Helpdesk', //_w('Helpdesk')
            'Assigned', //_w('Assigned')
            'From', //_w('From')
            'Requests:', //_w('Requests:')
            'Bulk operations', //_w('Bulk operations')
            'Show %s records on a page', //_w('Show %s records on a page')
            'Error delivering messages from:', //_w('Error delivering messages from:')
            'Save as a filter', //_w('Save as a filter')
            'All requests', //_w('All requests')
            'Delete', //_w('Delete')
            'Pages', //_w('Pages')
            'next', //_w('next')
            'prev', //_w('prev')
            'Save', //_w('Save')
            'Age', //_w('Age')
            'or', //_w('or')
            'cancel', //_w('cancel')
            'Cancel', //_w('Cancel')
            'Clear', //_w('Clear')
            'Empty response from server', //_w('Empty response from server')
            'Assigned:', //_w('Assigned:')
            '#%s', //_w('#%s')
            'ago', //_w('ago')
            'via', //_w('via')
            'Last action', //_w('Last action')
            'Sort by:', //_w('Sort by:')
            'No actions with this request.', //_w('No actions with this request.')
            'Questions & Answers', //_w('Questions & Answers')
            'Are you sure?', //_w('Are you sure?')
            'Delete field', //_w('Delete field')
            'Checking data in this field', //_w('Checking data in this field')
            'Back to workflow customizing page', //_w('Back to workflow customizing page')
            'No requests.', //_w('No requests.')
            'of', //_w('of')
            'Saved', //_w('Saved')
            'Unsaved changes will be lost if you leave this page now.', //_w('Unsaved changes will be lost if you leave this page now.')
            'What is this?', //_w('What is this?')
            '“Follow” mark', //_w('“Follow” mark')
            'After you set the “Follow” mark, the next time someone performs an action with this request it will appear as “Unread” for you and you will receive email notification about this action. So you will be able to follow all further activity related to this request.', //_w('After you set the “Follow” mark, the next time someone performs an action with this request it will appear as “Unread” for you and you will receive email notification about this action. So you will be able to follow all further activity related to this request.')
            'Insert', //_w('Insert')
            'Insert code block', //_w('Insert code block')
            'Loading', //_w('Loading')
            'Close', //_w('Close')
            'OK', //_w('OK')
        ) as $s) {
            $strings[$s] = _w($s);
        }

        // System locale strings
        foreach(array(
        ) as $s) {
            $strings[$s] = _ws($s);
        }

        // multiple forms
        foreach(array(
            //array('contacts selected', 'contact selected'),
        ) as $s) {
            $strings[$s[0]] = array(_w($s[1],$s[0],1), _w($s[1],$s[0],2), _w($s[1],$s[0],5));
        }

        // stdClass is used to show {} instead of [] when there are no strings
        echo '$.wa.locale = $.extend($.wa.locale || {}, '.json_encode(ifempty($strings, new stdClass())).');';
    }
}
