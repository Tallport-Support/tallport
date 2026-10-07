<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Browsers may call the REST API from the websites in Settings » API &
| Webhooks » Allowed CORS Hosts (APIWEBHOOKS_CORS_HOSTS); no others.
|
*/

return [

    'paths' => ['api/*', '*/api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('APIWEBHOOKS_CORS_HOSTS', ''))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Tallport-API-Key', 'X-FreeScout-API-Key'],

    'exposed_headers' => ['Resource-ID'],

    'max_age' => 0,

    'supports_credentials' => false,

];
