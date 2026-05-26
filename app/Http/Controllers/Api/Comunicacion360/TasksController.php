<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Tareas;
use Illuminate\Http\Request;

class TasksController extends Controller
{
    /**
     * GET /api/comunicacion360/tasks
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $tasks = \App\Models\Comunicacion360\Tareas::query()
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'id_portal',
                'clave',
                'nombre',
                'descripcion',
                'requiere_evidencia',
                'permite_comentarios',
                'tiempo_estimado_min',
                'activa',
                'created_at',
                'updated_at',
            ]);

        return response()->json([
            'ok'   => true,
            'code' => 'TASKS_LIST',
            'data' => $tasks,
        ]);
    }

    /**
     * POST /api/comunicacion360/tasks
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_portal'           => ['required', 'integer'],
            'nombre'              => ['required', 'string', 'max:150'],
            'descripcion'         => ['nullable', 'string'],
            'requiere_evidencia'  => ['required', 'boolean'],
            'tiempo_estimado_min' => ['nullable', 'integer', 'min:1'],
            'activa'              => ['required', 'boolean'],
        ]);

        $task = Tareas::create([
            'id_portal'           => $validated['id_portal'],
            'clave'               => 'TASK-' . time(),
            'nombre'              => $validated['nombre'],
            'descripcion'         => $validated['descripcion'] ?? null,
            'requiere_evidencia'  => $validated['requiere_evidencia'],
            'permite_comentarios' => 1,
            'tiempo_estimado_min' => $validated['tiempo_estimado_min'] ?? null,
            'activa'              => $validated['activa'],
        ]);

        return response()->json([
            'ok'   => true,
            'code' => 'TASK_CREATED',
            'data' => [
                'id'                  => $task->id,
                'id_portal'           => $task->id_portal,
                'clave'               => $task->clave,
                'nombre'              => $task->nombre,
                'descripcion'         => $task->descripcion,
                'requiere_evidencia'  => (bool) $task->requiere_evidencia,
                'permite_comentarios' => (bool) $task->permite_comentarios,
                'tiempo_estimado_min' => $task->tiempo_estimado_min,
                'activa'              => (bool) $task->activa,
            ],
        ], 201);
    }

    /**
     * GET /api/comunicacion360/tasks/{id}
     */
    public function show(int $id)
    {
        return response()->json([
            'ok'   => true,
            'code' => 'TASK_FOUND',
            'data' => [
                'id'                 => $id,
                'nombre'             => 'Tarea demo',
                'descripcion'        => 'Descripción demo',
                'requiere_evidencia' => true,
                'tiempo'             => '1_hr',
                'activa'             => true,
            ],
        ]);
    }
    /**
     * GET /api/comunicacion360/tasks/empleado/{id}
     */
    public function empleado(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $conexion = \DB::connection('portal_main');

        $tareas = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('empleado_id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->whereDate('created_at', now()->toDateString())
            ->orderBy('orden')
            ->orderByDesc('created_at')
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
            ->where('id_portal', $validated['id_portal'])
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('empleado_tarea_id');

        $evidencias = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where('id_portal', $validated['id_portal'])
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
                        'id_usuario' => $comentario->id_usuario !== null
                            ? (int) $comentario->id_usuario
                            : null,

                        'origen'     => $comentario->origen,
                        'texto'      => $comentario->comentario,
                        'fecha'      => optional($comentario->created_at)
                            ->format('Y-m-d H:i'),

                        'created_at' => $comentario->created_at,
                    ];
                })
                ->values();

            $evidenciaActual = collect(
                $evidencias->get($tarea->id, [])
            )->first();
            $evidenciaBase64 = null;

            if ($evidenciaActual && ! empty($evidenciaActual->ruta_archivo)) {
                $basePath = app()->environment('production')
                    ? config('paths.prod_images')
                    : config('paths.local_images');

                $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $evidenciaActual->ruta_archivo);

                if (file_exists($fullPath)) {
                    $mime = mime_content_type($fullPath);

                    $evidenciaBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
                }
            }

            return [
                'id'                 => (int) $tarea->id,
                'nombre'             => $tarea->nombre,
                'descripcion'        => $tarea->descripcion,

                'estatus'            => $tarea->estatus,

                'porcentaje_avance'  => (int) $tarea->porcentaje_avance,

                'requiere_evidencia' => (bool) $tarea->requiere_evidencia,

                'tiene_evidencia'    => (bool) $tarea->tiene_evidencia,

                'fecha_asignacion'   => $tarea->fecha_asignacion,
                'fecha_inicio'       => $tarea->fecha_inicio,
                'fecha_fin'          => $tarea->fecha_fin,

                'created_at'         => $tarea->created_at,
                'updated_at'         => $tarea->updated_at,

                'comentarios'        => $comentariosTarea,

                'evidencia'          => $evidenciaActual ? [
                    'id'             => (int) $evidenciaActual->id,
                    'nombre'         => $evidenciaActual->nombre_original,
                    'nombre_archivo' => $evidenciaActual->nombre_archivo,
                    'ruta_archivo'   => $evidenciaActual->ruta_archivo,
                    'mime_type'      => $evidenciaActual->mime_type,
                    'extension'      => $evidenciaActual->extension,
                    'base64'         => $evidenciaBase64,
                    'size'           => $evidenciaActual->peso_bytes !== null
                        ? (int) $evidenciaActual->peso_bytes
                        : null,

                    'created_at'     => $evidenciaActual->created_at,
                ] : null,
            ];
        })->values();

        return response()->json([
            'ok'   => true,
            'data' => $data,
        ]);
    }
    /**
     * POST /api/comunicacion360/tasks/empleado-tarea/{id}/comentarios
     */
    public function storeComentarioEmpleado(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal'  => ['required', 'integer'],
            'comentario' => ['required', 'string', 'max:2000'],
        ]);

        $conexion = \DB::connection('portal_main');

        $tarea = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        $comentarioId = $conexion
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->insertGetId([
                'id_portal'         => $validated['id_portal'],
                'empleado_tarea_id' => $tarea->id,
                'id_usuario'        => null,
                'origen'            => 'admin',
                'comentario'        => $validated['comentario'],
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

        $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $tarea->id)
            ->increment('total_comentarios');

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'     => $comentarioId,
                'texto'  => $validated['comentario'],
                'fecha'  => now()->format('Y-m-d H:i'),
                'origen' => 'admin',
            ],
        ]);
    }

    /**
     * POST /api/comunicacion360/tasks/empleado-tarea/{id}/reabrir
     */
    public function reabrirTareaEmpleado(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $conexion = \DB::connection('portal_main');

        $tarea = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->first();

        if (! $tarea) {
            return response()->json([
                'ok'      => false,
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $tarea->id)
            ->update([
                'estatus'           => 'pendiente',
                'porcentaje_avance' => 0,
                'fecha_fin'         => null,
                'updated_at'        => now(),
            ]);

        return response()->json([
            'ok'      => true,
            'message' => 'La tarea fue reabierta correctamente.',
        ]);
    }
    /**
     * DELETE /api/comunicacion360/tasks/comentarios/{id}
     */
    public function deleteComentarioEmpleado(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $conexion = \DB::connection('portal_main');

        $comentario = $conexion
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->first();

        if (! $comentario) {
            return response()->json([
                'ok'      => false,
                'message' => 'Comentario no encontrado',
            ], 404);
        }

        // SOLO comentarios admin
        if ($comentario->origen !== 'admin') {
            return response()->json([
                'ok'      => false,
                'message' => 'Solo se pueden eliminar comentarios administrativos.',
            ], 403);
        }

        $conexion
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->where('id', $comentario->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('id', $comentario->empleado_tarea_id)
            ->decrement('total_comentarios');

        return response()->json([
            'ok'      => true,
            'message' => 'Comentario eliminado correctamente.',
        ]);
    }
    /**
     * PUT /api/comunicacion360/tasks/{id}
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal'           => ['required', 'integer'],
            'nombre'              => ['required', 'string', 'max:150'],
            'descripcion'         => ['nullable', 'string'],
            'requiere_evidencia'  => ['required', 'boolean'],
            'tiempo_estimado_min' => ['nullable', 'integer', 'min:1'],
            'activa'              => ['required', 'boolean'],
        ]);

        $task = Tareas::query()
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->firstOrFail();

        $task->update([
            'nombre'              => $validated['nombre'],
            'descripcion'         => $validated['descripcion'] ?? null,
            'requiere_evidencia'  => $validated['requiere_evidencia'],
            'tiempo_estimado_min' => $validated['tiempo_estimado_min'] ?? null,
            'activa'              => $validated['activa'],
        ]);

        return response()->json([
            'ok'   => true,
            'code' => 'TASK_UPDATED',
            'data' => [
                'id'                  => $task->id,
                'id_portal'           => $task->id_portal,
                'clave'               => $task->clave,
                'nombre'              => $task->nombre,
                'descripcion'         => $task->descripcion,
                'requiere_evidencia'  => (bool) $task->requiere_evidencia,
                'permite_comentarios' => (bool) $task->permite_comentarios,
                'tiempo_estimado_min' => $task->tiempo_estimado_min,
                'activa'              => (bool) $task->activa,
            ],
        ]);
    }

    /**
     * DELETE /api/comunicacion360/tasks/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $task = Tareas::query()
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->firstOrFail();

        $task->delete();

        return response()->json([
            'ok'   => true,
            'code' => 'TASK_DELETED',
            'data' => [
                'id' => $id,
            ],
        ]);
    }

}
