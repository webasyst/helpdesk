<?php

class helpdeskFrontendPageAction extends waPageAction
{
    public function execute()
    {
        $this->setLayout(new helpdeskFrontendLayout());

        $fcm = new helpdeskFaqCategoryModel();
        $this->layout->assign('categories', $fcm->getAll(null, false, true));

        parent::execute();
    }
}
