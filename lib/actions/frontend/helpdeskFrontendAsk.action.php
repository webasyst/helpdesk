<?php
/**
 * Public frontend for "main_form".
 */
class helpdeskFrontendAskAction extends helpdeskFrontendViewAction
{
    public function execute()
    {
        $main_form_id = waRequest::param('main_form_id', 0, 'int');
        if ($main_form_id) {

            $source = $this->source = new helpdeskSource($main_form_id);
            $st = $source->getSourceType();
            if ($source->status <= 0 || !$st instanceof helpdeskFormSTInterface) {
                throw new waException('Not found', 404);
            }
            $this->view->assign('source', $source);
            $this->view->assign('form_html', $st->getFormHtml($source));
            $this->view->assign('is_public_frontend', true);

            $this->setThemeTemplate('my.newrequest.html');
            $this->getResponse()->setTitle($source->name);

            parent::execute();

        } else {
            throw new waException('Empty form', 404);
        }
    }

}
