<?php
/**
 * Performs frontend-enabled workflow actions from customer portal
 */
class helpdeskFrontendActionFormByHashController extends helpdeskFrontendActionFormController
{
    public function execute()
    {
        try {
            $hash = waRequest::param('hash', '', waRequest::TYPE_STRING_TRIM);
            if (!$hash) {
                throw new waException('Wrong hash');
            }
            $action_id = helpdeskOneClickFeedback::REQUEST_LOG_ACTION_ID;

            $fields = (array) waRequest::get('field');
            if (empty($fields)) {
                throw new waException("Empty fields");
            }

            $request_id = substr($hash, 16, -16);
            $request = new helpdeskRequest($request_id);
            if (!$request->exists()) {
                throw new waException("Request doesn't exist");
            }
            $wf = $request->getWorkflow();
            if (empty($request['client_contact_id'])) {
                throw new waException("Client ID is empty");
            }
            $client = new waUser($request['client_contact_id']);
            if (!$client->exists()) {
                throw new waException("Client doesn't exist");
            }


            $request_fields = helpdeskRequestDataModel::filterByType($fields, helpdeskRequestLogParamsModel::TYPE_REQUEST, true);
            if (empty($request_fields)) {
                throw new waException("No request field(s)");
            }

            $params = array();

            $request_field_id = '';

            foreach ($request_fields as $field_id => $value) {
                $field = helpdeskRequestFields::getField($field_id);
                if (!$field) {
                    continue;
                }
                if (!($field instanceof helpdeskRequestCheckboxField) && !($field instanceof helpdeskRequestSelectField)) {
                    continue;
                }
                if ($field instanceof helpdeskRequestCheckboxField && !trim($value)) {
                    continue;
                }
                if ($field instanceof helpdeskRequestSelectField) {
                    $options = $field->getOptions();
                    if (!isset($options[$value])) {
                        throw new waException("Unknown select value");
                    }
                }
                $fields[helpdeskRequestLogParamsModel::PREFIX_REQUEST . $field_id] = $value;
                $request_field_id = $field_id;
            }

            $rdm = new helpdeskRequestDataModel();
            $res = $rdm->getByOneClickFeedbackField(array(
                'request_id' => $request_id,
                'field' => $request_field_id
            ));
            if (!$res || $res['value'] !== $hash) {
                $this->redirectToMyRequestPage($request_id);
            }

            $params['request'] = $request;
            $_POST['params'] = $params;
            $_POST['field'] = $fields;

            wa()->setUser($client);
            wa()->getAuth()->updateAuth($client);
            $rdm->deleteById($res['id']);
            $this->processAction($wf->getId(), $action_id, $params, $client, false);
            $this->redirectToMyRequestPage($request_id);
        } catch (Exception $e) {
            throw new waException(_w('This action is not available') . ": " . $e->getMessage(), 404);
        }
    }

    public function redirectToMyRequestPage($request_id)
    {
        $this->redirect(array(
            'url' => wa()->getRouteUrl('helpdesk/frontend/myRequest', array(
                'id' => $request_id
            )),
        ));
    }

}

