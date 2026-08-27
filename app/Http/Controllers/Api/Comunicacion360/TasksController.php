<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Tareas;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TasksController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}
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
        $administrator = $this->administrator($request);

        $employee = $this->authorizedEmployee(
            $administrator,
            $id
        );

        $conexion = DB::connection('portal_main');

        $tareas = $conexion
            ->table('comunicacion360_empleado_tareas')
            ->where('empleado_id', (int) $employee->id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
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
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->groupBy('empleado_tarea_id');

        $evidencias = $conexion
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
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
                        'fecha'      => $comentario->created_at
                            ? Carbon::parse(
                            $comentario->created_at
                        )->format('Y-m-d H:i')
                            : null,

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
    public function storeComentarioEmpleado(
        Request $request,
        int $id
    ) {
        $validated = $request->validate([
            'comentario' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $administrator = $this->administrator($request);

        $task = $this->authorizedEmployeeTask(
            $administrator,
            $id
        );

        $conexion = DB::connection('portal_main');

        $commentId = $conexion->transaction(
            function () use (
                $conexion,
                $administrator,
                $task,
                $validated
            ) {
                $commentId = $conexion
                    ->table(
                        'comunicacion360_empleado_tarea_comentarios'
                    )
                    ->insertGetId([
                        'id_portal'         =>
                        (int) $administrator->id_portal,
                        'empleado_tarea_id' => (int) $task->id,
                        'id_usuario'        =>
                        (int) $administrator->id,
                        'origen'            => 'admin',
                        'comentario'        =>
                        $validated['comentario'],
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                $conexion
                    ->table('comunicacion360_empleado_tareas')
                    ->where('id', (int) $task->id)
                    ->where(
                        'id_portal',
                        (int) $administrator->id_portal
                    )
                    ->whereNull('deleted_at')
                    ->increment('total_comentarios');

                return (int) $commentId;
            }
        );

        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $task->alcance_id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'tarea_empleado_comentario',
            'entidad_id'   => $commentId,
            'accion'       => 'crear_comentario',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'Comentario administrativo agregado a una tarea.',
            'datos_nuevos' => [
                'empleado_tarea_id' => (int) $task->id,
                'empleado_id'       => (int) $task->empleado_id,
                'origen'            => 'admin',
            ],
        ], $request);

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'         => $commentId,
                'id_usuario' => (int) $administrator->id,
                'texto'      => $validated['comentario'],
                'fecha'      => now()->format('Y-m-d H:i'),
                'origen'     => 'admin',
            ],
        ], 201);
    }

    /**
     * POST /api/comunicacion360/tasks/empleado-tarea/{id}/reabrir
     */
    public function reabrirTareaEmpleado(
        Request $request,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $task = $this->authorizedEmployeeTask(
            $administrator,
            $id
        );

        DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->where('id', (int) $task->id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereNull('deleted_at')
            ->update([
                'estatus'           => 'pendiente',
                'porcentaje_avance' => 0,
                'fecha_fin'         => null,
                'updated_at'        => now(),
            ]);

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $task->alcance_id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'tarea_empleado',
            'entidad_id'       => (int) $task->id,
            'accion'           => 'reabrir',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Tarea de empleado reabierta por un administrador.',
            'datos_anteriores' => [
                'estatus'           => $task->estatus,
                'porcentaje_avance' =>
                (int) $task->porcentaje_avance,
                'fecha_fin'         => $task->fecha_fin,
            ],
            'datos_nuevos'     => [
                'estatus'           => 'pendiente',
                'porcentaje_avance' => 0,
                'fecha_fin'         => null,
                'empleado_id'       => (int) $task->empleado_id,
            ],
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' =>
            'La tarea fue reabierta correctamente.',
        ]);
    }
    /**
     * DELETE /api/comunicacion360/tasks/comentarios/{id}
     */
    public function deleteComentarioEmpleado(
        Request $request,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $conexion = DB::connection('portal_main');

        $comment = $conexion
            ->table(
                'comunicacion360_empleado_tarea_comentarios as c'
            )
            ->join(
                'comunicacion360_empleado_tareas as et',
                'et.id',
                '=',
                'c.empleado_tarea_id'
            )
            ->join(
                'empleados as e',
                'e.id',
                '=',
                'et.empleado_id'
            )
            ->where('c.id', $id)
            ->where(
                'c.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'et.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'e.id_portal',
                (int) $administrator->id_portal
            )
            ->whereNull('c.deleted_at')
            ->whereNull('et.deleted_at')
            ->where('e.eliminado', 0)
            ->first([
                'c.id',
                'c.empleado_tarea_id',
                'c.id_usuario',
                'c.origen',
                'c.created_at',
                'et.empleado_id',
                'e.id_cliente as alcance_id_cliente',
            ]);

        if (! $comment || ! $comment->alcance_id_cliente) {
            throw new AuthorizationException(
                'El comentario no pertenece al alcance administrativo.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $comment->alcance_id_cliente]
        );

        if ($comment->origen !== 'admin') {
            throw new AuthorizationException(
                'Solo se pueden eliminar comentarios administrativos.'
            );
        }

        $conexion->transaction(
            function () use (
                $conexion,
                $administrator,
                $comment
            ) {
                $conexion
                    ->table(
                        'comunicacion360_empleado_tarea_comentarios'
                    )
                    ->where('id', (int) $comment->id)
                    ->where(
                        'id_portal',
                        (int) $administrator->id_portal
                    )
                    ->whereNull('deleted_at')
                    ->update([
                        'deleted_at' => now(),
                        'updated_at' => now(),
                    ]);

                $conexion
                    ->table('comunicacion360_empleado_tareas')
                    ->where(
                        'id',
                        (int) $comment->empleado_tarea_id
                    )
                    ->where(
                        'id_portal',
                        (int) $administrator->id_portal
                    )
                    ->whereNull('deleted_at')
                    ->where('total_comentarios', '>', 0)
                    ->decrement('total_comentarios');
            }
        );

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $comment->alcance_id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'tarea_empleado_comentario',
            'entidad_id'       => (int) $comment->id,
            'accion'           => 'eliminar_comentario',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Comentario administrativo eliminado de una tarea.',
            'datos_anteriores' => [
                'empleado_tarea_id' =>
                (int) $comment->empleado_tarea_id,
                'empleado_id'       => (int) $comment->empleado_id,
                'id_usuario'        => $comment->id_usuario !== null
                    ? (int) $comment->id_usuario
                    : null,
                'origen'            => $comment->origen,
                'created_at'        => $comment->created_at,
                'deleted_at'        => null,
            ],
            'datos_nuevos'     => [
                'deleted_at' => now(),
            ],
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' =>
            'Comentario eliminado correctamente.',
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
    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }

    private function administratorName(
        AdministradorAuth $administrator
    ): ?string {
        $name = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        return $name !== '' ? $name : null;
    }

    private function authorizedEmployee(
        AdministradorAuth $administrator,
        int $employeeId
    ): object {
        $employee = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $employeeId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('eliminado', 0)
            ->first([
                'id',
                'id_portal',
                'id_cliente',
                'id_empleado',
                'nombre',
                'paterno',
                'materno',
            ]);

        if (! $employee || ! $employee->id_cliente) {
            throw new AuthorizationException(
                'El empleado no pertenece al alcance administrativo.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        return $employee;
    }

    private function authorizedEmployeeTask(
        AdministradorAuth $administrator,
        int $taskId
    ): object {
        $task = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas as et')
            ->join('empleados as e', 'e.id', '=', 'et.empleado_id')
            ->where('et.id', $taskId)
            ->where(
                'et.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'e.id_portal',
                (int) $administrator->id_portal
            )
            ->where('e.eliminado', 0)
            ->whereNull('et.deleted_at')
            ->first([
                'et.*',
                'e.id_cliente as alcance_id_cliente',
            ]);

        if (! $task || ! $task->alcance_id_cliente) {
            throw new AuthorizationException(
                'La tarea no pertenece al alcance administrativo.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $task->alcance_id_cliente]
        );

        return $task;
    }
}
