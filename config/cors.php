<?php

return [

    'paths' => ['api/*', 'broadcasting/auth', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:8080')))
    ),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400, // Cache preflight requests for 24 hours (reduces OPTIONS requests)

    'supports_credentials' => true,

];
