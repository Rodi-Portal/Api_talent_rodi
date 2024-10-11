<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DocumentController extends Controller
{
    public function upload(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'file_name' => 'required|string',
            'carpeta' => 'required|string',
        ]);
    
        $file = $request->file('file');
        $fileName = $request->input('file_name');
        $carpeta = $request->input('carpeta');
    
       // Log::info('Archivo recibido:', ['file' => $file, 'file_name' => $fileName]);
    
        if (!$file) {
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }
    
        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath = '/home/rodicomm/public_html/portal.rodi.com.mx';
    
        // Obtener la ruta de destino
        $destinationPath = app()->environment('production') 
            ? $prodImagePath . '/' . $carpeta 
            : $localImagePath . '/' . $carpeta;  // Cambia el separador de directorios
    
        Log::info('Ruta de destino:', ['destination_path' => $destinationPath]);
    
        // Asegúrate de que el directorio existe
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
    
        // Construir la ruta completa del archivo
        $fileDestination = $destinationPath . DIRECTORY_SEPARATOR . $fileName;
    
       /* Log::info('Moviendo archivo:', [
            'file_name' => $fileName,
            'destination_path' => $fileDestination,
        ]);*/
    
        // Mover el archivo a la ruta de destino
        try {
            $file->move($destinationPath, $fileName);
    
            /*Log::info('Archivo movido correctamente:', [
                'file_name' => $fileName,
                'destination_path' => $fileDestination,
            ]);*/
    
            return response()->json([
                'status' => 'success',
                'message' => 'Documento guardado correctamente en ' . $fileDestination,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al mover el archivo', [
                'exception' => $e->getMessage(),
            ]);
    
            return response()->json([
                'status' => 'error',
                'message' => 'Error al mover el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
    
}
