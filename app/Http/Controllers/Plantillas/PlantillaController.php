<?php
namespace App\Http\Controllers\Plantillas;

use App\Http\Controllers\Controller;
use App\Models\Plantilla;
use App\Models\PlantillaAdjunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
// 👇 agrega estas:
use Illuminate\Support\Facades\View;

class PlantillaController extends Controller
{

    public function index(Request $request)
    {
        $raw = $request->query('id_cliente');

        // Puede venir un solo id, array, o "6,70"
        $ids = is_array($raw)
            ? $raw
            : (is_string($raw) && str_contains($raw, ',') ? explode(',', $raw) : [$raw]);

        $ids = array_values(array_filter(array_map(static fn($v) => (int) $v, $ids)));

        $query = Plantilla::with([
            'adjuntos',
            'cliente:id,nombre', // ajusta al campo real que usas como nombre
        ]);

        if (! empty($ids)) {
            $query->where(function ($q) use ($ids) {
                $q->whereNull('id_cliente')      // 👈 plantillas generales del portal
                    ->orWhereIn('id_cliente', $ids); // 👈 plantillas específicas de esos clientes
            });
        }

        $plantillas = $query->get();

        return response()->json($plantillas);
    }

    public function listar()
    {
        $path     = resource_path('views/emails/plantillas');
        $archivos = File::files($path);

        $plantillas = collect($archivos)->map(function ($file) {
            $nombre = $file->getFilenameWithoutExtension(); // sin .blade.php
            return [
                'value' => $nombre,
                'label' => ucfirst(str_replace('_', ' ', $nombre)),
            ];
        });

        return response()->json($plantillas->values());
    }

    public function vistaPrevia(Request $request)
    {
        $nombre = $request->input('plantilla');

        $vista = "emails.plantillas.$nombre";
        if (! View::exists($vista)) {
            return response()->json(['error' => 'Plantilla no encontrada'], 404);
        }

        $html = view($vista, [
            'titulo'   => $request->input('titulo', ''),
            'cuerpo'   => $request->input('cuerpo', ''),
            'saludo'   => $request->input('saludo', ''),
            'logo_src' => $request->input('logo_url', ''),
        ])->render();

        return response()->json(['html' => $html]);
    }
/*
    public function store(Request $request)
    {
        Log::info('🌐 Iniciando store de plantilla');

        $validated = $request->validate([
            'id'                   => 'nullable|integer|exists:portal_main.plantillas,id',
            'nombre_personalizado' => 'required|string|max:255',
            'nombre_plantilla'     => 'required|string|max:100',
            'titulo'               => 'required|string|max:255',
            'asunto'               => 'nullable|string|max:255',
            'cuerpo'               => 'required|string',
            'saludo'               => 'nullable|string|max:255',
            'id_usuario'           => 'required|integer|exists:portal_main.usuarios_portal,id',
            'id_cliente'           => 'required|integer|exists:portal_main.cliente,id',
            'id_portal'            => 'required|integer',
            'logo'                 => 'nullable|file|image|max:2048',
            'adjuntos.*'           => 'nullable|file|mimes:pdf,jpeg,png,jpg,gif,svg|max:5120',
            'eliminar_logo'        => 'nullable|boolean',
            'adjuntos_a_eliminar'  => 'nullable|string', // JSON array string
        ]);

        Log::info('📦 Datos validados', $validated);

        $plantilla = Plantilla::updateOrCreate(
            ['id' => $request->id],
            [
                'nombre_personalizado' => $validated['nombre_personalizado'],
                'nombre_plantilla'     => $validated['nombre_plantilla'],
                'titulo'               => $validated['titulo'],
                'asunto'               => $validated['asunto'] ?? null,
                'cuerpo'               => $validated['cuerpo'],
                'saludo'               => $validated['saludo'] ?? null,
                'id_usuario'           => $validated['id_usuario'],
                'id_cliente'           => $validated['id_cliente'],
                'id_portal'            => $validated['id_portal'],
            ]
        );

        Log::info('✅ Plantilla guardada o actualizada', ['id' => $plantilla->id]);

        $basePath = rtrim(env('LOCAL_IMAGE_PATH'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plantillas';

        // 🧽 Eliminar logo si se indica
        if ($request->boolean('eliminar_logo') && $plantilla->logo_path) {
            $rutaLogo = $basePath . '/_logos/' . $plantilla->logo_path;
            if (file_exists($rutaLogo)) {
                unlink($rutaLogo);
                Log::info('🗑 Logo eliminado', ['ruta' => $rutaLogo]);
            }
            $plantilla->logo_path = null;
            $plantilla->save();
        }

        // 🖼 Guardar nuevo logo si existe
        if ($request->hasFile('logo')) {
            // Eliminar logo anterior si hay
            if ($plantilla->logo_path) {
                $rutaLogoAnt = $basePath . '/_logos/' . $plantilla->logo_path;
                if (file_exists($rutaLogoAnt)) {
                    unlink($rutaLogoAnt);
                    Log::info('🗑 Logo anterior eliminado', ['ruta' => $rutaLogoAnt]);
                }
            }

            $logo     = $request->file('logo');
            $logoName = uniqid('logo_') . '.' . $logo->getClientOriginalExtension();
            $this->resizeAndSaveImage(
                $logo->getPathname(),
                $basePath . '/_logos/' . $logoName,
                300, 100// máximo ancho: 300px, alto: 100px
            );
            $plantilla->logo_path = $logoName;
            $plantilla->save();

            Log::info('🖼 Logo guardado', [
                'original'      => $logo->getClientOriginalName(),
                'guardado_como' => $logoName,
                'ruta'          => $basePath . '/_logos/' . $logoName,
            ]);
        }

        // 🧽 Eliminar adjuntos si se indica
        if ($request->filled('adjuntos_a_eliminar')) {
            Log::info('🧪 Campo recibido adjuntos_a_eliminar:', [$request->adjuntos_a_eliminar]);

            $ids = json_decode($request->adjuntos_a_eliminar, true);
            if (is_array($ids)) {
                foreach ($ids as $idAdjunto) {
                    $adjunto = PlantillaAdjunto::find($idAdjunto);
                    if ($adjunto) {
                        $rutaAdjunto = $basePath . '/_adjuntos/' . $adjunto->archivo;
                        if (file_exists($rutaAdjunto)) {
                            unlink($rutaAdjunto);
                            Log::info('🗑 Adjunto eliminado', ['ruta' => $rutaAdjunto]);
                        }
                        $adjunto->delete();
                    }
                }
            }
        }

        // 📎 Guardar nuevos adjuntos
        if ($request->hasFile('adjuntos')) {
            foreach ($request->file('adjuntos') as $archivo) {
                $originalName = $archivo->getClientOriginalName();
                $uniqueName   = uniqid('adj_') . '.' . $archivo->getClientOriginalExtension();
                $archivo->move($basePath . '/_adjuntos', $uniqueName);

                PlantillaAdjunto::create([
                    'id_plantilla'    => $plantilla->id,
                    'nombre_original' => $originalName,
                    'archivo'         => $uniqueName,
                ]);

                Log::info('📎 Adjunto guardado', [
                    'original'      => $originalName,
                    'guardado_como' => $uniqueName,
                    'ruta'          => $basePath . '/_adjuntos/' . $uniqueName,
                ]);
            }
        }

        return response()->json([
            'success'      => true,
            'plantilla_id' => $plantilla->id,
        ]);
    }
*/
    public function store(Request $request)
    {
        Log::info('🌐 Iniciando store de plantilla (single-cliente / general)', [
            'id'         => $request->input('id'),
            'id_cliente' => $request->input('id_cliente'),
        ]);

        // 1) Normalizar id_cliente a NULL o int (general o cliente específico)
        $rawCliente = $request->input('id_cliente');
        $idCliente  = ($rawCliente !== null && $rawCliente !== '') ? (int) $rawCliente : null;

        // Lo fusionamos al request para que el validador vea null/int
        $request->merge(['id_cliente' => $idCliente]);

        // 2) Validación
        $validated = $request->validate([
            'id'                   => 'nullable|integer|exists:portal_main.plantillas,id',
            'nombre_personalizado' => 'required|string|max:255',
            'nombre_plantilla'     => 'required|string|max:100',
            'titulo'               => 'required|string|max:255',
            'asunto'               => 'nullable|string|max:255',
            'cuerpo'               => 'required|string',
            'saludo'               => 'nullable|string|max:255',
            'id_usuario'           => 'required|integer|exists:portal_main.usuarios_portal,id',
            'id_portal'            => 'required|integer',
            // 👇 ahora puede ser null (plantilla general)
            'id_cliente'           => 'nullable|integer|exists:portal_main.cliente,id',

            'logo'                 => 'nullable|file|image|max:2048',
            'adjuntos.*'           => 'nullable|file|mimes:pdf,jpeg,png,jpg,gif,svg|max:5120',
            'eliminar_logo'        => 'nullable|boolean',
            'adjuntos_a_eliminar'  => 'nullable|string', // JSON
        ]);

        // 3) Paths por ambiente
            $root = rtrim(
                app()->environment('production')
                    ? (config('paths.prod_images') ?: '')
                    : (config('paths.local_images') ?: ''),
                DIRECTORY_SEPARATOR
            );
        $basePath = $root . DIRECTORY_SEPARATOR . '_plantillas';
        $logosDir = $basePath . DIRECTORY_SEPARATOR . '_logos';
        $adjDir   = $basePath . DIRECTORY_SEPARATOR . '_adjuntos';

        if (! is_dir($logosDir)) {
            @mkdir($logosDir, 0777, true);
        }
        if (! is_dir($adjDir)) {
            @mkdir($adjDir, 0777, true);
        }

        // 4) Subimos archivos una sola vez (para esta plantilla)
        $masterLogo = null;
        if ($request->hasFile('logo')) {
            $logo     = $request->file('logo');
            $ext      = $logo->getClientOriginalExtension();
            $filename = uniqid('logo_') . '.' . $ext;
            $dest     = $logosDir . DIRECTORY_SEPARATOR . $filename;

            $this->resizeAndSaveImage($logo->getPathname(), $dest, 300, 100);
            $masterLogo = ['filename' => $filename, 'path' => $dest];
        }

        $masterAdjuntos = [];
        if ($request->hasFile('adjuntos')) {
            foreach ($request->file('adjuntos') as $file) {
                $ext    = $file->getClientOriginalExtension();
                $unique = uniqid('adj_') . '.' . $ext;
                $dest   = $adjDir . DIRECTORY_SEPARATOR . $unique;
                $file->move($adjDir, $unique);

                $masterAdjuntos[] = [
                    'original' => $file->getClientOriginalName(),
                    'filename' => $unique,
                    'path'     => $dest,
                ];
            }
        }

        // 5) Transacción: crear o actualizar SOLO UNA plantilla
        $plantilla = DB::transaction(function () use (
            $request,
            $validated,
            $idCliente,
            $logosDir,
            $adjDir,
            $masterLogo,
            $masterAdjuntos
        ) {
            // 5.1) Crear o actualizar
            if (! empty($validated['id'])) {
                /** @var \App\Models\Plantilla $plantilla */
                $plantilla = Plantilla::findOrFail($validated['id']);

                $plantilla->fill([
                    'nombre_personalizado' => $validated['nombre_personalizado'],
                    'nombre_plantilla'     => $validated['nombre_plantilla'],
                    'titulo'               => $validated['titulo'],
                    'asunto'               => $validated['asunto'] ?? null,
                    'cuerpo'               => $validated['cuerpo'],
                    'saludo'               => $validated['saludo'] ?? null,
                    'id_usuario'           => $validated['id_usuario'],
                    'id_portal'            => $validated['id_portal'],
                    'id_cliente'           => $idCliente, // puede ser null
                ]);
                $plantilla->save();
            } else {
                $plantilla = Plantilla::create([
                    'nombre_personalizado' => $validated['nombre_personalizado'],
                    'nombre_plantilla'     => $validated['nombre_plantilla'],
                    'titulo'               => $validated['titulo'],
                    'asunto'               => $validated['asunto'] ?? null,
                    'cuerpo'               => $validated['cuerpo'],
                    'saludo'               => $validated['saludo'] ?? null,
                    'id_usuario'           => $validated['id_usuario'],
                    'id_portal'            => $validated['id_portal'],
                    'id_cliente'           => $idCliente, // puede ser null
                ]);
            }

            // 5.2) Eliminar logo si se pide
            if ($request->boolean('eliminar_logo') && $plantilla->logo_path) {
                $rutaLogoAnt = $logosDir . DIRECTORY_SEPARATOR . $plantilla->logo_path;
                if (is_file($rutaLogoAnt)) {
                    @unlink($rutaLogoAnt);
                    Log::info('🗑 Logo eliminado', ['ruta' => $rutaLogoAnt]);
                }
                $plantilla->logo_path = null;
                $plantilla->save();
            }

            // 5.3) Si hay logo nuevo, reemplazar
            if ($masterLogo) {
                // eliminar anterior si existía
                if ($plantilla->logo_path) {
                    $rutaPrev = $logosDir . DIRECTORY_SEPARATOR . $plantilla->logo_path;
                    if (is_file($rutaPrev)) {
                        @unlink($rutaPrev);
                        Log::info('🗑 Logo anterior eliminado', ['ruta' => $rutaPrev]);
                    }
                }

                $plantilla->logo_path = $masterLogo['filename'];
                $plantilla->save();

                Log::info('🖼 Logo guardado', [
                    'guardado_como' => $masterLogo['filename'],
                    'ruta'          => $masterLogo['path'],
                ]);
            }

            // 5.4) Adjuntos a eliminar
            $adjuntosAEliminarJson = $request->input('adjuntos_a_eliminar');
            if (! empty($adjuntosAEliminarJson)) {
                $idsEliminar = json_decode($adjuntosAEliminarJson, true);
                if (is_array($idsEliminar)) {
                    foreach ($idsEliminar as $idAdj) {
                        $adj = PlantillaAdjunto::find($idAdj);
                        if ($adj && $adj->id_plantilla == $plantilla->id) {
                            $ruta = $adjDir . DIRECTORY_SEPARATOR . $adj->archivo;
                            if (is_file($ruta)) {
                                @unlink($ruta);
                                Log::info('🗑 Adjunto eliminado', ['ruta' => $ruta]);
                            }
                            $adj->delete();
                        }
                    }
                }
            }

            // 5.5) Nuevos adjuntos
            foreach ($masterAdjuntos as $m) {
                PlantillaAdjunto::create([
                    'id_plantilla'    => $plantilla->id,
                    'nombre_original' => $m['original'],
                    'archivo'         => $m['filename'],
                ]);

                Log::info('📎 Adjunto guardado', [
                    'original'      => $m['original'],
                    'guardado_como' => $m['filename'],
                    'ruta'          => $m['path'],
                ]);
            }

            return $plantilla;
        });

        Log::info('✅ Plantilla creada/actualizada', [
            'id'         => $plantilla->id,
            'id_cliente' => $plantilla->id_cliente,
        ]);

        return response()->json([
            'success'      => true,
            'plantilla_id' => $plantilla->id,
        ]);
    }

    public function descargarAdjunto($id)
    {
        $adjunto  = Adjunto::findOrFail($id);
        $basePath = rtrim(env('LOCAL_IMAGE_PATH'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '_plantillas';

        $ruta = storage_path("{$basePath}/_adjuntos/{$adjunto->archivo}");

        if (! file_exists($ruta)) {
            abort(404, 'Archivo no encontrado');
        }

        return response()->download($ruta, $adjunto->nombre_original);
    }
    private function resizeAndSaveImage($sourcePath, $targetPath, $maxWidth, $maxHeight)
    {
        list($width, $height, $type) = getimagesize($sourcePath);

        // Calcula la escala manteniendo la proporción
        $ratio     = min($maxWidth / $width, $maxHeight / $height);
        $newWidth  = (int) ($width * $ratio);
        $newHeight = (int) ($height * $ratio);

        // Crea una nueva imagen redimensionada
        $newImage = imagecreatetruecolor($newWidth, $newHeight);

        switch ($type) {
            case IMAGETYPE_JPEG:
                $sourceImage = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = imagecreatefrompng($sourcePath);
                imagealphablending($newImage, false);
                imagesavealpha($newImage, true);
                break;
            default:
                // No soportado, copia sin modificar
                copy($sourcePath, $targetPath);
                return;
        }

        imagecopyresampled(
            $newImage, $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );

        // Guarda la imagen redimensionada
        switch ($type) {
            case IMAGETYPE_JPEG:
                imagejpeg($newImage, $targetPath, 85); // calidad 85%
                break;
            case IMAGETYPE_PNG:
                imagepng($newImage, $targetPath, 6); // compresión media
                break;
        }

        imagedestroy($newImage);
        imagedestroy($sourceImage);
    }

    public function mostrarLogo(Plantilla $plantilla)
    {
        // Si la plantilla no tiene logo guardado
        if (! $plantilla->logo_path) {
            abort(404, 'La plantilla no tiene logo.');
        }

        // Mismo root que usas en store()
            $root = rtrim(
                app()->environment('production')
                    ? (config('paths.prod_images') ?: '')
                    : (config('paths.local_images') ?: ''),
                DIRECTORY_SEPARATOR
            );

        $path = $root
        . DIRECTORY_SEPARATOR . '_plantillas'
        . DIRECTORY_SEPARATOR . '_logos'
        . DIRECTORY_SEPARATOR . $plantilla->logo_path;

        if (! is_file($path)) {
            abort(404, 'Archivo de logo no encontrado.');
        }

        $mime = mime_content_type($path) ?: 'image/png';

        return response()->file($path, [
            'Content-Type' => $mime,
        ]);
    }

}
