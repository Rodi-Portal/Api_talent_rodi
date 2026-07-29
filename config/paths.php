<?php

$isProduction = env('APP_ENV') === 'production';

return [
    'prod_images'      => env('PROD_IMAGE_PATH'),
    'prod_images_url'  => env('PROD_IMAGE_URL'),

    'local_images'     => env('LOCAL_IMAGE_PATH'),
    'local_images_url' => env('LOCAL_IMAGE_URL'),

    /*
     * Rutas activas según el ambiente.
     */
    'images_path' => $isProduction
        ? env('PROD_IMAGE_PATH')
        : env('LOCAL_IMAGE_PATH'),

    'images_url' => $isProduction
        ? env('PROD_IMAGE_URL')
        : env('LOCAL_IMAGE_URL'),
];