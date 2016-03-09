<?php
/**
 * Sidebar editor.
 */
class helpdeskSettingsUnreadSaveController extends helpdeskJsonController
{
    public function execute()
    {
        $csm = new waContactSettingsModel();
        $default_settings = array(
            'count_all_new' => false,
            'count_assigned' => false,
            'count_assigned_logs' => false,
            'count_assigned_group' => false,
            'count_assigned_group_logs' => false,
            'mark_read_when_open' => false,
            'display_oncount' => true
        );
        $empty_settings = array_fill_keys(array_keys($default_settings), '');
        $settings_data = waRequest::post('settings');
        $settings_data = array_intersect_key($settings_data, $default_settings) + $empty_settings;
        $csm->delete(wa()->getUser()->getId(), 'helpdesk', array_keys($default_settings));
        $csm->set(wa()->getUser()->getId(), 'helpdesk', $settings_data);
    }
}

