<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
}
