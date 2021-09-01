<?php
/**
 * Collection of helper functions for sources.
 */
class helpdeskSourceHelper
{
    /**
     * Keys in $message array:
     * - source:
     *   - INPUT: helpdeskSource or source_id
     *   - OUTPUT: helpdeskSource
     * - client_contact: (optional)
     *   - INPUT: null or contact_id or waContact (existing or not yet saved to DB)
     *   - OUTPUT: null or waContact (existing or not yet saved to DB)
     * - creator_contact: (optional)
     *   - INPUT: null or contact_id or waContact (existing or not yet saved to DB)
     *   - OUTPUT: null or waContact (existing or not yet saved to DB)
     *   If not set, defaults to client_contact.
     * - request: (optional)
     *   - INPUT: null or request_id or helpdeskRequest. If set, the message will be processed as a response to this request.
     *   - OUTPUT: null or helpdeskRequest.
     * - summary: (optional, defaults to first 150 sumbols of text) short version of the text to show in list views
     * - text: message body
     * - message_id: (optional) unique message id for source's internal purposes (e.g. for email source to check
         which messages have already been fetched).
     * - rating: helpdesk_request.rating field
     *
     * - params: (optional) array of additional parameters to store in _params table, name => value
     *
     * - attachments: (optional) list of attached files, each represented by an array(
     *      'file' => full path,
     *      'name' => original filename (optional),
     *      'cid' => id to refer to this file inside message text (optional),
     *   )
     *
     * - assets: (optional) array of files to save along with this message (e.g. an .eml file) as basename => full path.
     *   Assets are saved in request or request log dir, depending on entity this message finally generates.
     *
     * @param array $message
     * @return array validated $message
     */
    public static function cleanMessage($message)
    {
        if(!is_array($message)) {
            $message = array();
        }

        // source
        if (empty($message['source'])) {
            throw new waException('No source.');
        }
        if (!$message['source'] instanceof helpdeskSource) {
            $message['source'] = new helpdeskSource($message['source']);
            $message['source']->load(); // this will throw exception right away if something's wrong with the source
        }

        // client_contact
        if (!empty($message['client_contact']) && !$message['client_contact'] instanceof waContact) {
            $message['client_contact'] = new waContact($message['client_contact']);
            $message['client_contact']->load(); // this will throw exception right away if something's wrong with the contact
        }
        if (empty($message['client_contact']) || !$message['client_contact'] instanceof waContact) {
            $message['client_contact'] = null;
        }
        // creator_contact
        if (!empty($message['creator_contact']) && !$message['creator_contact'] instanceof waContact) {
            $message['creator_contact'] = new waContact($message['creator_contact']);
            $message['creator_contact']->load(); // this will throw exception right away if something's wrong with the contact
        } else if (empty($message['creator_contact'])) {
            $message['creator_contact'] = $message['client_contact'];
        }
        if (empty($message['creator_contact']) || !$message['creator_contact'] instanceof waContact) {
            $message['creator_contact'] = null;
        }

        // default values for some parameters
        foreach(array(
            'text' => '',
            'rating' => 0,
            'params' => array(),
            'assets' => array(),
            'message_id' => null,
            'attachments' => array(),
        ) as $k => $default) {
            if (empty($message[$k])) {
                $message[$k] = $default;
            }
        }

        // creator type
        if (!in_array(ifset($message['creator_type'], ''), array('backend', 'auth', 'public'))) {
            $message['creator_type'] = null;
        }
        if (empty($message['creator_type'])) {
            if (wa()->getEnv() == 'backend') {
                $message['creator_type'] = 'backend';
            } else {
                $message['creator_type'] = wa()->getUser()->isAuth() ? 'auth' : 'public';
            }
        }

        // truncate text into summary
        if (empty($message['summary']) && !empty($message['text'])) {
            $t = strip_tags($message['text']);
            $t = preg_replace('/\s*&[^&]+;\s*/', '', $t);
            $message['summary'] = mb_substr($t, 0, 148);
            if(mb_strlen($t) > 148) {
                $message['summary'] .= '...';
            }
        }

        // make sure the request is either null or an instance of helpdeskRequest
        if (empty($message['request'])) {
            $message['request'] = null;
        }
        if (wa_is_int($message['request'])) {
            $message['request'] = new helpdeskRequest($message['request']);
        } else if (!empty($message['request']) && !$message['request'] instanceof helpdeskRequest) {
            throw new waException('Bad request: '.print_r($message['request'], true));
        }

        return $message;
    }

    public static function createRequest($message)
    {
        $r = new helpdeskRequest();
        $r->source_id = $message['source']->getId();
        $r['last_log_id'] = 0;
        foreach (array('summary','text','message_id','rating','params','attachments','assets', 'workflow_id', 'state_id') as $key) {
            if (isset($message[$key])) {
                $r[$key] = $message[$key];
            }
        }
        return $r;
    }

    public static function isBackendSourceAvailable()
    {
        static $res;
        if ($res === null) {
            $res = true;
            $source = helpdeskSource::getBackendSource();
            $helpdesk_backend_rights = wa()->getUser()->getRights('helpdesk', 'backend');
            if (!$helpdesk_backend_rights) {
                $res = false;
            } else if ($helpdesk_backend_rights <= 1) {
                $rm = new helpdeskRightsModel();
                $wf_create = $rm->getWorkflowsCreationRights();
                if (!$source->params->new_request_state_id) {
                    $res = false;
                } else {
                    $wf_with_state = explode('@', $source->params->new_request_state_id, 2);
                    $workflow_id = $wf_with_state[0];
                    if (empty($wf_create[$workflow_id])) {
                        $res = false;
                    }
                }
            }
        }
        return $res;
    }
}

