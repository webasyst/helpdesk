<?php
/**
 * Base class for all frontend actions.
 * Responsible for calling events and error processing.
 */
class helpdeskFrontendViewAction extends helpdeskViewAction
{
    public function execute()
    {
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new helpdeskFrontendLayout());
            $this->layout->assign('breadcrumbs', $this->getBreadcrumbs());
            if (waRequest::param('secure')) {
                $this->layout->assign('nofollow', true);
            }
        }

        $this->layout->assign('categories', $this->getFaqCategories());
    }

    public function getFaqCategories()
    {
        $fcm = new helpdeskFaqCategoryModel();
        return $fcm->getList('', array(
            'is_public' => true,
            'routes' => array($this->getCurrentRoute())
        ));
    }

    public function getBreadcrumbs()
    {
        return array();
    }

    /**
     * Helper for display().
     * Called instead of parent::display(), wrapped with error-catching and logging logic.
     * Overriden by some subclasses.
     */
    public function safeDisplay($clear_assign = true)
    {
        return parent::display($clear_assign);
    }

    public function display($clear_assign = true)
    {
        try {
            return $this->safeDisplay($clear_assign);
        } catch (waException $e) {
            $code = $e->getCode();
            if ($code == 404) {
                $url = $this->getConfig()->getRequestUrl(false, true);
                if (substr($url, -1) !== '/' && substr($url, -9) !== 'index.php') {
                    wa()->getResponse()->redirect($url.'/', 301);
                }
            }

            // Log the error if site is in debug mode
            if (waSystemConfig::isDebug()) {
                $msg = 'Unable to show frontend page. Details follow.';
                $msg .= "\n".$this->getLogMessage($e);
                waLog::log($msg, 'helpdesk.log');
            }

            $message = $e->getMessage();

            // Notify plugins about this error.
            // As if they could do anything about it...
            // Well, they can actually alter the message if they like.
            $params = array(
                'code' => $code,
                'message' => &$message,
                'exception' => $e,
                'action' => $this,
                'view' => $this->view,
            );
            wa()->event('frontend_error', $params);

            try {

                // Show nice theme-wrapped error message. Or at least do our best.
                $this->view->assign('error_code', $code);
                $this->view->assign('error_message', $message);
                $this->getResponse()->setStatus($code ? $code : 500);
                $this->setThemeTemplate('error.html');
                if (!waRequest::isXMLHttpRequest() && !$this->layout) {
                    $this->setLayout(new helpdeskFrontendLayout());
                    $this->layout->assign('nofollow', true);
                }
                $result = $this->view->fetch($this->getTemplate());
                if ($clear_assign) {
                    $this->view->clearAllAssign();
                }
                return $result;

            } catch (Exception $e2) {
                // Log initial error if not already logged
                if (!waSystemConfig::isDebug()) {
                    $msg = 'Unable to show frontend page. Details follow.';
                    $msg .= "\n".$this->getLogMessage($e);
                    waLog::log($msg, 'helpdesk.log');
                }

                // Log the second message
                $msg = 'Unable to find front-end theme. Details follow.';
                $msg .= "\n".$this->getLogMessage($e2);
                waLog::log($msg, 'helpdesk.log');

                // Show some clue
                return "Internal server error. See wa-log/helpdesk.log";
            }
        }
    }

    protected function getLogMessage($e)
    {
        $msg = "URL: ".$this->getConfig()->getRequestUrl(false, true);
        $msg .= "\nError: ".$e->getMessage().' ('.$e->getCode().')';
        $msg .= "\nTrace:\n".$e->getTraceAsString();
        return $msg;
    }

    /**
     * @return string
     */
    public function getCurrentRoute()
    {
        $current_route = wa()->getRouting()->getDomain(null, true).'/'.wa()->getRouting()->getRoute('url');
        return trim(rtrim($current_route, '*/'));
    }

    public function checkCategoryAccess($category)
    {
        if (empty($category['is_public'])) {
            return false;
        }
        $fcrm = new helpdeskFaqCategoryRoutesModel();
        $routes = $fcrm->get($category['id']);
        if (empty($routes)) {
            return true;
        }

        $current_route = $this->getCurrentRoute();
        foreach ($routes as $route) {
            if ($route['route'] === $current_route) {
                return true;
            }
        }
        return false;
    }
}

