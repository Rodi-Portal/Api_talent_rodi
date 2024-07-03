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
        ]);

        // Obtener el archivo y el nombre del archivo
        $file = $request->file('file');
        $fileName = $request->input('file_name');
        $carpeta = $request->input('carpeta');
         if($carpeta != null){
  // Preparar la ruta de destino
  $destinationPath = 'https://portal.talentsafecontrol.com/_psicometria/';
         }else{
              // Preparar la ruta de destino
        $destinationPath = 'https://portal.talentsafecontrol.com/_docs/';
         }

      

        // Loggear los valores antes de mover el archivo
        Log::info('Moviendo archivo:', [
            'file_name' => $fileName,
            'destination_path' => $destinationPath,
        ]);

        // Mover el archivo a la ruta de destino
        try {
            $file->move($destinationPath, $fileName);

            return response()->json([
                'status' => 'success',
                'message' => 'Documento guardado correctamente en ' . $destinationPath,
            ], 200);
        } catch (\Exception $e) {
            // Loggear excepción
            Log::error('Error al mover el archivo', [
                'exception' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al mover el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
}