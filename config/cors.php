<?php

return [
    /*
     |--------------------------------------------------------------------------
     | Laravel CORS Options
     |--------------------------------------------------------------------------
     |
     | The allowed_origins and allowed_origins_patterns options allow you to
     | configure what domains (or patterns) can send requests to your
     | application. Allowed_methods and allowed_headers are self-explanatory.
     |
     */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];