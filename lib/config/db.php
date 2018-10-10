<?php
// During app installation, database tables are created from this schema.
return array(

    // error messages from sources
    'helpdesk_error' => array(
        'id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'datetime' => array('datetime', 'null' => 0),
        'source_id' => array('bigint', 20, 'null' => 0),
        'message' => array('text', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'source_id' => 'source_id',
        ),
    ),

    'helpdesk_faq' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'faq_category_id' => array('int', 11, 'null' => 0),
        'question' => array('text', 'null' => 0),
        'answer' => array('text', 'null' => 0),
        'contact_id' => array('int', 11, 'null' => 0, 'default' => '0'),
        'create_datetime' => array('datetime', 'null' => 0),
        'sort' => array('int', 11, 'null' => 0, 'default' => '0'),
        'is_public' => array('tinyint', 1, 'null' => 0, 'default' => '0'),
        'is_backend' => array('tinyint', 1, 'null' => 0, 'default' => '0'),
        'url' => array('varchar', 255),
        'comment' => array('text'),
        ':keys' => array(
            'PRIMARY' => 'id',
        ),
    ),

    'helpdesk_faq_category' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'name' => array('varchar', 255, 'null' => 0),
        'icon' => array('varchar', 255),
        'sort' => array('int', 11, 'null' => 0, 'default' => '0'),
        'count' => array('int', 11, 'null' => 0, 'default' => '0'),
        'is_public' => array('tinyint', 1, 'null' => 0, 'default' => '0'),
        'is_backend' => array('tinyint', 1, 'null' => 0, 'default' => '0'),
        'url' => array('varchar', 255),
        'view_type' => array('varchar', 64, 'null' => 0, 'default' => 'separate'),
        ':keys' => array(
            'PRIMARY' => 'id',
        ),
    ),

    'helpdesk_faq_category_routes' => array(
        'category_id' => array('int', 11, 'null' => 0),
        'route' => array('varchar', 255, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('category_id', 'route')
        )
    ),

    // sidebar links for list views
    'helpdesk_filter' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'name' => array('varchar', 255, 'null' => 0),
        'hash' => array('text', 'null' => 0),
        'sort' => array('int', 11, 'null' => 0),
        'contact_id' => array('int', 11, 'null' => 0),
        'create_datetime' => array('datetime', 'null' => 0),
        'shared' => array('tinyint', 1, 'null' => 0),
        'icon' => array('varchar', 255, 'null' => 1),
        ':keys' => array(
            'PRIMARY' => 'id',
            'contact_id' => 'contact_id',
            'sort' => 'sort',
            'sort_2' => 'sort',
        ),
    ),

    'helpdesk_follow' => array(
        'contact_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        'request_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('contact_id', 'request_id'),
            'request_contact' => array('request_id', 'contact_id'),
        ),
    ),

    // user search history
    'helpdesk_history' => array(
        'id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'type' => array('varchar', 20, 'null' => 0),
        'name' => array('varchar', 255, 'null' => 0),
        'hash' => array('text', 'null' => 0),
        'contact_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'contact_id' => 'contact_id',
            'hash' => array('contact_id', array('hash', '24')),
        ),
    ),

    'helpdesk_page' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'parent_id' => array('int', 11),
        'domain' => array('varchar', 255),
        'route' => array('varchar', 255),
        'name' => array('varchar', 255, 'null' => 0),
        'title' => array('varchar', 255, 'null' => 0, 'default' => ''),
        'url' => array('varchar', 255),
        'full_url' => array('varchar', 255),
        'content' => array('mediumtext', 'null' => 0),
        'create_datetime' => array('datetime', 'null' => 0),
        'update_datetime' => array('datetime', 'null' => 0),
        'create_contact_id' => array('int', 11, 'null' => 0),
        'sort' => array('int', 11, 'null' => 0, 'default' => '0'),
        'status' => array('tinyint', 1, 'null' => 0, 'default' => '0'),
        ':keys' => array(
            'PRIMARY' => 'id',
            'routing' => array('route', 'status'),
        ),
    ),

    'helpdesk_page_params' => array(
        'page_id' => array('int', 11, 'null' => 0),
        'name' => array('varchar', 255, 'null' => 0),
        'value' => array('text', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('page_id', 'name'),
        ),
    ),

    // requests: main application entities
    'helpdesk_request' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'rating' => array('int', 11, 'null' => 0, 'default' => '0'),
        'workflow_id' => array('int', 11, 'null' => 1),
        'updated' => array('datetime', 'null' => 0),
        'closed' => array('datetime', 'null' => 1, 'default' => null),
        'creator_contact_id' => array('int', 11, 'null' => 0),
        'message_id' => array('varchar', 255, 'null' => 1, 'default' => null),
        'source_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        'creator_type' => array('varchar', 32, 'null' => 0),
        'created' => array('datetime', 'null' => 0),
        'state_id' => array('varchar', 64, 'null' => 0),
        'client_contact_id' => array('int', 10, 'unsigned' => 1, 'null' => 0),
        'assigned_contact_id' => array('bigint', 20, 'null' => 0),
        'summary' => array('varchar', 255, 'null' => 0),
        'text' => array('mediumtext', 'null' => 0),
        'last_log_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'message_id' => array('message_id', 'unique' => 1),
            'created' => 'created',
            'updated' => 'updated',
            'closed' => 'closed',
            'source_id' => 'source_id',
            'creator_contact_id' => 'creator_contact_id',
            'client_contact_id' => 'client_contact_id',
            'assigned_contact_id' => 'assigned_contact_id',
            'workflow_state' => array('state_id', 'workflow_id'),
            'workflow_id_assigned_contact_id' => array('workflow_id', 'assigned_contact_id'),
            'text_summary' => array('text', 'summary', 'fulltext' => 1),
        ),
    ),

    // history of actions with requests
    'helpdesk_request_log' => array(
        'id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'message_id' => array('varchar', 255),
        'request_id' => array('int', 11, 'null' => 0),
        'datetime' => array('datetime', 'null' => 0),
        'action_id' => array('varchar', 64, 'null' => 0),
        'actor_contact_id' => array('bigint', 20, 'null' => 0),
        'text' => array('mediumtext', 'null' => 0),
        'to' => array('text', 'null' => 0),
        'assigned_contact_id' => array('bigint', 20),
        'before_state_id' => array('varchar', 64, 'null' => 0),
        'after_state_id' => array('varchar', 64, 'null' => 0),
        'workflow_id' => array('int', 11, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'message_id' => array('message_id', 'unique' => 1),
            'request' => array('request_id', 'datetime'),
            'action_id' => 'action_id',
            'text' => array('text', 'fulltext' => 1),
        ),
    ),

    // key-value storage for request log records
    'helpdesk_request_log_params' => array(
        'request_log_id' => array('int', 11, 'null' => 0),
        'name' => array('varchar', 64, 'null' => 0),
        'value' => array('text', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('request_log_id', 'name'),
        ),
    ),

    // key-value storage for request records
    'helpdesk_request_params' => array(
        'request_id' => array('int', 11, 'null' => 0),
        'name' => array('varchar', 64, 'null' => 0),
        'value' => array('varchar', 255),
        'long_value' => array('text'),
        ':keys' => array(
            'PRIMARY' => array('request_id', 'name'),
            'name_value' => array('name', 'value'),
        ),
    ),

    // access control rights
    'helpdesk_rights' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'contact_id' => array('int', 11, 'null' => 0),
        'workflow_id' => array('int', 11, 'null' => 0),
        'action_id' => array('varchar', 64, 'null' => 1),
        'state_id' => array('varchar', 64, 'null' => 1),
        ':keys' => array(
            'PRIMARY' => 'id',
            'key' => array('contact_id', 'workflow_id', 'action_id', 'state_id'),
            'contact_id' => 'contact_id',
            'workflow_id' => 'workflow_id',
        ),
    ),

    // sources of requests: mailboxes, forms, etc.
    'helpdesk_source' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'type' => array('varchar', 32, 'null' => 0),
        'name' => array('varchar', 50, 'null' => 0),
        'status' => array('tinyint', 4, 'null' => 0, 'default' => '1'),
        ':keys' => array(
            'PRIMARY' => 'id',
        ),
    ),

    // key-value storage for source records
    'helpdesk_source_params' => array(
        'source_id' => array('int', 11, 'null' => 0),
        'name' => array('varchar', 64, 'null' => 0),
        'value' => array('text', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('source_id', 'name'),
        ),
    ),

    // request data is kept here during antispam validation period,
    // until they click confirmation link sent to them via email
    'helpdesk_temp' => array(
        'id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0, 'autoincrement' => 1),
        'created' => array('datetime', 'null' => 0),
        'data' => array('longblob', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'created' => 'created',
        ),
    ),

    // list of unread requests for each backend user
    'helpdesk_unread' => array(
        'contact_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        'request_id' => array('bigint', 20, 'unsigned' => 1, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('contact_id', 'request_id'),
            'request_contact' => array('request_id', 'contact_id'),
        ),
    ),

    'helpdesk_request_data' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'request_id' => array('int', 11, 'null' => 0),
        'field' => array('varchar', 255, 'null' => 0),
        'value' => array('varchar', 255, 'null' => 0),
        'status' => array('int', 11, 'null' => 0, 'default' => '0'),
        ':keys' => array(
            'PRIMARY' => 'id',
            'id_field_status' => array('request_id', 'field', 'status', 'unique' => 1)
        ),
    ),

    'helpdesk_tag' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'name' => array('varchar', 255, 'null' => 0),
        'count' => array('int', 11, 'null' => 0, 'default' => 0),
        ':keys' => array(
            'PRIMARY' => 'id'
        )
    ),

    'helpdesk_request_tags' => array(
        'request_id' => array('int', 11, 'null' => 0),
        'tag_id' => array('int', 11, 'null' => 0),
        ':keys' => array(
            'PRIMARY' => array('request_id', 'tag_id')
        )
    ),

    'helpdesk_messages_queue' => array(
        'id' => array('int', 11, 'null' => 0, 'autoincrement' => 1),
        'created' => array('datetime', 'null' => 0),
        'data' => array('longblob', 'null' => 0),
        ':keys' => array(
            'PRIMARY' => 'id',
            'created' => 'created',
        )
    ),

);
