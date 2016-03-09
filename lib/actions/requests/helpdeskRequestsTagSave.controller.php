<?php


class helpdeskRequestsTagSaveController extends helpdeskJsonController
{
    public function execute()
    {
        $request_id = waRequest::request('request_id', null, waRequest::TYPE_INT);
        if ($request_id) {
            $r = new helpdeskRequest($request_id);
            if ($r) {
                $rm = new helpdeskRightsModel();
                $rights = $rm->getWorkflowsCreateTagRights();
                $tags = array_map('trim', (array) waRequest::request('tag', array()));
                $tag_model = new helpdeskTagModel();
                $ids = $tag_model->getIds($tags, !empty($rights[$r['workflow_id']]));
                $rtm = new helpdeskRequestTagsModel();
                if (waRequest::request('set')) {
                    $rtm->set($request_id, $ids);
                } else {
                    $rtm->assign($request_id, $ids);
                }
            }
        }
    }
}

