<?php

return [

    /*
    |--------------------------------------------------------------------------
    | REST API and webhooks (App\Api)
    |--------------------------------------------------------------------------
    */

    // Part of the global API key: a new one makes a new key (Settings » API & Webhooks).
    'key_salt' => env('APIWEBHOOKS_API_KEY_SALT', ''),

    // Websites allowed to call the API from a browser (CORS), comma separated; "*" for any.
    'cors_hosts' => env('APIWEBHOOKS_CORS_HOSTS', ''),

    // Largest page of a list.
    'max_page_size' => 1000,

];
