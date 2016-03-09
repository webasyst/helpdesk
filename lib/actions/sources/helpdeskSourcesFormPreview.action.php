<?php
/**
 * Form preview to show in iframe in backend.
 */
class helpdeskSourcesFormPreviewAction extends waViewAction
{
    public function execute()
    {
        $html = '';
        $id = waRequest::get('id', 0, 'int');
        if (waRequest::post()) {
            $html = helpdeskHelper::form($id, array(
                'custom_css' => waRequest::post('css'),
                'env' => 'frontend'
            ));
            $this->view->assign('html', $html);
        } else {
            $html = helpdeskHelper::form($id, array(
                'env' => 'frontend'
            ));
            $this->view->assign('html', $html);
        }
    }
}

