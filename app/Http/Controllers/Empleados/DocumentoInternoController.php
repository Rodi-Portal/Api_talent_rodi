<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\ClienteInformacionInterna;
use App\Models\DocumentoInterno;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DocumentoInternoController extends Controller
{
    public function store(Request $request, ClienteInformacionInterna $informacion)
    {
        $request->validate([
            'file'              => 'required|file|max:10240', // 10MB
            'fecha_vencimiento' => 'nullable|date',
            'dias_antes'        => 'nullable|integer|min:0',
            'id_usuario'        => 'nullable|integer',
        ]);

        // 1) Tomamos el archivo UNA sola vez
        $file = $request->file('file');

        // 2) Leemos TODO lo que necesitamos ANTES de moverlo
        $originalName = $file->getClientOriginalName();
        $mimeType     = $file->getClientMimeType();
        $sizeBytes    = $file->getSize(); // ← AQUÍ Y SOLO AQUÍ

        // 3) Base según entorno
        $basePath = app()->environment('local')
            ? rtrim(env('LOCAL_IMAGE_PATH'), '/\\')
            : rtrim(env('PROD_IMAGE_PATH'), '/\\');

        // 4) Ruta relativa: _internos/id_portal/id_cliente
        $folderRel = "_internos/{$informacion->id_portal}/{$informacion->id_cliente}";
        $targetDir = $basePath . DIRECTORY_SEPARATOR . $folderRel;

        // 5) Crear carpeta si no existe
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        // 6) Nombre final (puedes agregar lógica para evitar sobrescribir)
        $filename = $originalName;

        // 7) Movemos el archivo físico
        $file->move($targetDir, $filename);

        // 8) Ruta relativa que guardaremos en la BD
        $storagePath = $folderRel . '/' . $filename;

        $now = Carbon::now();

        $doc = DocumentoInterno::create([
            'id_informacion_interna' => $informacion->id,
            'id_usuario'             => $request->input('id_usuario'),

            'nombre'                 => $filename,
            'typo'                   => $mimeType,
            'size'                   => $sizeBytes,
            'storage_path'           => $storagePath,

            'fecha_vencimiento'      => $request->input('fecha_vencimiento'),
            'dias_antes'             => $request->input('dias_antes', 0),
            'eliminado'              => 0,

            'creacion'               => $now,
            'edicion'                => $now,
        ]);

        return response()->json($doc, 201);
    }

    public function download(DocumentoInterno $documento)
    {
        // 1️⃣ Base según entorno
        $basePath = app()->environment('local')
            ? rtrim(env('LOCAL_IMAGE_PATH'), '/\\')
            : rtrim(env('PROD_IMAGE_PATH'), '/\\');

        // 2️⃣ Ruta absoluta al archivo
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $documento->storage_path;

        if (! file_exists($fullPath)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        // 3️⃣ Descargar usando la ruta absoluta
        return response()->download($fullPath, $documento->nombre);
    }

    public function destroy(DocumentoInterno $documento)
    {
        $documento->eliminado = 1;
        $documento->edicion   = now();
        $documento->save();

        // Si quisieras borrarlo físicamente del portal:
        // $basePath = app()->environment('local')
        //     ? rtrim(env('LOCAL_IMAGE_PATH'), '/\\')
        //     : rtrim(env('PROD_IMAGE_PATH'), '/\\');
        // $fullPath = $basePath . DIRECTORY_SEPARATOR . $documento->storage_path;
        // if (file_exists($fullPath)) {
        //     @unlink($fullPath);
        // }

        return response()->json(['ok' => true]);
    }
}
