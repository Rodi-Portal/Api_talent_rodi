<?php

return [
   'paths' => [],
    'allowed_methods' => [],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    ))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => [],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];

/*OLD CORS
return [
   'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['https://portal.talentsafecontrol.com', '*'],
    'allowed_origins_patterns' => ['*'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];


*/