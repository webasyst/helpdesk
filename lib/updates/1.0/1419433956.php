<?php


foreach (array(
    'lib/actions/requests/constructor/helpdeskRequestsConstructorSave.controller.php',
    'lib/actions/constructor/helpdeskConstructorSave.controller.php',
    'lib/actions/constructor/helpdeskConstructorDelete.controller.php',
    'lib/actions/constructor/helpdeskConstructorDeleteConfirm.action.php',
    'templates/actions/requests/ConstructorDeleteConfirm.html',
    'templates/actions/requests/RequestsConstructor.html',
    'lib/actions/requests/constructor/helpdeskRequestsConstructor.action.php',
    'lib/actions/requests/constructor/helpdeskRequestsConstructorSave.controller.php',
    'lib/actions/sources/helpdeskSourcesFormPreview.controller.php',
    'lib/actions/frontend/helpdeskFrontendFormPreview.action.php',
    'templates/actions/frontend/FrontendFormPreview.html',
    'lib/actions/requests/constructor'
) as $_file)
{
    $_file_path = wa('helpdesk')->getAppPath(). '/' . $_file;
    if (file_exists($_file_path)) {
        waFiles::delete($_file_path);
    }
}
