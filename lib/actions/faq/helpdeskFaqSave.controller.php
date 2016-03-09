<?php

class helpdeskFaqSaveController extends waJsonController
{
    public function execute()
    {
        if ($this->getRights('backend') <= 1) {
            throw new waRightsException(_w('Access denied.'));
        }

        $fm = new helpdeskFaqModel();

        $id = waRequest::post('id', null, waRequest::TYPE_INT);
        $category_id = waRequest::get('category_id', null, waRequest::TYPE_INT);
        $data = $this->getData();

        if (!empty($data['is_public'])) {
            if ($data['url']) {
                if (preg_match("/[^0-9a-z_\-]/i", $data['url'])) {
                    $this->setError(_w('Category URL may contain only letters, digits, and hyphens'), 'faq_url');
                    return false;
                }
                if ($fm->checkUrlUniq($data['url'], $category_id, $id)) {
                    $this->setError(_w('This URL is already used'), 'faq_url');
                    return false;
                }
            } else {
                $this->setError(_w('This field is required'), 'faq_url');
                return false;
            }
        }

        if (empty($data['question'])) {
            $this->setError(_w('This field is required'), 'question');
            return false;
        }

        if (!$id) {
            $id = $fm->add($data);
        } else {
            $fm->update($id, $data);
        }

        $faq = $fm->getById($id);
        $category = array();
        $fcm = new helpdeskFaqCategoryModel();
        if ($faq['faq_category_id']) {
            $category = $fcm->getById($faq['faq_category_id']);
        } else {
            $category = $fcm->getNoneCategory();
        }
        $this->response['faq'] = $faq;
        //$this->response['category'] = $category;
        $this->response['counters'] = $fcm->getCounters();

    }

    public function getData()
    {
        $data = array();
        $data['question'] = waRequest::post('question', '', waRequest::TYPE_STRING_TRIM);
        $data['answer'] = waRequest::post('answer', '', waRequest::TYPE_STRING_TRIM);
        $data['faq_category_id'] = (int) waRequest::request('category_id');
        $data['is_public'] = waRequest::request('is_public', 0, waRequest::TYPE_INT);
        $data['is_backend'] = waRequest::request('is_backend', 0, waRequest::TYPE_INT);
        $data['comment'] = waRequest::request('comment', null, waRequest::TYPE_STRING_TRIM);
        $data['url'] = waRequest::request('faq_url', '', waRequest::TYPE_STRING_TRIM);
        return $data;
    }
}

// EOF