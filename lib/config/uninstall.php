<?php

wa('helpdesk')->event('before_uninstall');
waFiles::delete(wa()->getConfigPath('helpdesk'));

