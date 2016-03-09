<?php
/**
 * Instance of this is available as {$wa->helpdesk} in all templates
 * everywhere in webasyst apps.
 */
class helpdeskViewHelper extends waAppViewHelper
{
    public function form($id, $form_params = array())
    {
        $app_id = 'helpdesk';
        if (wa()->getRouting()->getByApp($app_id, wa()->getRouting()->getDomain())) {

            $extra_html = array(
                'bottom' => ''
            );

            $fields = helpdeskHelper::getFormFields($id);
            $params = array();
            foreach (ifset($form_params['data'], array()) as $field_id => $field_val) {
                if (isset($fields[$field_id])) {
                    $params['fld_' . $field_id] = array('value' => $field_val);
                } else if (is_string($field_val)) {
                    $extra_html['bottom'] .= "<input type='hidden' name='fld_data[{$field_id}]' value='{$field_val}'>";
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

