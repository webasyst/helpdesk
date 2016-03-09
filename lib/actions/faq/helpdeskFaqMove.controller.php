<?php

class helpdeskFaqMoveController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $id = (int) $this->getRequest()->post('id');
        $before_id = (int) $this->getRequest()->post('before_id');
        $category_id = (int) $this->getRequest()->post('category_id');

        $m = new helpdeskFaqModel();
        if ($this->getRequest()->request('to_category')) {
            if (!$m->moveToCategory($id, $category_id)) {
                $this->errors[] = array(
                    'Error occurs'
                );
            }
        } else {
            if (!$m->move($id, $before_id)) {
                $this->errors[] = array(
                    'Error occurs'
                );
            }
        }


    }
}

// EOF