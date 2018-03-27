<?php

// Frontend routing for the app.
// URLs are relative to the main app rule in wa-config/routing.php

// Default routing: with public frontend and customer portal
$routes_with_public_frontend = array(
    // Fetch action form and/or perform action from frontend
    'perform' => array(
        'url' => 'perform/<workflow_id:\d+>/<action_id:[a-z0-9_-]+>/<params>/',
        'module' => 'frontend',
        'action' => 'actionForm',
        'secure' => false,
    ),

    'perform_by_hash' => array(
        'url' => 'perform/<hash:[a-z0-9]+>/',
        'module' => 'frontend',
        'action' => 'actionFormByHash',
        'secure' => false,
    ),

    // Accept submit from frontend forms (e.g. to create new request)
    'form' => array(
        'url' => 'form/',
        'module' => 'frontend',
        'action' => 'form',
        'secure' => false,
    ),

    // Background form process
    'form_background' => array(
        'url' => 'form_background/',
        'module' => 'frontend',
        'action' => 'formBackground',
        'secure' => false,
    ),

    // Request list in customer portal
    'portal_requests' => array(
        'url' => 'my/',
        'module' => 'frontend',
        'action' => 'myRequests',
        'secure' => true,
    ),

    // Single request page in customer portal
    'portal_request' => array(
        'url' => 'my/request/<id:\d+>/',
        'module' => 'frontend',
        'action' => 'myRequest',
        'secure' => true,
    ),

    // Form to create new requests
    'portal_form' => array(
        'url' => 'my/new/<id:\d+>/',
        'module' => 'frontend',
        'action' => 'myNew',
        'secure' => true,
    ),

    // Profile editor
    'portal_attachment' => array(
        'url' => 'my/attachment/',
        'module' => 'frontend',
        'action' => 'myAttachment',
        'secure' => true,
    ),

    // Profile editor
    'portal_profile' => array(
        'url' => 'my/profile/',
        'module' => 'frontend',
        'action' => 'myProfile',
        'secure' => true,
    ),

    // Frontend home
    'root' => array(
        'url' => '',
        'module' => 'frontend',
        'action' => '',
    ),

    'data_regions' => array(
        'url' => 'data/regions/',
        'module' => 'frontend',
        'action' => 'regions',
        'secure' => false,
    ),

    'upload_image' => array(
        'url' => 'upload/image',
        'module' => 'frontend',
        'action' => 'uploadImage',
        'secure' => false,
    ),

    'iframe' => array(
        'url' => 'iframe/',
        'module' => 'frontend',
        'action' => 'iframe',
        'secure' => false,
    ),

    // Antispam confirmation (via email)
    'confirm' => array(
        'url' => 'confirm/',
        'module' => 'frontend',
        'action' => 'confirm',
        'secure' => false,
    ),

    // Faq category list page
    'faq_category_list' => array(
        'url' => 'faq/',
        'module' => 'frontend',
        'action' => 'faq',
        'secure' => false,
    ),

    // A faq category page
    'faq_category_question' => array(
        'url' => 'faq/<category>/<question>/',
        'module' => 'frontend',
        'action' => 'faqCategoryQuestion',
        'secure' => false,
    ),

    // A faq category page
    'faq_category' => array(
        'url' => 'faq/<category>/',
        'module' => 'frontend',
        'action' => 'faqCategory',
        'secure' => false,
    ),

    'search' => array(
        'url' => 'search/*',
        'module' => 'frontend',
        'action' => 'search',
        'secure' => false,
    ),

    'ask' => array(
        'url' => 'ask/*',
        'module' => 'frontend',
        'action' => 'ask',
        'secure' => false,
    ),

    // This will trigger an error since there's no such class
    '*' => 'routeNotFound',

);

// Second routing option: no public frontend, customer portal only
$routes_customer_portal_only = $routes_with_public_frontend;
unset($routes_customer_portal_only['portal_requests']);
$routes_customer_portal_only['root'] = array(
    'url' => 'my/',
    'module' => 'frontend',
    'action' => 'myRequests',
    'secure' => true
);

return array(
    0 => $routes_with_public_frontend,
    1 => $routes_customer_portal_only,
);

