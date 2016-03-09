<?php

class helpdeskFaqLayoutAction extends waViewAction
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }
        $fm = new helpdeskFaqModel();
        $fcm = new helpdeskFaqCategoryModel();
        $this->view->assign(array(
            'categories' => $fcm->getAll(),
            'none_category' => $fcm->getNoneCategory(),
            'faq_count' => $fm->countAll()
        ));
    }
}

// EOF
