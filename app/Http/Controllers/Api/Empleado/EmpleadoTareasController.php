<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmpleadoTareasController extends Controller
{
    /**
     * GET /api/empleado/tareas
     * Lista tareas del empleado autenticado
     */
    public function index(Request $request)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $conexion = DB::connection('portal_main');
        $ahora    = now();

        $asignacionesRecurrentes = $conexion
            ->table('comunicacion360_plantilla_asignaciones')
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->where('tipo', 'recurrente')
            ->where('estatus', 'asignada')
            ->whereNull('deleted_at')
            ->get();

        foreach ($asignacionesRecurrentes as $asignacion) {
            $debeGenerar = $this->debeGenerarRecurrencia(
                $asignacion->frecuencia,
                $asignacion->ultima_generacion,
                $ahora
            );

            if (! $debeGenerar) {
                continue;
            }

            $conexion->transaction(function () use ($conexion, $asignacion, $empleado, $ahora) {
                $asignacionActual = $conexion
                    ->table('comunicacion360_plantilla_asignaciones')
                    ->where('id', $asignacion->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if (! $asignacionActual) {
                    return;
                }

                $sigueDebiendoGenerar = $this->debeGenerarRecurrencia(
                    $asignacionActual->frecuencia,
                    $asignacionActual->ultima_generacion,
                    $ahora
                );

                if (! $sigueDebiendoGenerar) {
                    return;
                }

                $tareasPlantilla = $conexion
                    ->table('comunicacion360_plantilla_tareas')
                    ->where('id_portal', $empleado->id_portal)
                    ->where('plantilla_id', $asignacionActual->plantilla_id)
                    ->where('activa', 1)
                    ->whereNull('deleted_at')
                    ->orderBy('orden')
                    ->get();

                $tareasInsertadas = 0;

                foreach ($tareasPlantilla as $tarea) {
                    $conexion
                        ->table('comunicacion360_empleado_tareas')
                        ->insert([
                            'id_portal'               => $empleado->id_portal,
                            'empleado_id'             => $empleado->id,
                            'plantilla_id'            => $asignacionActual->plantilla_id,
                            'plantilla_asignacion_id' => $asignacionActual->id,
                            'plantilla_tarea_id'      => $tarea->id,
                            'tarea_catalogo_id'       => $tarea->tarea_catalogo_id,
                            'orden'                   => $tarea->orden,
                            'clave'                   => $tarea->clave_snapshot,
                            'nombre'                  => $tarea->nombre_snapshot,
                            'descripcion'             => $tarea->descripcion_snapshot,
                            'requiere_evidencia'      => (bool) $tarea->requiere_evidencia,
                            'permite_comentarios'     => (bool) $tarea->permite_comentarios,
                            'tiempo_estimado_min'     => $tarea->tiempo_estimado_min,
                            'estatus'                 => 'pendiente',
                            'porcentaje_avance'       => 0,
                            'tiene_evidencia'         => 0,
                            'total_comentarios'       => 0,
                            'fecha_asignacion'        => $ahora,
                            'fecha_inicio'            => null,
                            'fecha_fin'               => null,
                            'created_at'              => $ahora,
                            'updated_at'              => $ahora,
                        ]);

                    $tareasInsertadas++;
                }

                if ($tareasInsertadas > 0) {
                    $conexion
                        ->table('comunicacion360_plantilla_asignaciones')
                        ->where('id', $asignacionActual->id)
                        ->update([
                            'ultima_generacion' => $ahora,
                            'updated_at'        => $ahora,
                        ]);
                }
            });
        }

        $tareas = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($tareas->isEmpty()) {
            return response()->json([
                'ok'   => true,
                'data' => [],
            ]);
        }

        $tareaIds = $tareas->pluck('id')->values()->all();

        $comentarios = $conexion
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->where('id_portal', $empleado->id_portal)
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('empleado_tarea_id');

        $evidencias = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('id_portal', $empleado->id_portal)
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->where('activo', 1)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('empleado_tarea_id');

        $data = $tareas->map(function ($tarea) use ($comentarios, $evidencias) {
            $comentariosTarea = collect($comentarios->get($tarea->id, []))
                ->map(function ($comentario) {
                    return [
                        'id'         => (int) $comentario->id,
                        'id_usuario' => $comentario->id_usuario !== null ? (int) $comentario->id_usuario : null,
                        'origen'     => $comentario->origen,
                        'texto'      => $comentario->comentario,
                        'fecha'      => optional($comentario->created_at)->format('Y-m-d H:i'),
                        'created_at' => $comentario->created_at,
                    ];
                })
                ->values();

            $evidenciaActual = collect($evidencias->get($tarea->id, []))->first();

            return [
                'id'                      => (int) $tarea->id,
                'id_portal'               => (int) $tarea->id_portal,
                'empleado_id'             => (int) $tarea->empleado_id,
                'plantilla_id'            => (int) $tarea->plantilla_id,
                'plantilla_asignacion_id' => (int) $tarea->plantilla_asignacion_id,
                'plantilla_tarea_id'      => (int) $tarea->plantilla_tarea_id,
                'tarea_catalogo_id'       => (int) $tarea->tarea_catalogo_id,
                'orden'                   => (int) $tarea->orden,
                'clave'                   => $tarea->clave,
                'nombre'                  => $tarea->nombre,
                'descripcion'             => $tarea->descripcion,
                'requiere_evidencia'      => (bool) $tarea->requiere_evidencia,
                'permite_comentarios'     => (bool) $tarea->permite_comentarios,
                'tiempo_estimado_min'     => $tarea->tiempo_estimado_min !== null ? (int) $tarea->tiempo_estimado_min : null,
                'estatus'                 => $tarea->estatus,
                'porcentaje_avance'       => (int) $tarea->porcentaje_avance,
                'tiene_evidencia'         => (bool) $tarea->tiene_evidencia,
                'total_comentarios'       => (int) $tarea->total_comentarios,
                'fecha_asignacion'        => $tarea->fecha_asignacion,
                'fecha_inicio'            => $tarea->fecha_inicio,
                'fecha_fin'               => $tarea->fecha_fin,
                'created_at'              => $tarea->created_at,
                'updated_at'              => $tarea->updated_at,
                'deleted_at'              => $tarea->deleted_at,

                'comentarios'             => $comentariosTarea,

                'evidencia'               => $evidenciaActual ? [
                    'id'             => (int) $evidenciaActual->id,
                    'nombre'         => $evidenciaActual->nombre_original,
                    'nombre_archivo' => $evidenciaActual->nombre_archivo,
                    'ruta_archivo'   => $evidenciaActual->ruta_archivo,
                    'mime_type'      => $evidenciaActual->mime_type,
                    'extension'      => $evidenciaActual->extension,
                    'size'           => $evidenciaActual->peso_bytes !== null ? (int) $evidenciaActual->peso_bytes : null,
                    'url'            => $evidenciaActual->ruta_archivo,
                    'created_at'     => $evidenciaActual->created_at,
                ] : null,
            ];
        })->values();

        return response()->json([
            'ok'   => true,
            'data' => $data,
        ]);
    }
    private function debeGenerarRecurrencia(?string $frecuencia, $ultimaGeneracion, $ahora): bool
    {
        if (! $frecuencia) {
            return false;
        }

        if (! $ultimaGeneracion) {
            return true;
        }

        $ultima = Carbon::parse($ultimaGeneracion);
        $actual = $ahora instanceof Carbon ? $ahora : Carbon::parse($ahora);

        return match ($frecuencia) {
            'diaria'  => ! $ultima->isSameDay($actual),
            'semanal' => ! $ultima->isSameWeek($actual),
            'mensual' => ! $ultima->isSameMonth($actual),
            default   => false,
        };
    }

    public function toggle(Request $request, $id)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $tarea = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        // 🔥 lógica simple
        $nuevoEstado = $tarea->estatus === 'completada'
            ? 'pendiente'
            : 'completada';

        DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->update([
                'estatus'    => $nuevoEstado,
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok'      => true,
            'estatus' => $nuevoEstado,
        ]);
    }

    public function storeComentario(Request $request, $id)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $request->validate([
            'comentario' => 'required|string|max:2000',
        ]);

        // 🔍 validar tarea del empleado
        $tarea = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        // 💾 guardar comentario
        $comentarioId = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->insertGetId([
                'id_portal'         => $empleado->id_portal,
                'empleado_tarea_id' => $id,           // 🔥 IMPORTANTE
                'id_usuario'        => $empleado->id, // 🔥 IMPORTANTE
                'origen'            => 'empleado',
                'comentario'        => $request->comentario,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

        // 🔢 contador (ya lo tienes en tabla tareas)
        DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->increment('total_comentarios');

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'    => $comentarioId,
                'texto' => $request->comentario,
                'fecha' => now()->format('Y-m-d H:i'),
            ],
        ]);
    }

    private function guardarEvidenciaTarea(
        \Illuminate\Http\UploadedFile $file,
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): array {
        $mes = Carbon::parse($fecha)->format('Y-m');

        $relativeDir = "_evidenciasTarea/{$idPortal}/{$idCliente}/{$idEmpleado}/{$mes}";

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        $fullDir = rtrim($basePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (! File::exists($fullDir)) {
            File::makeDirectory($fullDir, 0755, true);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        $filename = 'evidencia_tarea_' . now()->format('Ymd_His')
        . '_' . Str::random(10)
            . '.' . $extension;

        $originalName = $file->getClientOriginalName();
        $mimeType     = $file->getClientMimeType();
        $size         = $file->getSize();
        $extension    = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        $filename = 'evidencia_tarea_' . now()->format('Ymd_His')
        . '_' . Str::random(10)
            . '.' . $extension;

        $file->move($fullDir, $filename);

        return [
            'relative_path' => $relativeDir . '/' . $filename,
            'filename'      => $filename,
            'original_name' => $originalName,
            'extension'     => $extension,
            'mime_type'     => $mimeType,
            'size'          => $size,
        ];
    }
    public function uploadEvidencia(Request $request, $id)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $request->validate([
            'archivo' => [
                'required',
                'file',
                'max:20480', // 20MB
                'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx',
            ],
        ]);

        $conexion = DB::connection('portal_main');

        $tarea = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('empleado_id', $empleado->id)
            ->where('id_portal', $empleado->id_portal)
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        $archivo = $request->file('archivo');

        $stored = $this->guardarEvidenciaTarea(
            $archivo,
            (int) $empleado->id_portal,
            (int) $empleado->id_cliente,
            (int) $empleado->id,
            now()->toDateString()
        );

        $evidenciaId = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->insertGetId([
                'id_portal'         => $empleado->id_portal,
                'empleado_tarea_id' => $tarea->id,

                'nombre_original'   => $stored['original_name'],
                'nombre_archivo'    => $stored['filename'],
                'ruta_archivo'      => $stored['relative_path'],

                'mime_type'         => $stored['mime_type'],
                'extension'         => $stored['extension'],
                'peso_bytes'        => $stored['size'],

                'activo'            => 1,

                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

        $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $tarea->id)
            ->update([
                'tiene_evidencia' => 1,
                'updated_at'      => now(),
            ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Evidencia subida correctamente.',
            'data'    => [
                'id'   => $evidenciaId,
                'name' => $stored['original_name'],
                'url'  => url("/api/empleado/tareas/{$tarea->id}/evidencia/{$evidenciaId}/ver"),
                'type'      => $stored['mime_type'],
                'size'      => $stored['size'],
                'extension' => $stored['extension'],
            ],
        ]);
    }
    public function verEvidencia(Request $request, $id, $evidenciaId)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $conexion = DB::connection('portal_main');

        $evidencia = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias as e')
            ->join('comunicacion360_empleado_tareas as t', 't.id', '=', 'e.empleado_tarea_id')
            ->where('e.id', $evidenciaId)
            ->where('e.empleado_tarea_id', $id)
            ->where('e.id_portal', $empleado->id_portal)
            ->where('t.empleado_id', $empleado->id)
            ->where('e.activo', 1)
            ->whereNull('e.deleted_at')
            ->first();

        if (! $evidencia) {
            return response()->json([
                'ok'      => false,
                'message' => 'Evidencia no encontrada',
            ], 404);
        }

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $evidencia->ruta_archivo);

        if (! File::exists($fullPath)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Archivo no encontrado',
            ], 404);
        }

        return response()->file($fullPath, [
            'Content-Type'        => $evidencia->mime_type,
            'Content-Disposition' => 'inline; filename="' . $evidencia->nombre_original . '"',
        ]);
    }

    public function deleteEvidencia(Request $request, $id, $evidenciaId)
    {
        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $conexion = DB::connection('portal_main');

        $evidencia = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias as e')
            ->join('comunicacion360_empleado_tareas as t', 't.id', '=', 'e.empleado_tarea_id')
            ->where('e.id', $evidenciaId)
            ->where('e.empleado_tarea_id', $id)
            ->where('e.id_portal', $empleado->id_portal)
            ->where('t.empleado_id', $empleado->id)
            ->where('e.activo', 1)
            ->whereNull('e.deleted_at')
            ->select('e.id')
            ->first();

        if (! $evidencia) {
            return response()->json([
                'ok'      => false,
                'message' => 'Evidencia no encontrada',
            ], 404);
        }

        $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('id', $evidenciaId)
            ->update([
                'activo'     => 0,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $hayActivas = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('empleado_tarea_id', $id)
            ->where('activo', 1)
            ->whereNull('deleted_at')
            ->exists();

        $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->update([
                'tiene_evidencia'   => $hayActivas ? 1 : 0,
                'estatus'           => $hayActivas ? DB::raw('estatus') : 'pendiente',
                'porcentaje_avance' => $hayActivas ? DB::raw('porcentaje_avance') : 0,
                'fecha_fin'         => $hayActivas ? DB::raw('fecha_fin') : null,
                'updated_at'        => now(),
            ]);
        return response()->json([
            'ok'      => true,
            'message' => 'Evidencia eliminada correctamente.',
        ]);
    }
    public function validarUbicacion(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'latitud'   => ['required', 'numeric'],
            'longitud'  => ['required', 'numeric'],
            'precision' => ['nullable', 'numeric'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'Datos de ubicación inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $empleado = auth('sanctum')->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $conexion = DB::connection('portal_main');

        $idEmpleado = (int) $empleado->id;
        $idPortal   = (int) $empleado->id_portal;
        $idCliente  = (int) $empleado->id_cliente;
        $hoy        = now()->toDateString();

        // 1. Validar tarea real del módulo actual
        $tarea = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('empleado_id', $idEmpleado)
            ->where('id_portal', $idPortal)
            ->whereNull('deleted_at')
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'La tarea no existe o no pertenece al empleado.',
            ], 404);
        }

        // 2. Buscar asignación activa vigente del checador
        $asignacion = $conexion
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->where(function ($q) use ($hoy) {
                $q->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $hoy);
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion || ! $asignacion->id_plantilla_checada) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'El empleado no tiene una plantilla de checada activa.',
            ], 404);
        }

        // 3. Buscar plantilla de checada activa
        $plantilla = $conexion
            ->table('checador_checada_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_checada)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'La plantilla de checada no está activa o no existe.',
            ], 404);
        }

        // 4. Si la plantilla no requiere ubicación, permitir
        if ((int) $plantilla->requiere_ubicacion !== 1) {
            return response()->json([
                'ok'      => true,
                'dentro'  => true,
                'message' => 'La plantilla no requiere validación de ubicación.',
                'data'    => [
                    'ubicacion'        => null,
                    'distancia_metros' => null,
                    'radio_metros'     => null,
                ],
            ]);
        }

        // 5. Obtener ubicaciones permitidas
        $ubicaciones = $conexion
            ->table('checador_checada_plantilla_ubicaciones as pu')
            ->join('checador_ubicaciones as u', 'u.id', '=', 'pu.id_ubicacion')
            ->where('pu.id_plantilla', (int) $plantilla->id)
            ->where('pu.activo', 1)
            ->where('u.id_portal', $idPortal)
            ->where('u.id_cliente', $idCliente)
            ->where('u.activa', 1)
            ->select([
                'u.id',
                'u.nombre',
                'u.tipo_zona',
                'u.latitud',
                'u.longitud',
                'u.radio_metros',
                'u.polygon_json',
            ])
            ->get();

        if ($ubicaciones->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'dentro'  => false,
                'message' => 'La plantilla requiere ubicación, pero no tiene ubicaciones permitidas configuradas.',
            ], 422);
        }

        $latitudEmpleado  = (float) $request->latitud;
        $longitudEmpleado = (float) $request->longitud;

        // 6. Validar círculos
        foreach ($ubicaciones as $ubicacion) {
            if ($ubicacion->tipo_zona !== 'circle') {
                continue;
            }

            if ($ubicacion->latitud === null || $ubicacion->longitud === null) {
                continue;
            }

            $distanciaMetros = $this->calcularDistanciaMetros(
                $latitudEmpleado,
                $longitudEmpleado,
                (float) $ubicacion->latitud,
                (float) $ubicacion->longitud
            );

            $radioMetros = (float) ($ubicacion->radio_metros ?: 100);

            if ($distanciaMetros <= $radioMetros) {
                return response()->json([
                    'ok'      => true,
                    'dentro'  => true,
                    'message' => 'Ubicación válida.',
                    'data'    => [
                        'ubicacion'        => [
                            'id'     => (int) $ubicacion->id,
                            'nombre' => $ubicacion->nombre,
                        ],
                        'distancia_metros' => round($distanciaMetros, 2),
                        'radio_metros'     => $radioMetros,
                    ],
                ]);
            }
        }

        return response()->json([
            'ok'      => true,
            'dentro'  => false,
            'message' => 'Estás fuera de las ubicaciones permitidas.',
        ]);
    }
    private function calcularDistanciaMetros(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $radioTierra = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLon / 2) *
        sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radioTierra * $c;
    }

}
