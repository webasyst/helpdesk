<?php
/**
 * Accepts new coordinates for workflow graph states.
 */
class helpdeskSettingsGraphposController extends helpdeskJsonController
{
    public function execute()
    {
        if (! ( $wf = waRequest::request('wf', 0, 'int'))) {
            throw new waException('No workflow id');
        }
        if (! ( $type = waRequest::request('type', null))) {
            throw new waException('No type');
        }

        if ($type == 'sources') {
            $source_ids = waRequest::request('sources');
            if ($source_ids) {
                $source_ids = explode(',', $source_ids);
            }
            helpdeskGraphPositionModel::saveSourcesOrder($wf, $source_ids);
        } else {
            $id = waRequest::request('id');
            $x = waRequest::request('x', 0, 'int');
            $y = waRequest::request('y', 0, 'int');
            helpdeskGraphPositionModel::savePosition($wf, $type, $id, $x, $y);
        }
        $this->response = 'ok';
    }
}

