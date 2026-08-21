<?php

$isProduction = env('APP_ENV') === 'production';

return [
    /*
     * Infraestructura documental antigua.
     */
    'prod_images'      => env('PROD_IMAGE_PATH'),
    'prod_images_url'  => env('PROD_IMAGE_URL'),
    'local_images'     => env('LOCAL_IMAGE_PATH'),
    'local_images_url' => env('LOCAL_IMAGE_URL'),

    /*
     * Infraestructura documental nueva.
     */
    'prod_documents'      => env('PROD_DOCUMENT_PATH'),
    'prod_documents_url'  => env('PROD_DOCUMENT_URL'),
    'local_documents'     => env('LOCAL_DOCUMENT_PATH'),
    'local_documents_url' => env('LOCAL_DOCUMENT_URL'),

    /*
     * Rutas antiguas activas según el ambiente.
     */
    'images_path' => $isProduction
        ? env('PROD_IMAGE_PATH')
        : env('LOCAL_IMAGE_PATH'),

    'images_url' => $isProduction
        ? env('PROD_IMAGE_URL')
        : env('LOCAL_IMAGE_URL'),

    /*
     * Rutas nuevas activas según el ambiente.
     */
    'documents_path' => $isProduction
        ? env('PROD_DOCUMENT_PATH')
        : env('LOCAL_DOCUMENT_PATH'),

    'documents_url' => $isProduction
        ? env('PROD_DOCUMENT_URL')
        : env('LOCAL_DOCUMENT_URL'),
];