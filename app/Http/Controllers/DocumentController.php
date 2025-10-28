<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DocumentController extends Controller
{

    public function upload(Request $request)
{
    $traceId = (string) Str::ulid();
    $t0 = microtime(true);
    Log::withContext(['traceId' => $traceId, 'endpoint' => 'document.upload']);

    Log::info('⌛ Inicio UPLOAD', [
        'ip'           => $request->ip(),
        'content_type' => $request->header('Content-Type'),
        'content_len'  => $request->header('Content-Length'),
        'files_count'  => count($request->files->all()),
        'upload_max'   => ini_get('upload_max_filesize'),
        'post_max'     => ini_get('post_max_size'),
        'tmp_dir'      => sys_get_temp_dir(),
    ]);

    try {
        $request->validate([
            'file'      => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'file_name' => 'required|string',
            'carpeta'   => 'nullable|string',
        ]);
    } catch (\Illuminate\Validation\ValidationException $ve) {
        Log::warning('⚠️ Validación fallida UPLOAD', ['errors' => $ve->errors()]);
        return response()->json(['traceId' => $traceId, 'errors' => $ve->errors()], 422);
    }

    $file = $request->file('file');
    if (! $file) {
        Log::error('❌ No se recibió archivo en UPLOAD');
        return response()->json(['traceId' => $traceId, 'error' => 'No se recibió ningún archivo.'], 400);
    }

    Log::info('📦 Archivo temporal', [
        'tmp_path'   => $file->getPathname(),
        'size_bytes' => $file->getSize(),
        'mime_cli'   => $file->getClientMimeType(),
        'mime_det'   => $file->getMimeType(),
        'is_valid'   => $file->isValid(),
        'php_error'  => method_exists($file, 'getError') ? $file->getError() : null,
    ]);

    if (! $file->isValid()) {
        $err = $file->getError();
        $map = [
            UPLOAD_ERR_INI_SIZE   => 'excede upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE  => 'excede MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL    => 'subida parcial',
            UPLOAD_ERR_NO_FILE    => 'sin archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'falta tmp_dir',
            UPLOAD_ERR_CANT_WRITE => 'no se pudo escribir',
            UPLOAD_ERR_EXTENSION  => 'bloqueado por extensión',
        ];
        Log::error('❌ Archivo inválido en UPLOAD', ['php_error_code' => $err, 'explain' => $map[$err] ?? 'desconocido']);
        return response()->json(['traceId' => $traceId, 'error' => 'Archivo inválido: '.($map[$err] ?? 'desconocido')], 400);
    }

    $isProd     = app()->environment('production');
    $basePath   = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH');
    $basePublic = $isProd ? env('PROD_IMAGE_URL')  : env('LOCAL_IMAGE_URL');

    Log::info('🧭 Config de paths', [
        'env'        => $isProd ? 'production' : app()->environment(),
        'basePath'   => $basePath,
        'basePublic' => $basePublic,
    ]);

    if (! $basePath) {
        Log::error('🚫 Falta PROD_IMAGE_PATH/LOCAL_IMAGE_PATH en .env');
        return response()->json(['traceId' => $traceId, 'error' => 'Ruta base no configurada en .env'], 500);
    }

    $carpetaRaw = (string)$request->input('carpeta', '');
    $carpeta    = trim(str_replace(['\\', '..'], ['/', ''], $carpetaRaw), '/');
    $fileName   = basename((string)$request->input('file_name'));

    $destinationPath = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');
    $fileDestination = $destinationPath . $fileName;

    Log::info('📍 Destino calculado', [
        'carpeta_raw' => $carpetaRaw,
        'carpeta'     => $carpeta,
        'dest_dir'    => $destinationPath,
        'dest_file'   => $fileDestination,
    ]);

    if (! is_dir($destinationPath)) {
        $mk = @mkdir($destinationPath, 0755, true);
        Log::info('📁 mkdir ejecutado', ['ok' => $mk]);
        if (! $mk && ! is_dir($destinationPath)) {
            return response()->json(['traceId' => $traceId, 'error' => 'No se pudo crear el directorio destino'], 500);
        }
    }

    if (! is_writable($destinationPath)) {
        Log::error('🔒 Directorio no escribible', ['dir' => $destinationPath]);
        return response()->json(['traceId' => $traceId, 'error' => 'El directorio no es escribible: '.$destinationPath], 500);
    }

    try {
        $file->move($destinationPath, $fileName);

        // Permisos (no falla si no aplica)
        @chmod($fileDestination, 0664);
        @chgrp($fileDestination, 'rodicomm');

        $publicUrl = null;
        if ($basePublic) {
            $publicUrl = rtrim($basePublic, "/") . '/'
                . ($carpeta !== '' ? $carpeta . '/' : '')
                . rawurlencode($fileName);
        }

        $ms = (int)((microtime(true) - $t0) * 1000);
        Log::info('✅ UPLOAD OK', [
            'dur_ms'    => $ms,
            'publicUrl' => $publicUrl,
            'final'     => $fileDestination,
        ]);

        return response()->json([
            'traceId'    => $traceId,
            'status'     => 'success',
            'message'    => 'Documento guardado correctamente.',
            'path'       => $fileDestination,
            'public_url' => $publicUrl,
            'dur_ms'     => $ms,
        ], 200);

    } catch (\Throwable $e) {
        Log::error('💥 Error al mover el archivo', [
            'msg'  => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
        return response()->json(['traceId' => $traceId, 'error' => 'Error al mover el archivo: '.$e->getMessage()], 500);
    }
}
   /* public function upload(Request $request)
    {
        // ✅ Validación (simplificada de mimes)
        $request->validate([
            'file'      => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $file = $request->file('file');
        if (! $file) {
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // ✅ Paths desde .env (APP_ENV usa 'production' en inglés)
        $isProd     = app()->environment('production');
        $basePath   = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH'); // p.ej. /home/... o C:/laragon/...
        $basePublic = $isProd ? env('PROD_IMAGE_URL') : env('LOCAL_IMAGE_URL');   // p.ej. https://portal.rodi.com.mx

        // ✅ Saneo entradas
        $carpetaInput  = $request->input('carpeta');
        $fileNameInput = $request->input('file_name');

        // Evita traversal y backslashes
        $carpeta  = trim(str_replace(['\\', '..'], ['/', ''], $carpetaInput), '/');
        $fileName = basename($fileNameInput);

        // ✅ Rutas finales
        $destinationPath = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');
        $fileDestination = $destinationPath . $fileName;

        // ✅ Crea carpeta si no existe (con recursivo)
        if (! is_dir($destinationPath)) {
            if (! mkdir($destinationPath, 0755, true) && ! is_dir($destinationPath)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No se pudo crear el directorio destino: ' . $destinationPath,
                ], 500);
            }
        }

        try {
            // ✅ Mover archivo
            // move($dir, $name) coloca el archivo en $dir con nombre $name
            $file->move($destinationPath, $fileName);

            // ✅ Permisos (ignora silenciosamente en Windows)
            @chmod($fileDestination, 0664);
            @chgrp($fileDestination, 'rodicomm'); // opcional en tu server

            // (Opcional) URL pública del archivo si lo sirves directo desde esa ruta
            // Ajusta si la carpeta raíz pública es diferente
            $publicUrl = null;
            if ($basePublic) {
                $publicUrl = rtrim($basePublic, "/") . '/'
                . ($carpeta !== '' ? $carpeta . '/' : '')
                . rawurlencode($fileName);
            }

            return response()->json([
                'status'     => 'success',
                'message'    => 'Documento guardado correctamente.',
                'path'       => $fileDestination,
                'public_url' => $publicUrl, // útil para previsualización/descarga
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error al mover el archivo', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al mover el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }*/

    public function uploadZip(Request $request)
    {
        // ✅ Validar la solicitud
        $request->validate([
            'file'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'file_name' => 'required|string', // nombre base del ZIP (sin extensión)
            'carpeta'   => 'required|string',
        ]);

        $file = $request->file('file');
        if (! $file) {
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // ✅ Rutas desde .env (usa 'production', no 'produccion')
        $isProd   = app()->environment('production');
        $basePath = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH');

        // ✅ Saneo de entradas (evita traversal y backslashes)
        $carpetaInput  = $request->input('carpeta');
        $zipBaseNameIn = $request->input('file_name');

        $carpeta   = trim(str_replace(['\\', '..'], ['/', ''], $carpetaInput), '/');
        $zipBase   = preg_replace('/[^\w\-.]+/u', '_', trim($zipBaseNameIn)); // nombre ZIP seguro
        $ext       = strtolower($file->getClientOriginalExtension());
        $safeName  = preg_replace('/[^\w\-.]+/u', '_', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $innerName = $safeName . '.' . $ext; // nombre del archivo dentro del zip

        // (Opcional) coherencia simple MIME↔extensión
        $map = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (isset($map[$ext]) && strpos((string) $file->getMimeType(), explode('/', $map[$ext])[1]) === false) {
            return response()->json(['error' => 'Extensión y tipo de archivo no coinciden.'], 422);
        }

        // ✅ Rutas destino
        $destinationPath = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');
        if (! is_dir($destinationPath)) {
            if (! mkdir($destinationPath, 0755, true) && ! is_dir($destinationPath)) {
                return response()->json(['error' => 'No se pudo crear el directorio: ' . $destinationPath], 500);
            }
        }

        $zipFilePath = $destinationPath . $zipBase . '.zip';

        // Si ya existe, eliminar
        if (is_file($zipFilePath) && ! unlink($zipFilePath)) {
            return response()->json(['error' => 'No se pudo reemplazar el ZIP existente.'], 500);
        }

        // ✅ Crear ZIP y agregar el archivo subido (desde su tmp path)
        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['error' => 'No se pudo crear el archivo ZIP.'], 500);
        }

        // Añadir el archivo con nombre “limpio” dentro del ZIP
        if (! $zip->addFile($file->getRealPath(), $innerName)) {
            $zip->close();
            @unlink($zipFilePath);
            return response()->json(['error' => 'No se pudo agregar el archivo al ZIP.'], 500);
        }

        // Compresión máxima (por nombre, más claro si hubiera >1 archivo)
        $zip->setCompressionName($innerName, 9);
        $zip->close();

        // (Opcional) permisos/propietario — ignorará en Windows
        @chmod($zipFilePath, 0664);
        @chgrp($zipFilePath, 'rodicomm');

        return response()->json([
            'status'  => 'success',
            'message' => 'Documento guardado correctamente.',
            'zip'     => $zipFilePath,
        ], 200);
    }

    public function unzipFile(Request $request)
    {
        // 1) Validación básica
        $request->validate([
            'file_name' => 'required|string', // nombre del .zip (puede venir con o sin .zip)
            'carpeta'   => 'required|string',
        ]);

        // 2) Entradas saneadas
        $zipNameIn = $request->input('file_name');
        $carpetaIn = $request->input('carpeta');

        $zipName = basename($zipNameIn); // evita traversal en nombre
        if (! preg_match('/\.zip$/i', $zipName)) {
            $zipName .= '.zip';
        }

        // quita backslashes y '..' en carpeta
        $carpeta = trim(str_replace(['\\', '..'], ['/', ''], $carpetaIn), '/');

        // 3) Rutas desde .env por entorno
        $isProd   = app()->environment('production');
        $basePath = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH');
        $baseUrl  = $isProd ? env('PROD_IMAGE_URL') : env('LOCAL_IMAGE_URL');

        // 4) Construcción de rutas/URLs
        $destinationPath = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');
        if (! is_dir($destinationPath)) {
            if (! mkdir($destinationPath, 0755, true) && ! is_dir($destinationPath)) {
                return response()->json(['error' => 'No se pudo crear el directorio destino.'], 500);
            }
        }

        $zipFilePath = $destinationPath . $zipName;

        if (! is_file($zipFilePath)) {
            return response()->json(['error' => 'El archivo ZIP no existe.'], 404);
        }

        // 5) Abrir ZIP y checar seguridad (anti zip-slip)
        $zip = new \ZipArchive();
        if ($zip->open($zipFilePath) !== true) {
            return response()->json(['error' => 'No se pudo abrir el archivo ZIP.'], 500);
        }

        // Revisa cada entrada: no permitir rutas absolutas ni "../"
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);

            // normaliza separadores
            $entryNameNorm = str_replace('\\', '/', $entryName);

            if (str_starts_with($entryNameNorm, '/')
                || str_contains($entryNameNorm, '../')
                || str_contains($entryNameNorm, '..\\')) {
                $zip->close();
                return response()->json(['error' => 'ZIP inválido: contiene rutas peligrosas.'], 400);
            }
        }

        // 6) Extraer
        if (! $zip->extractTo($destinationPath)) {
            $zip->close();
            return response()->json(['error' => 'No se pudo extraer el ZIP.'], 500);
        }

        // Recopila los archivos extraídos (no directorios)
        $extractedFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -1) !== '/') { // omite directorios
                                                 // ruta absoluta local
                $abs = $destinationPath . str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $name);
                // URL pública
                $url = rtrim($baseUrl, '/') . '/'
                . ($carpeta !== '' ? $carpeta . '/' : '')
                . str_replace(DIRECTORY_SEPARATOR, '/', $name);
                $extractedFiles[] = [
                    'name' => $name,
                    'path' => $abs,
                    'url'  => $url,
                ];
            }
        }
        $zip->close();

        // 7) Respuesta
        return response()->json([
            'status'     => 'success',
            'message'    => 'Archivo descomprimido correctamente.',
            'zip'        => $zipFilePath,
            'files'      => $extractedFiles,
            'first_file' => $extractedFiles[0]['url'] ?? null, // atajo útil si solo hay uno
        ], 200);
    }

    public function deleteFile(Request $request)
    {
        // 1) Validación
        $request->validate([
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        // 2) Saneo de entradas
        $fileNameIn = $request->input('file_name');
        $carpetaIn  = $request->input('carpeta');

        $fileName = basename($fileNameIn); // evita rutas tipo a/b/c.ext
        $carpeta  = trim(str_replace(['\\', '..'], ['/', ''], $carpetaIn), '/');

        // 3) Rutas según entorno desde .env
        $isProd   = app()->environment('production');
        $basePath = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH');

        // 4) Construcción de ruta absoluta
        $destinationPath = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR
            . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');
        $filePath = $destinationPath . $fileName;

        // 5) Verificar y eliminar
        if (! is_file($filePath)) {
            return response()->json(['error' => 'El archivo no existe.'], 404);
        }

        if (! @unlink($filePath)) {
            return response()->json(['error' => 'No se pudo eliminar el archivo. Revisa permisos.'], 500);
        }

        // (Opcional) intenta borrar la carpeta si quedó vacía (ignora errores)
        @rmdir($destinationPath);

        return response()->json([
            'status'  => 'success',
            'message' => 'Archivo eliminado correctamente.',
            'path'    => $filePath,
        ], 200);
    }

    public function downloadZip(Request $request)
    {
        $request->validate([
            'file_name' => 'required|string',
            'carpeta'   => 'required|string',
        ]);

        $isProd   = app()->environment('production');
        $basePath = $isProd ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH');

        $carpeta  = trim(str_replace(['\\', '..'], ['/', ''], $request->input('carpeta')), '/');
        $fileName = basename($request->input('file_name'));

        $dir = rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . ($carpeta !== '' ? $carpeta . DIRECTORY_SEPARATOR : '');

        // Candidatos a descargar
        $candidates = [];

        // Nombre exacto
        $candidates[] = $dir . $fileName;

        // Si termina en .pdf, prueba con .pdf.zip
        if (str_ends_with(strtolower($fileName), '.pdf')) {
            $candidates[] = $dir . $fileName . '.zip';
        }

        // Si no termina en .zip, prueba nombre + .zip
        if (! str_ends_with(strtolower($fileName), '.zip')) {
            $candidates[] = $dir . $fileName . '.zip';
        }

        // Buscar el primero que exista
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return response()->download($path, basename($path), [
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control'          => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma'                 => 'no-cache',
                ]);
            }
        }

        return response()->json([
            'error' => 'El archivo no existe.',
            'tried' => $candidates,
        ], 404);
    }

    private function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", ceil($length / 10))), 1, $length);
    }

}
