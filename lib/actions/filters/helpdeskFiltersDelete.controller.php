<?php
/**
 * Delete existing sidebar filter.
 */
class helpdeskFiltersDeleteController extends helpdeskJsonController
{
    public function execute()
    {
        if (! ( $id = waRequest::request('id', 0))) {
            throw new waException('No id given');
        }
        if (is_numeric($id)) {
            $f = new helpdeskFilter($id);

            // Non-admin can only delete own filters
            if ($f->contact_id != wa()->getUser()->getId() && $this->getRights('backend') <= 1) {
                throw new waRightsException(_w('Access denied.'));
            }

            $f->delete();
        } else if ($id === '@all' && wa()->getUser()->isAdmin()) {
            $asm = new waAppSettingsModel();
            $asm->set('helpdesk', 'all_requests_hide', 1);
        }
        $this->response = 'ok';
    }
}

