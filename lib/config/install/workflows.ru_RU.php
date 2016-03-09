<?php
/* Pre-installed workflow settings. */
return array (
  'states' =>
  array (
  ),
  'actions' =>
  array (
  ),
  'workflows' =>
  array (
    1 =>
    array (
      'id' => 1,
      'name' => 'Поток',
      'classname' => 'helpdeskWorkflow',
      'states' =>
      array (
        'new' =>
        array (
          'classname' => 'helpdeskWorkflowState',
          'available_actions' =>
          array (
            0 => 'reply',
            1 => 'reply_close',
            2 => 'discuss',
            3 => 'comment',
            4 => 'delete',
          ),
          'options' =>
          array (
            'name' => 'Новый',
            'list_row_css' => 'color:#006600;',
            'closed_state' => false,
            'customer_portal_name' => 'Новый',
          ),
        ),
        'processing' =>
        array (
          'classname' => 'helpdeskWorkflowState',
          'available_actions' =>
          array (
            0 => 'reply',
            1 => 'discuss',
            2 => 'comment',
            3 => 'reply_close',
            4 => 'delete',
          ),
          'options' =>
          array (
            'name' => 'Открыт',
            'list_row_css' => 'color:#3366ff;',
            'closed_state' => false,
            'customer_portal_name' => 'В работе',
          ),
        ),
        'archive' =>
        array (
          'classname' => 'helpdeskWorkflowState',
          'available_actions' =>
          array (
            0 => 'reopen',
          ),
          'options' =>
          array (
            'name' => 'Закрыт',
            'list_row_css' => 'color:#737373;',
            'closed_state' => false,
            'customer_portal_name' => 'Закрыт',
          ),
        ),
        'deleted' =>
        array (
          'classname' => 'helpdeskWorkflowState',
          'available_actions' =>
          array (
            0 => 'reopen',
          ),
          'options' =>
          array (
            'name' => 'Удален',
            'list_row_css' => 'color:#a1a1a1;',
            'closed_state' => false,
          ),
        ),
      ),
      'actions' =>
      array (
        'reply' =>
        array (
          'options' =>
          array (
            'name' => 'Ответить',
            'show_textarea' => '1',
            'textarea_default_text' => '
--
С уважением, {ACTOR_NAME}.
Служба поддержки {COMPANY_NAME}',
            'allow_attachments' => '1',
            'default_assignee' => '0',
            'user_button_border_color' => '#5b9c0a',
            'messages' =>
            array (
              0 =>
              array (
                'sourcefrom' => true,
                'tmpl' => '{SEPARATOR}Re: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}<div>{ACTION_TEXT}</div>
<p>Используйте <a href="{REQUEST_CUSTOMER_PORTAL_URL}">ваш личный кабинет</a> для просмотра, повторного открытия или создания новых запросов.</p>
<p>Запрос №{REQUEST_ID} от {CUSTOMER_NAME}:</p>
<blockquote style="margin:0 0 0 .8ex;border-left:3px solid #cce;padding-left:1ex">
{REQUEST_HISTORY_CUSTOMER}
</blockquote><br>',
                'to' =>
                array (
                  'client' => '1',
                ),
                'add_attachments' => '1',
              ),
            ),
            'user_button_value' => 'Ответить',
            'user_form_button_value' => 'Ответить',
            'client_visible' => 'all',
          ),
          'transition' => 'processing',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
        'comment' =>
        array (
          'options' =>
          array (
            'name' => 'Добавить комментарий',
            'show_textarea' => '1',
            'textarea_default_text' => '',
            'allow_attachments' => '1',
            'default_assignee' => '',
            'user_button_border_color' => '#42a7f5',
            'user_button_value' => 'Добавить комментарий',
            'user_form_button_value' => 'Добавить комментарий',
            'client_triggerable' => 1,
            'client_visible' => 'own',
            'messages' =>
            array (
            ),
          ),
          'transition' => 'processing',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
        'discuss' =>
        array (
          'options' =>
          array (
            'name' => 'Обсудить',
            'show_textarea' => '1',
            'textarea_default_text' => '
--
{ACTOR_NAME}',
            'assignment' => '1',
            'default_assignee' => '',
            'allow_choose_assign' => '1',
            'allow_attachments' => '1',
            'use_state_border_color' => '1',
            'user_button_border_color' => 'rgb(51, 102, 255)',
            'messages' =>
            array (
              0 =>
              array (
                'sourcefrom' => true,
                'tmpl' => '{SEPARATOR}Fwd: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}<div>{ACTION_TEXT}</div>
<p><a href="{REQUEST_BACKEND_URL}">{REQUEST_BACKEND_URL}</a></p>',
                'to' =>
                array (
                  'assignee' => '1',
                ),
                'add_attachments' => '1',
              ),
            ),
            'user_button_value' => 'Обсудить',
            'user_form_button_value' => 'Обсудить',
          ),
          'transition' => 'processing',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
        'reopen' =>
        array (
          'options' =>
          array (
            'name' => 'Открыть повторно',
            'show_textarea' => '1',
            'textarea_default_text' => '',
            'assignment' => '1',
            'default_assignee' => '',
            'allow_choose_assign' => '1',
            'use_state_border_color' => '1',
            'user_button_border_color' => 'rgb(51, 102, 255)',
            'user_button_value' => 'Открыть повторно',
            'user_form_button_value' => 'Открыть повторно',
            'client_triggerable' => 1,
            'client_visible' => 'own',
            'messages' =>
            array (
            ),
          ),
          'transition' => 'processing',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
        'delete' =>
        array (
          'options' =>
          array (
            'name' => 'Удалить',
            'textarea_default_text' => '',
            'default_assignee' => '0',
            'user_button_border_color' => '#cc0000',
            'user_button_value' => 'Удалить',
            'user_form_button_value' => 'Удалить',
            'messages' =>
            array (
            ),
            'client_triggerable' => 1,
            'client_visible' => 'own',
          ),
          'transition' => 'deleted',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
        'reply_close' =>
        array (
          'options' =>
          array (
            'name' => 'Ответить и закрыть',
            'show_textarea' => '1',
            'textarea_default_text' => '',
            'allow_attachments' => '1',
            'default_assignee' => '',
            'user_button_border_color' => '#006600',
            'messages' =>
            array (
              0 =>
              array (
                'sourcefrom' => true,
                'tmpl' => '{SEPARATOR}Re: {REQUEST_SUBJECT_WITH_ID}{SEPARATOR}<div>{ACTION_TEXT}</div>
<p>Используйте <a href="{REQUEST_CUSTOMER_PORTAL_URL}">ваш личный кабинет</a> для просмотра, повторного открытия или создания новых запросов.</p>
<p>Запрос №{REQUEST_ID} от {CUSTOMER_NAME}:</p>
<blockquote style="margin:0 0 0 .8ex;border-left:3px solid #cce;padding-left:1ex">
{REQUEST_HISTORY_CUSTOMER}
</blockquote><br>',
                'to' =>
                array (
                  'client' => '1',
                ),
                'add_attachments' => '1',
              ),
            ),
            'user_button_value' => 'Ответить и закрыть',
            'user_form_button_value' => 'Ответить и закрыть',
            'client_visible' => 'all',
          ),
          'transition' => 'archive',
          'classname' => 'helpdeskWorkflowBasicAction',
        ),
      ),
    ),
  ),
);
