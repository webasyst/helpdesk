<?php
/**
 * Email teplate editor.
 * Included in source and action settings pages.
 * Fetched via XHR to get a new empty copy
 * when source or action allows multiple messages.
 */
class helpdeskEditorEmailTemplateAction extends helpdeskViewAction
{
    public function execute()
    {
        $tpl = waRequest::get('tpl', 'action');

        $data = array(
            'input_name' => null,
            'variables' => null,
            'to_name' => null,
            'to_value' => null,
            'sourcefrom_name' => null,
            'sourcefrom_set' => null,
            'add_attachments_name' => null,
            'oneclick_feedback_fields' => helpdeskWorkflow::getOneClickFeedbackFields(true)
        );
        if ($tpl == 'source') {
            if (wa()->getLocale() == 'ru_RU') {
                 $data['template'] = '{SEPARATOR}Ваш запрос получен и поставлен в очередь на обработку{SEPARATOR}<p>Ваш запрос получен и поставлен в очередь на обработку.</p><p>Мы ответим вам в ближайшее время.</p><p>Спасибо!</p><p>--<br>Служба поддержки {COMPANY_NAME}</p><p>Это автоматическое уведомление о получении запроса. Пожалуйста, не отвечайте на это сообщение!</p><p>Используйте <a href="{REQUEST_CUSTOMER_PORTAL_URL}">ваш личный кабинет</a> для просмотра, повторного открытия или создания новых запросов.</p><p>Запрос №{REQUEST_ID} от {CUSTOMER_NAME}:</p><blockquote style="margin:0 0 0 .8ex;border-left:3px solid #cce;padding-left:1ex">Тема: {REQUEST_SUBJECT}<br>{REQUEST_TEXT}</blockquote><br>';
            } else {
                 $data['template'] = '{SEPARATOR}Your request has been received and queued into our support tracking system{SEPARATOR}<p>Your request has been received and queued into our support tracking system.</p><p>We shall reply to you as soon as possible.</p><p>Thank you!</p><p>--<br>Support Team<br>{COMPANY_NAME}</p><p>This is an automatic request receipt notification. Please do not reply to this message!</p><p>Use <a href="{REQUEST_CUSTOMER_PORTAL_URL}">your online account</a> to view, reopen, or create new requests.</p><p>Request #{REQUEST_ID} from {CUSTOMER_NAME}:</p><blockquote style="margin:0 0 0 .8ex;border-left:3px solid #cce;padding-left:1ex">Subject: {REQUEST_SUBJECT}<br>{REQUEST_TEXT}</blockquote><br>';
            }
            $data['to_value'] = 'client';
        } else if ($tpl === 'auto_action') {
            $data['template'] = '{SEPARATOR}Re: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}{ACTION_TEXT}';
        } else {
            $data['template'] = '{SEPARATOR}Re: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}';
        }
        $data = array_intersect_key(waRequest::request(), $data) + $data;

        if (waRequest::request('source_id')) {
            $data['source'] = new helpdeskSource(waRequest::request('source_id'));
        }

        $this->view->assign($data);
        $this->setTemplate('templates/actions/editor/email_template_editor.html');
    }
}
