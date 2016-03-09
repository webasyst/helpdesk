<?php

class helpdeskHelpdeskPersonalSettingsHandler extends waEventHandler
{
    public function execute(&$params)
    {
        $sm = new helpdeskSourceModel();
        $spm = new helpdeskSourceParamsModel();

        $customer_portals = $this->getCustomerPortals($sm, $spm, $params['domain']);
        $source_params = $spm->getByField('name', 'domain_' . $params['domain'], true);
        $domain_sources = array();
        foreach ($source_params as $p) {
            $domain_sources[$p['source_id']] = $p['value'];
        }

        $settings = array(
            'portal_actor_display' => wa('helpdesk')->getSetting('portal_actor_display', 'company_name', 'helpdesk'),
        );

        $view = wa()->getView();
        $view->assign('customer_portals', $customer_portals);
        $view->assign('domain_sources', $domain_sources);
        $view->assign('domain', $params['domain']);
        $view->assign('settings', $settings);

        $template = wa()->getAppPath('templates/handlers/PersonalSettings.html', 'helpdesk');
        return $view->fetch($template);
    }

    public function getCustomerPortals(helpdeskSourceModel $sm, helpdeskSourceParamsModel $spm, $domain)
    {
        $source_params = array();
        $customer_portals = $sm->getByField('type', 'form', 'id');
        $sorts = $spm->select('*')->where("name = :0", array(
             'sort_domain_' . $domain
        ))->order('CAST(value AS UNSIGNED)')->fetchAll();
        $res = array();
        $sort = 0 ;
        foreach ($sorts as $s) {
            if (isset($customer_portals[$s['source_id']])) {
                $res[] = $customer_portals[$s['source_id']];
                $source_params[] = array(
                    'source_id' => $s['source_id'],
                    'sort_domain_' . $domain,
                    'value' => $sort++
                );
                unset($customer_portals[$s['source_id']]);
            }
        }
        if ($customer_portals) {
            foreach ($customer_portals as $p) {
                $res[] = $p;
                $source_params[] = array(
                    'source_id' => $p['id'],
                    'sort_domain_' . $domain,
                    'value' => $sort++
                );
            }
            $spm->deleteByField('name', 'sort_domain_' . $domain);
            $spm->multipleInsert($source_params);
        }

        return $res;
    }
}
