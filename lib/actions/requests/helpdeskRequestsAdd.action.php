<?php


class helpdeskRequestsAddAction extends helpdeskViewAction
{
    public function execute()
    {
        $form_id = waRequest::request('form_id');
        $source = $this->getSource($form_id);
        $st = $source->getSourceType();

        $helpdesk_backend_rights = $this->getUser()->getRights('helpdesk', 'backend');

        $this->view->assign(array(
            'form_id' => $form_id,
            'form_html' => $st->getFormHtml($source),
            'source' => $source,
            'source_type' => $st,
            'settings_html' => $this->getSettings($source),
            'helpdesk_backend_rights' => $helpdesk_backend_rights
        ));
    }

    /**
     *
     * @throws waException
     * @param int $form_id
     * @return helpdeskSource
     */
    public function getSource($form_id)
    {
        $source = helpdeskSource::get($form_id);
        if (!$source->getSourceType() instanceof helpdeskFormSTInterface) {
            throw new waException('Form not found.', 404);
        }
        if ($form_id === 'backend') {
            if (!helpdeskSourceHelper::isBackendSourceAvailable()) {
                throw new waRightsException(_w('Access denied.'));
            }
        } else {
            foreach ($source->describeBehaviour() as $wf_id => $b) {
                if (!helpdeskRightsModel::isAllowed($wf_id, '!create')) {
                    throw new waRightsException(_w('Access denied.'));
                }
            }
        }
        return $source;
    }

    public function getSettings(helpdeskSource $source)
    {
        $settings_html = '';
        $st = $source->getSourceType();
        if ($st instanceof helpdeskBackendSourceType) {
            try {
                $settings_html = $st->settingsController(null, $source);
            } catch (waRightsException $e) {}
        }
        return $settings_html;
    }

}

