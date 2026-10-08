<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Control de acceso por pago mensual
    |--------------------------------------------------------------------------
    */

    'payment' => [
        'enabled' => true,

        // Días de gracia, igual que en CI3
        'grace_days' => 5,

        // Portales exentos en este entorno
        'exempt_portals' => [1, 19],
    ],
];