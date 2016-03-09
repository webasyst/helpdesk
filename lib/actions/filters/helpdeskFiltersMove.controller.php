<?php
/**
 * Move filters
 */
class helpdeskFiltersMoveController extends helpdeskJsonController
{
    public function execute()
    {
        $id = (int) $this->getRequest()->post('id');
        $before_id = (int) $this->getRequest()->post('before_id');
        $m = new helpdeskFilterModel();
        if (!$m->move($id, $before_id)) {
            $this->errors[] = array(
                'Error occurs'
            );
        }
    }
}

