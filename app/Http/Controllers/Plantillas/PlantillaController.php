<?php
namespace App\Http\Controllers\Plantillas;

use App\Http\Controllers\Controller;
use App\Models\Plantilla;
use App\Models\PlantillaAdjunto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
// 👇 agrega estas:
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Validator;
class PlantillaController extends Controller
{

    public function index(Request $request)
    {
        $idCliente = $request->query('id_cliente');

        if (! $idCliente) {
            return response()->json(['error' => 'id_cliente es requerido'], 400);
        }

        $plantillas = Plantilla::with('adjuntos')
            ->where('id_cliente', $idCliente)
            ->get();

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
        Log::info('🌐 Iniciando store de plantilla (multi-cliente)');

        // ============ 1) Normaliza id_cliente a array ============
        $raw = $request->input('id_cliente');
        $ids = is_array($raw) ? $raw
            : (is_string($raw) && str_contains($raw, ',') ? explode(',', $raw) : [$raw]);

        $ids = array_values(array_filter(array_map(static fn($v) => (int) $v, $ids)));

        // Fusiona al request para validación
        $request->merge(['id_cliente' => $ids]);

        // ============ 2) Validación ============
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

            // 🔽 ahora como ARREGLO
            'id_cliente'           => 'required|array|min:1',
            'id_cliente.*'         => 'integer|exists:portal_main.cliente,id|distinct',

            'logo'                 => 'nullable|file|image|max:2048',
            'adjuntos.*'           => 'nullable|file|mimes:pdf,jpeg,png,jpg,gif,svg|max:5120',
            'eliminar_logo'        => 'nullable|boolean',
            'adjuntos_a_eliminar'  => 'nullable|string', // JSON
        ]);

        // Paths por ambiente
        $root = rtrim(
            app()->environment('production') ? env('PROD_IMAGE_PATH') : env('LOCAL_IMAGE_PATH'),
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

                            // ============ 3) Preparar archivos (guardar una vez y duplicar) ============
        $masterLogo = null; // ['filename' => ..., 'path' => ...]
        if ($request->hasFile('logo')) {
            $logo     = $request->file('logo');
            $ext      = $logo->getClientOriginalExtension();
            $filename = uniqid('logo_') . '.' . $ext;
            $dest     = $logosDir . DIRECTORY_SEPARATOR . $filename;

            // Tu helper para redimensionar:
            $this->resizeAndSaveImage($logo->getPathname(), $dest, 300, 100);

            $masterLogo = ['filename' => $filename, 'path' => $dest];
        }

        $masterAdjuntos = []; // cada item: ['original' => ..., 'filename' => ..., 'path' => ...]
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

        // ============ 4) Transacción: crear/actualizar por cliente ============
        $createdIds = [];

        DB::transaction(function () use ($request, $validated, $ids, $logosDir, $adjDir, $masterLogo, $masterAdjuntos, &$createdIds) {
            // Si se manda un único cliente y un id → actualizamos ese registro
            $isSingle = (count($ids) === 1);
            $updateId = $isSingle ? ($request->input('id') ?: null): null;

            // Si hay que eliminar logo/adjuntos aplica SOLO si es actualización 1 a 1
            $eliminarLogo          = $request->boolean('eliminar_logo');
            $adjuntosAEliminarJson = $request->input('adjuntos_a_eliminar');

            foreach ($ids as $index => $cid) {

                // 4.1) Crear/actualizar plantilla
                if ($updateId) {
                    // UPDATE del registro existente
                    $plantilla = Plantilla::findOrFail($updateId);
                    $plantilla->fill([
                        'nombre_personalizado' => $validated['nombre_personalizado'],
                        'nombre_plantilla'     => $validated['nombre_plantilla'],
                        'titulo'               => $validated['titulo'],
                        'asunto'               => $validated['asunto'] ?? null,
                        'cuerpo'               => $validated['cuerpo'],
                        'saludo'               => $validated['saludo'] ?? null,
                        'id_usuario'           => $validated['id_usuario'],
                        'id_cliente'           => $cid, // si cambió cliente, se reasigna
                        'id_portal'            => $validated['id_portal'],
                    ]);
                    $plantilla->save();
                } else {
                    // CREATE por cada cliente
                    $plantilla = Plantilla::create([
                        'nombre_personalizado' => $validated['nombre_personalizado'],
                        'nombre_plantilla'     => $validated['nombre_plantilla'],
                        'titulo'               => $validated['titulo'],
                        'asunto'               => $validated['asunto'] ?? null,
                        'cuerpo'               => $validated['cuerpo'],
                        'saludo'               => $validated['saludo'] ?? null,
                        'id_usuario'           => $validated['id_usuario'],
                        'id_cliente'           => $cid,
                        'id_portal'            => $validated['id_portal'],
                    ]);
                }

                // 4.2) Logo: si pidieron eliminar (solo en edición 1 a 1)
                if ($updateId && $eliminarLogo && $plantilla->logo_path) {
                    $rutaLogoAnt = $logosDir . DIRECTORY_SEPARATOR . $plantilla->logo_path;
                    if (is_file($rutaLogoAnt)) {
                        @unlink($rutaLogoAnt);
                    }

                    $plantilla->logo_path = null;
                    $plantilla->save();
                }

                // 4.3) Si subieron un logo nuevo, DUPLICA por cada plantilla
                if ($masterLogo) {
                    // copiar el master a un archivo nuevo para esta plantilla
                    $ext       = pathinfo($masterLogo['filename'], PATHINFO_EXTENSION);
                    $nuevoLogo = uniqid('logo_') . '.' . $ext;
                    $dest      = $logosDir . DIRECTORY_SEPARATOR . $nuevoLogo;
                    @copy($masterLogo['path'], $dest);

                    // si había logo previo en esta plantilla, elimínalo
                    if ($plantilla->logo_path) {
                        $rutaPrev = $logosDir . DIRECTORY_SEPARATOR . $plantilla->logo_path;
                        if (is_file($rutaPrev)) {
                            @unlink($rutaPrev);
                        }

                    }

                    $plantilla->logo_path = $nuevoLogo;
                    $plantilla->save();
                }

                // 4.4) Adjuntos a eliminar (solo en edición 1 a 1)
                if ($updateId && ! empty($adjuntosAEliminarJson)) {
                    $idsEliminar = json_decode($adjuntosAEliminarJson, true);
                    if (is_array($idsEliminar)) {
                        foreach ($idsEliminar as $idAdj) {
                            $adj = PlantillaAdjunto::find($idAdj);
                            if ($adj && $adj->id_plantilla == $plantilla->id) {
                                $ruta = $adjDir . DIRECTORY_SEPARATOR . $adj->archivo;
                                if (is_file($ruta)) {
                                    @unlink($ruta);
                                }

                                $adj->delete();
                            }
                        }
                    }
                }

                // 4.5) Adjuntos nuevos: DUPLICA para cada plantilla
                foreach ($masterAdjuntos as $m) {
                    $ext   = pathinfo($m['filename'], PATHINFO_EXTENSION);
                    $nuevo = uniqid('adj_') . '.' . $ext;
                    $dest  = $adjDir . DIRECTORY_SEPARATOR . $nuevo;
                    @copy($m['path'], $dest);

                    PlantillaAdjunto::create([
                        'id_plantilla'    => $plantilla->id,
                        'nombre_original' => $m['original'],
                        'archivo'         => $nuevo,
                    ]);
                }

                $createdIds[] = $plantilla->id;
            }
        });

        Log::info('✅ Plantillas creadas/actualizadas', ['ids' => $createdIds, 'clientes' => $ids]);

        return response()->json([
            'success'       => true,
            'plantilla_ids' => $createdIds,
            'count'         => count($createdIds),
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

}
