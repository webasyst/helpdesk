<?php

/**
 * Full contact info block for backend request creation form.
 * See lib/sources/templates/backend.html
 */
class helpdeskBackendContactInfoAction extends helpdeskViewAction
{
    public function execute()
    {
        $id = waRequest::request('id');
        $contact = new waContact($id);
        
        $data_visible = array();
        $data_hidden = array();
        foreach(waContactFields::getAll('person') as $fld_id => $field) {
            $value = $contact->get($fld_id, 'html');
            if ($value && !in_array($fld_id, array('name', 'firstname', 'middlename', 'lastname', 'title', 'sex'))) {
                if (in_array($fld_id, array('email', 'phone'))) {
                    $data_visible[$field->getName()] = $value;
                } else {
                    $data_hidden[$field->getName()] = $value;
                }
            }
        }

        if (count($data_hidden) == 1 || count($data_visible) + count($data_hidden) < 5) {
            $data_visible += $data_hidden;
            $data_hidden = array();
        }

        $this->view->assign('data_visible', $data_visible);
        $this->view->assign('data_hidden', $data_hidden);
        $this->view->assign('contact', $contact);
        $this->view->assign('contact_email', $contact->get('email', 'default'));
    }
}