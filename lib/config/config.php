<?php

return array(

     // paginator in requests list. Values: page, range
    'paginator_type' => 'range',

    // Max count of emails sent at once
    'messages_queue_send_max_count' => 250,

    // Max size of queue of emails messages. Oldest messages will be removed from queue
    'messages_queue_max_size' => 100000,

    // Turn on or turn off loging email cron job
    'email_cron_job_logs' => false

);

