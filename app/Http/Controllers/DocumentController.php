<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

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

        // Preparar la ruta de destino
        $destinationPath = $carpeta ? 'https://portal.talentsafecontrol.com/_psicometria/' : 'https://portal.talentsafecontrol.com/_docs/';

        // Loggear los valores antes de mover el archivo
        Log::info('Moviendo archivo:', [
            'file_name' => $fileName,
            'destination_path' => $destinationPath,
        ]);

        // Guardar temporalmente el archivo en el servidor local
        $tempPath = storage_path('app/temp/' . $fileName);
        $file->move(storage_path('app/temp'), $fileName);

        // Enviar el archivo al servidor remoto
        try {
            $this->sendFileToRemoteServer($tempPath, $fileName, $destinationPath);

            // Eliminar el archivo temporal
            File::delete($tempPath);

            return response()->json([
                'status' => 'success',
                'message' => 'Documento guardado correctamente en ' . $destinationPath,
            ], 200);
        } catch (\Exception $e) {
            // Loggear excepción
            Log::error('Error al mover el archivo', [
                'exception' => $e->getMessage()
            ]);

            // Eliminar el archivo temporal en caso de error
            File::delete($tempPath);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al mover el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function sendFileToRemoteServer($filePath, $fileName, $destinationUrl)
    {
        $ch = curl_init();

        $cfile = new \CURLFile($filePath, mime_content_type($filePath), $fileName);

        $data = array(
            'file' => $cfile,
            'file_name' => $fileName
        );

        curl_setopt($ch, CURLOPT_URL, $destinationUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error_msg = curl_error($ch);
            curl_close($ch);
            throw new \Exception('Error al enviar el archivo: ' . $error_msg);
        }

        curl_close($ch);

        $responseData = json_decode($response, true);

        if ($responseData['status'] != 'success') {
            throw new \Exception('Error al mover el archivo: ' . $responseData['message']);
        }
    }
}
