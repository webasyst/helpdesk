<?php

class helpdeskPersonalSettingsSaveController extends waJsonController
{
    public function execute()
    {
        if ($domain = waRequest::post('domain')) {
            $spm = new helpdeskSourceParamsModel();
            $spm->deleteByField('name', 'domain_' . $domain);
            if ($customer_portals = waRequest::post('customer_portals')) {
                $sources_params = array();
                foreach ($customer_portals as $k=>$v) {
                    $sources_params[] = array(
                        'source_id' => $k,
                        'name'      => 'domain_' . $domain,
                        'value'     => 1,
                    );
                    $sources[] = $k;
                }
                $spm->multipleInsert($sources_params);
            }

            $spm->deleteByField('name', 'sort_domain_' . $domain);
            if ($customer_portals = waRequest::post('customer_portals_source')) {
                $sources_params = array();
                $sort = 0;
                foreach ($customer_portals as $id) {
                    $sources_params[] = array(
                        'source_id' => $id,
                        'name'      => 'sort_domain_' . $domain,
                        'value'     => $sort++,
                    );
                }
                $spm->multipleInsert($sources_params);
            }

        }

        // Client-visible names of assigned users
        $portal_actor_display = waRequest::request('portal_actor_display', 'company_name', 'string');
        if ($portal_actor_display !== 'company_name' && $portal_actor_display !== 'contact_name') {
            $portal_actor_display = waRequest::request('portal_actor_display_custom', '', 'string');
            if (!strlen($portal_actor_display) || $portal_actor_display == wa()->getSetting('name', 'Webasyst', 'webasyst')) {
                $portal_actor_display = 'company_name';
            }
        }
        wao(new waAppSettingsModel())->set('helpdesk', 'portal_actor_display', $portal_actor_display);
    }
}