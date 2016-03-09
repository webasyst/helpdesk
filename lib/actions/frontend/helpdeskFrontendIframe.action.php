<?php
/**
 * Form in the "iframe".
 */
class helpdeskFrontendIframeAction extends helpdeskFrontendViewAction
{
    public function execute($clear_assign = true)
    {
        $html = '';
        if ($id = waRequest::get('id', 0, 'int')) {
            $html = helpdeskHelper::form($id);
        }
        $this->view->assign('html', $html);

        header('P3P:CP="IDC DSP COR ADM DEVi TAIi PSA PSD IVAi IVDi CONi HIS OUR IND CNT"');
    }
}

