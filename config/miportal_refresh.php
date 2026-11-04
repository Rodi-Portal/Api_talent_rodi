<?php

return [
    // Activar solo despues de completar las pruebas.
    'enabled' => filter_var(
        env('MIPORTAL_REFRESH_ENABLED', false),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Lista exclusiva de origenes de MiPortal.
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(
            ',',
            (string) env('MIPORTAL_REFRESH_ORIGINS', '')
        )
    ))),
];