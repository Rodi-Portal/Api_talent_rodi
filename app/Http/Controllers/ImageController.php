<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Response;

class ImageController extends Controller
{
    /**
     * Obtiene un archivo desde una URL externa y lo devuelve.
     *
     * @param  string  $path
     * @return \Illuminate\Http\Response
     */
    public function getFile($path)
    {
        // URL completa del archivo en el servidor externo
        $externalUrl = 'https://rodicontrol.rodi.com.mx/' . $path;

        try {
            // Realizar una solicitud GET a la URL externa
            $response = Http::get($externalUrl);

            if ($response->successful()) {
                // Obtener el tipo de contenido del archivo
                $contentType = $response->header('Content-Type');

                // Devolver el contenido del archivo con el tipo de contenido adecuado
                return response($response->body(), 200)
                    ->header('Content-Type', $contentType);
            }

            return response()->json(['error' => 'Archivo no encontrado'.$path], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al obtener el archivo: ' . $e->getMessage()], 500);
        }
    }
}