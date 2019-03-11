<?php

class helpdeskSitemapConfig extends waSitemapConfig
{
    public function execute()
    {
        $routes = $this->getRoutes();

        $fcm = new helpdeskFaqCategoryModel();
        $page_model = new helpdeskPageModel();

        foreach ($routes as $route) {
            $this->routing->setRoute($route);
            $u = $this->getUrlByRoute($route);
            $domain = $this->routing->getDomain(null, true);
            $route_url = $domain.'/'.$this->routing->getRoute('url');
            $route_url = trim(rtrim($route_url, '*/'));

            $categories = $fcm->getList('', array(
                'is_public' => true,
                'routes' => array($route_url)
            ));
            foreach ($categories as $c) {
                $c_url = $u . 'faq/';
                if (!empty($c['url'])) {
                    $c_url .= $c['url'] . '/';
                }
                $fm = new helpdeskFaqModel();
                $faq_list = $fm->getByFaqCategory($c['id'], true);
                $lastmod = 0;
                foreach ($faq_list as $row) {
                    $faq_lastmod = strtotime(!empty($row['update_datetime']) ? $row['update_datetime'] : $row['create_datetime']);
                    if ($faq_lastmod > $lastmod) {
                        $lastmod = $faq_lastmod;
                    }
                    $this->addUrl($c_url.$row['url'].'/', $faq_lastmod, self::CHANGE_MONTHLY, 0.8);
                }
                if (!$lastmod) {
                    $lastmod = date('Y-m-d H:i:s');
                }
                $this->addUrl($c_url, $lastmod, self::CHANGE_WEEKLY, 0.2);
            }
            // pages
            $this->addPages($page_model, $route);
        }
    }
}
