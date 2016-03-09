<?php

class helpdeskFaqCategoryAction extends waViewAction
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fm = new helpdeskFaqModel();
        $fcm = new helpdeskFaqCategoryModel();

        $id = waRequest::request('id', null, waRequest::TYPE_STRING_TRIM);
        $category = $this->getCategory($id);

        $this->view->assign(array(
            'faq' => $fm->getEmptyRow(),
            'category' => $category,
            'icons' => helpdeskHelper::getIcons(),
            'count' => $fcm->countAll(),
            'all_settled' => $this->isAllDomainsHasHelpdeskSettled(),
            'site_url' => rtrim(wa()->getRootUrl(true), '/') . wa()->getAppUrl('site')
        ));
    }

    public function getCategory($id)
    {
        $fcm = new helpdeskFaqCategoryModel();

        if (is_numeric($id) && $id == '0') {
            $category = $fcm->getNoneCategory(true);
        } else if ($id > 0) {
            $category = $fcm->get($id);
            $category['url'] = $category['url'] ? $category['url'] : helpdeskHelper::transliterate($category['name']);
            foreach ($category['questions'] as &$q) {
                $q['url'] = $q['url'] ? $q['url'] : helpdeskHelper::transliterate($q['question']);
            }
            unset($q);
        } else {
            $category = $fcm->getEmptyRow();
        }
        if (!$category) {
            throw new waException('Unkown category: ' . $id);
        }
        $category['routes'] = $this->getRoutes($category);
        $category['routes_all'] = $this->isAllCheckedFalse($category['routes']);
        return $category;
    }

    public function getRoutes($category)
    {
        $category_routes = array();

        foreach (ifset($category['routes'], array()) as $item) {
            $category_routes[] = $item['route'];
        }

        $all_routes = array();
        $routing = wa()->getRouting();
        $domain_routes = $routing->getByApp('helpdesk');
        foreach ($domain_routes as $domain => $routes) {
            foreach ($routes as $r) {
                $route = rtrim($domain . '/' . $r['url'], '*/');
                $checked = in_array($route, $category_routes);
                if (!$checked && !empty($r['url_type'])) {
                    continue;
                }
                $url = '';
                if ($checked && $category['url']) {
                    $routing->setRoute($r, $domain);
                    $url = $routing->getUrl('helpdesk/faq_category',
                        array('category' => $category['id']), true);
                }
                $all_routes[] = array(
                    'route' => $route,
                    'checked' => $checked,
                    'url' => $url
                );
            }
        }
        return $all_routes;
    }

    public function isAllDomainsHasHelpdeskSettled()
    {
        $routing = wa()->getRouting();
        $all_domains = $routing->getDomains();

        $helpdesk_domains = array();
        foreach ($routing->getByApp('helpdesk') as $domain => $routes) {
            foreach ($routes as $r) {
                if (empty($r['url_type'])) {
                    $helpdesk_domains[] = $domain;
                }
            }
        }
        $helpdesk_domains = array_unique($helpdesk_domains);

        return count($helpdesk_domains) == count($all_domains);
    }

    public function isAllCheckedFalse($data)
    {
        foreach ($data as $item) {
            if ($item['checked']) {
                return false;
            }
        }
        return true;
    }

}

// EOF