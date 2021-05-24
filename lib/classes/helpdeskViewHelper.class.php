<?php
/**
 * Instance of this is available as {$wa->helpdesk} in all templates
 * everywhere in webasyst apps.
 */
class helpdeskViewHelper extends waAppViewHelper
{
    /**
     * @param int $id - ID of form
     * @param array $form_params
     *      array $form_params['data']      [optional] - prefill request data in form
     *      array $form_params['contact']   [optional] - prefill contact data in form
     * @return string
     * @throws waException
     */
    public function form($id, $form_params = [])
    {
        $app_id = 'helpdesk';
        if (wa()->getRouting()->getByApp($app_id, wa()->getRouting()->getDomain())) {

            $extra_html = array(
                'bottom' => ''
            );

            $form_params = is_array($form_params) ? $form_params : [];

            $is_proper_type = isset($form_params['data']) && is_array($form_params['data']);
            if (!$is_proper_type) {
                $form_params['data'] = [];
            }

            $is_proper_type = isset($form_params['contact']) && (is_array($form_params['contact']) || $form_params['contact'] instanceof waContact);
            if (!$is_proper_type) {
                $form_params['contact'] = [];
            }

            $fields = helpdeskHelper::getFormFields($id);

            $params = [];

            foreach ($form_params['data'] as $field_id => $field_val) {
                if (isset($fields[$field_id])) {
                    $params['fld_' . $field_id] = ['value' => $field_val];
                } else if (is_scalar($field_val)) {
                    $extra_html['bottom'] .= "<input type='hidden' name='fld_data[{$field_id}]' value='{$field_val}'>";
                }
            }
            
            $contact_fields = helpdeskHelper::getFormContactFields($id);
            foreach ($form_params['contact'] as $field_id => $field_val) {
                if (isset($contact_fields[$field_id])) {
                    $params['fldc_' . $field_id] = ['value' => $field_val];
                }
            }

            return helpdeskHelper::form($id, $params, 1, $extra_html);
        } else {
            waLocale::loadByDomain($app_id);
            $msg = _wd($app_id, 'Routing rules are not defined for Helpdesk app');
            return '<p class="errormsg h-routing-error">' . $msg . '</p>';
        }
    }

    public function getAppStaticUrl()
    {
        return wa()->getAppStaticUrl(wa()->getApp(), true);
    }

    public function faqCategories()
    {
        $fcm = new helpdeskFaqCategoryModel();
        return $fcm->getAll(null, false, true);
    }

}

