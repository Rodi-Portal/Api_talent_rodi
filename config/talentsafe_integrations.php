<?php

return [
    'base_url' => rtrim(
        (string) env('TALENTSAFE_INTEGRATIONS_API_URL', ''),
        '/'
    ),

    'client' => env(
        'TALENTSAFE_INTEGRATIONS_API_CLIENT'
    ),

    'secret' => env(
        'TALENTSAFE_INTEGRATIONS_API_SECRET'
    ),

    'timeout' => (int) env(
        'TALENTSAFE_INTEGRATIONS_API_TIMEOUT',
        30
    ),
];