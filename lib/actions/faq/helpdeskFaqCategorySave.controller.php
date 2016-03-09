<?php

class helpdeskFaqCategorySaveController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fcm = new helpdeskFaqCategoryModel();

        $data = $this->getData();
        $id = waRequest::request('id', null, waRequest::TYPE_INT);

        if (!empty($data['is_public'])) {
            if ($data['url']) {
                if (preg_match("/[^0-9a-z_\-]/i", $data['url'])) {
                    $this->setError(_w('Please specify a valid URL'), 'url');
                    return false;
                }
                if ($fcm->checkUrlUniq($data['url'], $id)) {
                    $this->setError(_w('This URL is already used'), 'url');
                    return false;
                }
            } else {
                $this->setError(_w('This field is required'), 'url');
                return false;
            }
        }

        if (empty($data['name'])) {
            $this->setError(_w('This field is required'), 'name');
            return false;
        }

        if (!$id) {
            $id = $fcm->add($data);
        } else {
            $fcm->update($id, $data);
        }
        if (waRequest::request('is_public_apply_all', null, waRequest::TYPE_INT)) {
            $fm = new helpdeskFaqModel();
            $fm->updateByField('faq_category_id', $id, array('is_public' => 1));
        }

        $this->response['category'] =  $fcm->getById($id);
    }

    public function getData()
    {
        $data = array();
        $data['name'] = waRequest::request('name', '', waRequest::TYPE_STRING_TRIM);
        $icon = waRequest::request('icon', null, waRequest::TYPE_STRING_TRIM);
        if ($icon) {
            $data['icon'] = $icon;
        }
        $data['is_public'] = waRequest::request('is_public', 0, waRequest::TYPE_INT);
        $data['is_backend'] = waRequest::request('is_backend', 0, waRequest::TYPE_INT);
        $data['url'] = waRequest::request('url', '', waRequest::TYPE_STRING_TRIM);
        $data['view_type'] = waRequest::request('view_type', null, waRequest::TYPE_STRING_TRIM);

        return $data;
    }
}

// EOF