<?php

return [

    'key_path' => env('EMPLOYEE_DATA_KEY_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Campos personalizados que requieren cifrado
    |--------------------------------------------------------------------------
    */

    'campos_cifrado' => [],

    /*
    |--------------------------------------------------------------------------
    | Campos adicionales para la integracion CROL
    |--------------------------------------------------------------------------
    |
    | Incorporar exclusivamente campos necesarios que no existan
    | actualmente en TalentSafe.
    |
    */

    'crol' => [],

];