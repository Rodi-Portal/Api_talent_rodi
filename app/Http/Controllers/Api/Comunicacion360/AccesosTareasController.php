<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Checador\TaskEvidencePathService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccesosTareasController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria,
        private TaskEvidencePathService $evidencePaths
    ) {}

    public function historialTareas(Request $request, $id)
    {
        $validated = $request->validate([
            'fecha_inicio' => [
                'nullable',
                'date_format:Y-m-d',
            ],
            'fecha_fin'    => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:fecha_inicio',
            ],
        ]);

        $administrator = $this->administrator($request);

        $employee = $this->authorizedEmployee(
            $administrator,
            (int) $id
        );

        $fechaInicio = $validated['fecha_inicio'] ?? now()->subDays(7)->toDateString();

        $fechaFin = $validated['fecha_fin'] ?? now()->toDateString();

        $inicio = Carbon::createFromFormat(
            'Y-m-d',
            $fechaInicio
        )->startOfDay();

        $fin = Carbon::createFromFormat(
            'Y-m-d',
            $fechaFin
        )->startOfDay();

        if ($inicio->diffInDays($fin) > 366) {
            throw ValidationException::withMessages([
                'fecha_fin' => [
                    'El periodo no puede superar 366 días.',
                ],
            ]);
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
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'empleado_id',
                (int) $employee->id
            )
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
        $validated = $request->validate([
            'fecha' => [
                'nullable',
                'date_format:Y-m-d',
            ],
        ]);

        $administrator = $this->administrator($request);

        $employee = $this->authorizedEmployee(
            $administrator,
            (int) $id
        );

        $fecha = $validated['fecha'] ?? now()->toDateString();

        $tareas = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas as t')
            ->where(
                't.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                't.empleado_id',
                (int) $employee->id
            )
            ->whereNull('t.deleted_at')
            ->whereDate('t.fecha_asignacion', $fecha)
            ->orderBy('t.orden')
            ->orderBy('t.id')
            ->get();

        $tareaIds = $tareas->pluck('id')->values();

        $comentarios = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_comentarios')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('empleado_tarea_id', $tareaIds)
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->get()
            ->groupBy('empleado_tarea_id');

        $evidencias = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_evidencias')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
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
        $administrator = $this->administrator($request);

        $employee = $this->authorizedEmployee(
            $administrator,
            (int) $id
        );

        $evidencia = DB::connection('portal_main')
            ->table(
                'comunicacion360_empleado_tarea_evidencias as e'
            )
            ->join(
                'comunicacion360_empleado_tareas as t',
                't.id',
                '=',
                'e.empleado_tarea_id'
            )
            ->where('e.id', (int) $idEvidencia)
            ->where(
                'e.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'e.empleado_tarea_id',
                (int) $idTarea
            )
            ->where(
                't.id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                't.empleado_id',
                (int) $employee->id
            )
            ->where('e.activo', 1)
            ->whereNull('e.deleted_at')
            ->whereNull('t.deleted_at')
            ->select('e.*')
            ->first();

        if (! $evidencia || empty($evidencia->ruta_archivo)) {
            return response()->json([
                'ok'      => false,
                'message' => 'Evidencia no encontrada.',
            ], 404);
        }

        $fullPath = $this->evidencePaths->resolveExisting(
            $evidencia->ruta_archivo
        );

        if ($fullPath === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Archivo no encontrado.',
            ], 404);
        }

        $size = filesize($fullPath);

        if ($size === false || $size > 20 * 1024 * 1024) {
            return response()->json([
                'ok'      => false,
                'message' =>
                'El archivo de evidencia no puede visualizarse.',
            ], 422);
        }

        $fileContent = file_get_contents($fullPath);

        if ($fileContent === false) {
            return response()->json([
                'ok'      => false,
                'message' =>
                'No fue posible leer la evidencia.',
            ], 500);
        }

        $mime = $evidencia->mime_type
            ?: mime_content_type($fullPath)
            ?: 'application/octet-stream';

        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $employee->id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'tarea_empleado_evidencia',
            'entidad_id'   => (int) $evidencia->id,
            'accion'       => 'visualizar',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'Evidencia de tarea visualizada por un administrador.',
            'metadatos'    => [
                'empleado_id'       => (int) $employee->id,
                'empleado_tarea_id' => (int) $idTarea,
                'mime'              => $mime,
                'peso_bytes'        => (int) $size,
                'almacenamiento'    => str_starts_with(
                    str_replace(
                        '\\',
                        '/',
                        $evidencia->ruta_archivo
                    ),
                    '_evidenciasTarea/portales/'
                )
                    ? 'nuevo'
                    : 'legacy',
            ],
        ], $request);

        return response()->json([
            'ok'   => true,
            'data' => [
                'id'       => (int) $evidencia->id,
                'filename' =>
                $evidencia->nombre_original,
                'mime'     => $mime,
                'base64'   =>
                'data:' . $mime . ';base64,'
                . base64_encode($fileContent),
            ],
        ]);
    }
    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
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
}
