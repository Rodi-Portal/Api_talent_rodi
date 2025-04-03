<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class DocumentController extends Controller
{
    public function upload(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file'      => 'required|file|mimes:pdf,application/pdf,application/x-pdf,application/acrobat,application/vnd.pdf,jpg,jpeg,png|max:5120',
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $file     = $request->file('file');
        $fileName = $request->input('file_name');
        $carpeta  = $request->input('carpeta');

         Log::info('Archivo recibido:', ['file' => $file, 'file_name' => $fileName]);

        if (! $file) {
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath  = '/home/rodicomm/public_html/portal.rodi.com.mx';

        // Obtener la ruta de destino
        $destinationPath = app()->environment('produccion')
        ? $prodImagePath . '/' . $carpeta
        : $localImagePath . '/' . $carpeta; // Cambia el separador de directorios

        Log::info('Ruta de destino:', ['destination_path' => $destinationPath]);

        // Asegúrate de que el directorio existe
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

        // Construir la ruta completa del archivo
        $fileDestination = $destinationPath . DIRECTORY_SEPARATOR . $fileName;

        Log::info('Moviendo archivo:', [
        'file_name' => $fileName,
        'destination_path' => $fileDestination,
        ]);

        // Mover el archivo a la ruta de destino
        try {
            $file->move($destinationPath, $fileName);
        
            // 🔹 Ajustar permisos
            chmod($fileDestination, 0664);
            @chgrp($fileDestination, 'rodicomm'); // Opcional, si el grupo es el problema
        
            Log::info('Archivo movido correctamente y permisos ajustados:', [
                'file_name' => $fileName,
                'destination_path' => $fileDestination,
            ]);
        
            return response()->json([
                'status'  => 'success',
                'message' => 'Documento guardado correctamente en ' . $fileDestination,
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error al mover el archivo', [
                'exception' => $e->getMessage(),
            ]);
        
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al mover el archivo: ' . $e->getMessage(),
            ], 500);
        }
        
    }

    public function uploadZip(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $file     = $request->file('file');
        $fileName = $request->input('file_name'); // El nombre base del archivo
        $carpeta  = $request->input('carpeta');

        if (! $file) {
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath  = '/home/rodicomm/public_html/portal.rodi.com.mx';

        // Obtener la ruta de destino
        $destinationPath = app()->environment('produccion')
        ? $prodImagePath . '/' . $carpeta
        : $localImagePath . '/' . $carpeta;

        // Log::info('Ruta de destino:', ['destination_path' => $destinationPath]);

        // Asegúrate de que el directorio existe
        if (! file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }

                                                                                    // Crear un archivo ZIP
        $zipFilePath = $destinationPath . DIRECTORY_SEPARATOR . $fileName . '.zip'; // Usar file_name para el ZIP

        // Si el archivo ZIP ya existe, lo eliminamos
        if (file_exists($zipFilePath)) {
            unlink($zipFilePath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el archivo ZIP.'], 500);
        }

        // Añadir el archivo al ZIP
        if (! $zip->addFile($file->getRealPath(), $fileName . '.' . $file->getClientOriginalExtension())) {
            return response()->json(['error' => 'No se pudo agregar el archivo al ZIP.'], 500);
        }

                                         // Establecer el nivel de compresión al máximo
        $zip->setCompressionIndex(0, 9); // Compresión máxima

        $zip->close(); // Cerrar el archivo ZIP

        // Respuesta exitosa
        return response()->json([
            'status'  => 'success',
            'message' => 'Documento guardado correctamente en ' . $zipFilePath,
        ], 200);
    }

    public function unzipFile(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $fileName = $request->input('file_name');
        $carpeta  = $request->input('carpeta');

        //  \Log::info('Archivo a descomprimir:', [$fileName]);
        //  \Log::info('Carpeta destino:', [$carpeta]);

        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath  = '/home/rodicomm/public_html/portal.rodi.com.mx';

        // Obtener la ruta de destino
        $destinationPath = app()->environment('produccion')
        ? rtrim($prodImagePath, '/\\') . '/' . trim($carpeta, '/\\')
        : rtrim($localImagePath, '/\\') . '/' . trim($carpeta, '/\\');

        // \Log::info('Ruta de destino:', [$destinationPath]);

        // Asegúrate de que el directorio existe
        if (! is_dir($destinationPath)) {
            //   \Log::info('El directorio no existe, se creará uno nuevo: ' . $destinationPath);
            mkdir($destinationPath, 0755, true);
        }

        // Ruta del archivo ZIP
        $zipFilePath = rtrim($destinationPath, '/\\') . DIRECTORY_SEPARATOR . trim($fileName, '/\\');
        $zipFilePath = str_replace('\\', '/', $zipFilePath); // Normaliza las barras
                                                             //  \Log::info('Ruta del archivo ZIP:', [$zipFilePath]);

        // Verificar si el archivo ZIP existe
        if (! file_exists($zipFilePath)) {
            //  \Log::error('El archivo ZIP no se encontró:', [$zipFilePath]);
            return response()->json(['error' => 'El archivo ZIP no existe.'], 404);
        }

        // Crear un objeto ZipArchive
        $zip = new ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            //  \Log::error('No se pudo abrir el archivo ZIP:', [$zipFilePath]);
            return response()->json(['error' => 'No se pudo abrir el archivo ZIP.'], 500);
        }

        // Extraer el contenido del ZIP
        $zip->extractTo($destinationPath);
        $zip->close();
        // \Log::info('Archivo descomprimido correctamente.');

        // Eliminar la extensión .zip para obtener el nombre del archivo descomprimido
        $baseFileName = pathinfo($fileName, PATHINFO_FILENAME);
        $filePath     = $destinationPath . DIRECTORY_SEPARATOR . $baseFileName;

        // Ajusta la base de la URL según tu configuración
        $baseUrl = app()->environment('produccion')
        ? 'https://portal.rodi.com.mx/' . trim($carpeta, '/\\') . '/'
        : 'http://localhost/rodi_portal/' . trim($carpeta, '/\\') . '/';

        // Generar la URL del archivo descomprimido
        $fileUrl = $baseUrl . $baseFileName;
        // \Log::info('URL del archivo:', [$fileUrl]);

        // Respuesta exitosa con la URL del archivo
        return response()->json([
            'status'  => 'success',
            'message' => 'Archivo descomprimido correctamente.',
            'file'    => $fileUrl, // Devuelve la URL del archivo descomprimido
        ], 200);
    }

    public function deleteFile(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file_name' => 'required|string',
            'carpeta'   => 'require|string',
        ]);

        $fileName = $request->input('file_name');
        $carpeta  = $request->input('carpeta');

        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath  = '/home/rodicomm/public_html/portal.rodi.com.mx';

        // Obtener la ruta de destino
        $destinationPath = app()->environment('produccion')
        ? rtrim($prodImagePath, '/\\') . '/' . trim($carpeta, '/\\')
        : rtrim($localImagePath, '/\\') . '/' . trim($carpeta, '/\\');

        $filePath = $destinationPath . '/' . $fileName;

        // Verificar si el archivo existe y eliminarlo
        if (file_exists($filePath)) {
            unlink($filePath);
            return response()->json(['status' => 'success', 'message' => 'Archivo eliminado correctamente.'], 200);
        } else {
            return response()->json(['error' => 'El archivo no existe.'], 404);
        }
    }

    public function downloadZip(Request $request)
    {
        // Validar la solicitud
        $request->validate([
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $fileName = $request->input('file_name') . '.zip'; // Asegúrate de que el nombre tenga la extensión .zip
        $carpeta  = $request->input('carpeta');

        // Define las rutas directamente
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath  = '/home/rodicomm/public_html/portal.rodi.com.mx';

        // Obtener la ruta de destino
        $filePath = app()->environment('produccion')
        ? rtrim($prodImagePath, '/\\') . '/' . trim($carpeta, '/\\') . '/' . $fileName
        : rtrim($localImagePath, '/\\') . '/' . trim($carpeta, '/\\') . '/' . $fileName;

        // Verificar si el archivo ZIP existe
        if (! file_exists($filePath)) {
            return response()->json(['error' => 'El archivo ZIP no existe.'], 404);
        }

        // Descargar el archivo ZIP
        return response()->download($filePath);
    }

    private function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", ceil($length / 10))), 1, $length);
    }

}
