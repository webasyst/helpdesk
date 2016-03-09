<?php
/**
 * Delete given source.
 */
class helpdeskSourcesDeleteController extends helpdeskJsonController
{
    public function execute()
    {
        // only allowed to admin
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        if (! ( $id = waRequest::post('id', 0, 'int'))) {
            throw new waException('No id given.');
        }

        $rm = new helpdeskRequestModel();
        $sm = new helpdeskSourceModel();

        // If there are no requests from this source, delete permanently
        if (!$rm->select('id')->where('source_id=?', $id)->limit(1)->fetchField()) {
            $sm->delete($id);
            return;
        }

        // Otherwise, when requests from this source exist, archive the source.
        $sm->updateById($id, array(
            'status' => -1,
        ));
    }
}

