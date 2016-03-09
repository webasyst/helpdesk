<?php

class helpdeskOneClickFeedback
{
    const REQUEST_LOG_ACTION_ID = '!1click_feedback';

    public function getFields(helpdeskRequest $request = null, $keys = null) {
        if ($request === null) {
            $fields = helpdeskWorkflow::getOneClickFeedbackFields(true);
        } else {
            $fields = array();
            foreach (helpdeskWorkflow::getOneClickFeedbackFields(true) as $key => $info) {
                $info['hash'] = self::generateHash($request, $info['field_id']);;
                $fields[$key] = $info;
            }
        }
        if ($keys !== null) {
            foreach ($fields as $field_id => &$info) {
                if (is_string($keys)) {
                    $info = ifset($info[$keys], '');
                } else {
                    $slice = array();
                    foreach ($keys as $key) {
                        $slice[$key] = ifset($info[$key], '');
                    }
                    $info = $slice;
                }
            }
            unset($info);
        }
        return $fields;
    }

    public static function getRequestLogActionName()
    {
        return _w('1-Click Feedback');
    }

    public static function getAction($workflow_id)
    {
        return new helpdeskWorkflowBasicAction(self::REQUEST_LOG_ACTION_ID, helpdeskWorkflow::getWorkflow($workflow_id), array(
            'client_visible' => 1,
            'client_triggerable' => 1,
            'name' => self::getRequestLogActionName()
        ));
    }

    public static function generateHash(helpdeskRequest $request, $field_id)
    {
        $hash = md5($field_id . md5($request->created));
        $hash = substr($hash, 0, 16) . $request->id . substr($hash, 16);
        return $hash;
    }

    public function getMatchedFields($text)
    {
        $match = array();
        $vars = $this->getVarsMatch();

        foreach ($vars as $field_id => $group) {
            foreach ($group as $search => $replace) {
                if (strpos($text, $search) !== false) {
                    $match[] = $field_id;
                    break;
                }
            }
        }
        return $match;
    }

    private function getVarsMatch(helpdeskRequest $request = null)
    {
        $one_click_vars = array();
        foreach ($this->getFields($request) as $key => $info) {
            $prefix = preg_quote(helpdeskWorkflow::PREFIX_ONE_CLICK_FEEDBACK_HREF);
            $pattern = "/{{$prefix}(.*?):(.*?)}/";
            if (preg_match_all($pattern, $info['html'], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $one_click_vars[$key][$match[0]] = array(
                        'match' => array_slice($match, 1),
                        'hash' => ifset($info['hash'], '')
                    );
                }
            }
        }
        return $one_click_vars;
    }

    private function flatVarsArray($vars_array) {
        $flat_res = array();
        foreach ($vars_array as $key => $info) {
            $flat_res += $info;
        }
        return $flat_res;
    }

    public function getVarsForNotClient(helpdeskRequest $request)
    {
        $request_prefix = helpdeskRequestDataModel::PREFIX_REQUEST;

        $one_click_vars = $this->getVarsMatch();
        foreach ($one_click_vars as $field_id => &$group) {
            foreach ($group as $key => &$info) {
                $controller_url = wa()->getRouteUrl('helpdesk/frontend/actionFormByHash', array(
                    'hash' => $hash = self::generateHash($request, uniqid($info['match'][0]))
                ), true);
                $info = "{$controller_url}?" . urldecode("field[{$request_prefix}{$info['match'][0]}]={$info['match'][1]}");
            }
            unset($info);
        }
        unset($group);

        return $this->flatVarsArray($one_click_vars);
    }

    public function getVarsForClient(helpdeskRequest $request, $flat = true)
    {
        $request_prefix = helpdeskRequestDataModel::PREFIX_REQUEST;

        $one_click_vars = $this->getVarsMatch($request);
        foreach ($one_click_vars as $field_id => &$group) {
            foreach ($group as $key => &$info) {
                $controller_url = wa()->getRouteUrl('helpdesk/frontend/actionFormByHash', array(
                    'hash' => $info['hash']
                ), true);
                $info = "{$controller_url}?" . urldecode("field[{$request_prefix}{$info['match'][0]}]={$info['match'][1]}");
            }
            unset($info);
        }

        return $flat ? $this->flatVarsArray($one_click_vars) : $one_click_vars;
    }

}