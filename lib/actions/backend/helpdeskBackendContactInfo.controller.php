<?php


class helpdeskBackendContactInfoController extends waController
{
    public function execute()
    {
        echo waRequest::request('json') ?
                $this->jsonExecute() :
                $this->htmlExecute();
        exit;
    }
    
    public function jsonExecute()
    {
        $fields = waRequest::post('fields');
        if (empty($fields)) {
            $fields = array('name');
        }
        $fields[] = 'id';
        
        $result = array();
        
        $id = waRequest::request('id');
        if ($id) {
            $contact = new waContact($id);
            if ($contact->exists()) {
                $result['contact'] = array();
                foreach ($fields as $field_id) {
                    $result['contact'][$field_id] = $contact->get($field_id, 'js');
                }
                
            }
        }
        
        return json_encode(array(
            'status' => 'ok',
            'data' => $result
        ));
        
    }
    
    public function htmlExecute()
    {
        return wao(new helpdeskBackendContactInfoAction())->display();
    }
    
}