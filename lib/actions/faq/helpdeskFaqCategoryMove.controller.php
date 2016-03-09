<?php

class helpdeskFaqCategoryMoveController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $id = (int) $this->getRequest()->post('id');
        $before_id = (int) $this->getRequest()->post('before_id');
        $m = new helpdeskFaqCategoryModel();
        if (!$m->move($id, $before_id)) {
            $this->errors[] = array(
                'Error occurs'
            );
        }
    }
}

// EOF