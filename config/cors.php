<?php

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