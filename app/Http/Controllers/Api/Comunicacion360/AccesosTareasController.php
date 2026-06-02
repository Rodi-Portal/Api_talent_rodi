<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccesosTareasController extends Controller
{
    public function historialTareas(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');

        $fechaInicio = $request->input(
            'fecha_inicio',
            now()->subDays(7)->toDateString()
        );

        $fechaFin = $request->input(
            'fecha_fin',
            now()->toDateString()
        );

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $tareas = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->select([
                DB::raw('DATE(fecha_asignacion) as fecha'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estatus = 'completada' THEN 1 ELSE 0 END) as completadas"),
                DB::raw("SUM(CASE WHEN estatus = 'pendiente' THEN 1 ELSE 0 END) as pendientes"),
                DB::raw("SUM(CASE WHEN tiene_evidencia = 1 THEN 1 ELSE 0 END) as con_evidencia"),
                DB::raw("SUM(CASE WHEN total_comentarios > 0 THEN 1 ELSE 0 END) as con_comentarios"),
                DB::raw('MIN(fecha_asignacion) as primera_asignacion'),
                DB::raw('MAX(updated_at) as ultima_actualizacion'),
            ])
            ->where('id_portal', $idPortal)
            ->where('empleado_id', $id)
            ->whereNull('deleted_at')
            ->whereBetween(DB::raw('DATE(fecha_asignacion)'), [$fechaInicio, $fechaFin])
            ->groupBy(DB::raw('DATE(fecha_asignacion)'))
            ->orderByDesc('fecha')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $tareas,
        ]);
    }

    public function tareasDia(Request $request, $id)
    {
        $idPortal = $request->input('id_portal');
        $fecha    = $request->input('fecha', now()->toDateString());

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $tareas = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas as t')
            ->where('t.id_portal', $idPortal)
            ->where('t.empleado_id', $id)
            ->whereNull('t.deleted_at')
            ->whereDate('t.fecha_asignacion', $fecha)
            ->orderBy('t.orden')
            ->orderBy('t.id')
            ->get();

        $tareaIds = $tareas->pluck('id')->values();

        $comentarios = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->where('id_portal', $idPortal)
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get()
            ->groupBy('empleado_tarea_id');

        $evidencias = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('id_portal', $idPortal)
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->where('activo', 1)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get()
            ->groupBy('empleado_tarea_id');

        return response()->json([
            'ok'   => true,
            'data' => [
                'fecha' => $fecha,
                'total' => $tareas->count(),
                'items' => $tareas->map(function ($tarea) use ($comentarios, $evidencias) {
                    $comentariosTarea = $comentarios->get($tarea->id, collect());
                    $evidenciasTarea  = $evidencias->get($tarea->id, collect());

                    return [
                        'id'                  => (int) $tarea->id,
                        'orden'               => (int) $tarea->orden,
                        'clave'               => $tarea->clave,
                        'nombre'              => $tarea->nombre,
                        'descripcion'         => $tarea->descripcion,
                        'requiere_evidencia'  => (bool) $tarea->requiere_evidencia,
                        'permite_comentarios' => (bool) $tarea->permite_comentarios,
                        'tiempo_estimado_min' => $tarea->tiempo_estimado_min,
                        'estatus'             => $tarea->estatus,
                        'porcentaje_avance'   => (int) ($tarea->porcentaje_avance ?? 0),
                        'tiene_evidencia'     => (bool) $tarea->tiene_evidencia,
                        'total_comentarios'   => (int) ($tarea->total_comentarios ?? 0),
                        'fecha_asignacion'    => $tarea->fecha_asignacion,
                        'fecha_inicio'        => $tarea->fecha_inicio,
                        'fecha_fin'           => $tarea->fecha_fin,

                        'comentarios'         => $comentariosTarea->map(function ($comentario) {
                            return [
                                'id'         => (int) $comentario->id,
                                'origen'     => $comentario->origen,
                                'comentario' => $comentario->comentario,
                                'created_at' => $comentario->created_at,
                            ];
                        })->values(),

                        'evidencias'          => $evidenciasTarea->map(function ($evidencia) {
                            return [
                                'id'              => (int) $evidencia->id,
                                'nombre_original' => $evidencia->nombre_original,
                                'nombre_archivo'  => $evidencia->nombre_archivo,
                                'mime_type'       => $evidencia->mime_type,
                                'extension'       => $evidencia->extension,
                                'peso_bytes'      => $evidencia->peso_bytes,
                                'created_at'      => $evidencia->created_at,
                            ];
                        })->values(),
                    ];
                })->values(),
            ],
        ]);
    }

    public function evidenciaTarea(
        Request $request,
        $id,
        $idTarea,
        $idEvidencia
    ) {
        $idPortal = $request->input('id_portal');

        if (! $idPortal) {
            return response()->json([
                'ok'      => false,
                'message' => 'id_portal es requerido',
            ], 422);
        }

        $evidencia = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('id', $idEvidencia)
            ->where('id_portal', $idPortal)
            ->where('empleado_tarea_id', $idTarea)
            ->whereNull('deleted_at')
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

        $relativePath = ltrim($evidencia->ruta_archivo, '/\\');

        $fullPath = $basePath . DIRECTORY_SEPARATOR . $relativePath;

        if (! file_exists($fullPath)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Archivo no encontrado',
            ], 404);
        }

        $fileContent = file_get_contents($fullPath);

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'       => $evidencia->id,
                'filename' => $evidencia->nombre_original,
                'mime'     => $evidencia->mime_type,
                'base64'   => 'data:' .
                $evidencia->mime_type .
                ';base64,' .
                base64_encode($fileContent),
            ],
        ]);
    }
}
