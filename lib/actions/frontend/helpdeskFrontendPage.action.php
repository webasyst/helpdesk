<?php

class helpdeskFrontendPageAction extends waPageAction
{
    public function execute()
    {
        $this->setLayout(new helpdeskFrontendLayout());

        $fcm = new helpdeskFaqCategoryModel();
        $this->layout->assign('categories', $fcm->getList('', array(
            'is_public' => true,
            'routes' => array($this->getCurrentRoute())
        )));

        parent::execute();
    }

    /**
     * @return string
     */
    public function getCurrentRoute()
    {
        $current_route = wa()->getRouting()->getDomain(null, true).'/'.wa()->getRouting()->getRoute('url');
        return trim(rtrim($current_route, '*/'));
    }
}
