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
            'ID',
            'Created',
            'Updated',
            'Workflow',
            'State',
            'Status',
            'Helpdesk',
            'Assigned',
            'From',
            'Requests:',
            'Bulk operations',
            'Show %s records on a page',
            'Error delivering messages from:',
            'Save as a filter',
            'All requests',
            'Delete',
            'Share',
            'Unshare',
            'Pages',
            'next',
            'prev',
            'Save',
            'Age',
            'or',
            'cancel',
            'Cancel',
            'Clear',
            'Empty response from server',
            'Assigned:',
            '#%s',
            'ago',
            'via',
            'Last action',
            'Sort by:',
            'No actions with this request.',
            'Questions & Answers',
            'Are you sure?',
            'Delete field',
            'Checking data in this field',
            'Back to workflow customizing page',
            'No requests.',
            'of',
            'Saved',
            'Unsaved changes will be lost if you leave this page now.',
            'What is this?',
            'Follow mark',
            'After you set "Follow" mark, next time someone performs an action with this request it will appear as "Unread" for you and you will receive email notification about this action. So you will be able to follow all further activity related to this request.',
            'Insert',
            'Insert code block',
            'Loading',
            'Close',
            'OK'
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
