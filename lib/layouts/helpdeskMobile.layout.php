<?php

class helpdeskMobileLayout extends waLayout
{
    public $admin;
    public $global_admin;
    public $disable_shared_filters;

    public function execute()
    {
        // Layout caching is forbidden
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Expires: ".date("r"));
        $this->executeAction('sidebar', new helpdeskBackendSidebarAction());

        $this->admin = wa()->getUser()->getRights('helpdesk', 'backend') > 1;
        $this->global_admin = wa()->getUser()->getRights('webasyst', 'backend') > 0;
        $this->disable_shared_filters = !!$this->appSettings('disable_shared_filters');

        $this->view->assign('admin', $this->admin);
        $this->view->assign('global_admin', $this->global_admin);
        $this->view->assign('disable_shared_filters', $this->disable_shared_filters);
        $this->view->assign('paginator_type', wa('helpdesk')->getConfig('helpdesk')->getOption('paginator_type'));

        /**
         * @event backend_layout
         * @param helpdeskBackendLayout $params ['layout']
         * @param array &$params ['blocks'] $layout->blocks
         * @param waSmarty3View $params ['view']
         * @return string HTML
         */
        $params = array(
            'layout' => $this,
            'blocks' => &$this->blocks,
            'view'   => $this->view,
        );
        $plugin_blocks = wa('helpdesk')->event('backend_layout', $params);
        $this->view->assign('plugin_blocks', $plugin_blocks);
    }
}

