<?php
/**
 * Form to create new request in customer portal.
 * Controller for my.newrequest.html in themes.
 */
class helpdeskFrontendMyNewAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $source_id = waRequest::param('id', 0, 'int');
        if (!$source_id) {
            throw new waException('Not found', 404);
        }

        $source = $this->source = new helpdeskSource($source_id);
        $st = $source->getSourceType();
        if ($source->status <= 0 || !$st instanceof helpdeskFormSTInterface) {
            throw new waException('Not found', 404);
        }
        $this->view->assign('source', $source);
        $this->view->assign('form_html', $st->getFormHtml($source));

        $this->setThemeTemplate('my.newrequest.html');
        $this->getResponse()->setTitle(_w('New request'));

        parent::execute();
    }

    public function getBreadcrumbs()
    {
        $result = parent::getBreadcrumbs();
        $result[] = array(
            'name' => _w('My account'),
            'url' => wa()->getRouteUrl('helpdesk/frontend/myRequests'),
        );
        return $result;
    }
}

