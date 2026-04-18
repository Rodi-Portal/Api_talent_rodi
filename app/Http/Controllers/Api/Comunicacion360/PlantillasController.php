<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Plantilla;
use App\Models\Comunicacion360\PlantillaTarea;
use App\Models\Comunicacion360\Tareas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlantillasController extends Controller
{
    /**
     * GET /api/comunicacion360/plantillas
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $plantillas = Plantilla::query()
            ->with(['tareas'])
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'id_portal',
                'clave',
                'nombre',
                'descripcion',
                'vigencia_inicio',
                'vigencia_fin',
                'prioridad',
                'modo_superposicion',
                'activa',
                'vigencia_dias',
                'created_at',
                'updated_at',
            ]);

        $data = $plantillas->map(function ($plantilla) {
            return [
                'id'                 => $plantilla->id,
                'id_portal'          => $plantilla->id_portal,
                'clave'              => $plantilla->clave,
                'nombre'             => $plantilla->nombre,
                'descripcion'        => $plantilla->descripcion,
                'vigencia_inicio'    => optional($plantilla->vigencia_inicio)->format('Y-m-d'),
                'vigencia_fin'       => optional($plantilla->vigencia_fin)->format('Y-m-d'),
                'prioridad'          => $plantilla->prioridad,
                'modo_superposicion' => $plantilla->modo_superposicion,
                'activa'             => (bool) $plantilla->activa,
                'vigencia_dias'      => $plantilla->vigencia_dias,
                'created_at'         => $plantilla->created_at,
                'updated_at'         => $plantilla->updated_at,
                'tareas'             => $plantilla->tareas->map(function ($tarea) {
                    return [
                        'id'                   => $tarea->id,
                        'id_portal'            => $tarea->id_portal,
                        'plantilla_id'         => $tarea->plantilla_id,
                        'tarea_catalogo_id'    => $tarea->tarea_catalogo_id,
                        'task_id'              => $tarea->tarea_catalogo_id,
                        'orden'                => $tarea->orden,
                        'clave_snapshot'       => $tarea->clave_snapshot,
                        'nombre_snapshot'      => $tarea->nombre_snapshot,
                        'descripcion_snapshot' => $tarea->descripcion_snapshot,
                        'requiere_evidencia'   => (bool) $tarea->requiere_evidencia,
                        'permite_comentarios'  => (bool) $tarea->permite_comentarios,
                        'tiempo_estimado_min'  => $tarea->tiempo_estimado_min,
                        'activa'               => (bool) $tarea->activa,
                        'created_at'           => $tarea->created_at,
                        'updated_at'           => $tarea->updated_at,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'ok'   => true,
            'code' => 'PLANTILLAS_LIST',
            'data' => $data,
        ]);
    }

    /**
     * POST /api/comunicacion360/plantillas
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_portal'          => ['required', 'integer'],
            'nombre'             => ['required', 'string', 'max:150'],
            'descripcion'        => ['nullable', 'string'],
            'vigencia_inicio'    => ['required', 'date'],
            'vigencia_fin'       => ['required', 'date', 'after_or_equal:vigencia_inicio'],
            'prioridad'          => ['required', 'integer', 'min:1'],
            'modo_superposicion' => ['required', 'string', 'in:merge,override'],
            'activa'             => ['required', 'boolean'],
            'tareas'             => ['required', 'array', 'min:1'],
            'tareas.*.task_id'   => ['required', 'integer'],
            'tareas.*.orden'     => ['required', 'integer', 'min:1'],
        ]);

        $result = DB::connection('portal_main')->transaction(function () use ($validated) {
            $clave = 'TPL-' . time();

            $plantilla = Plantilla::create([
                'id_portal'          => $validated['id_portal'],
                'clave'              => $clave,
                'nombre'             => $validated['nombre'],
                'descripcion'        => $validated['descripcion'] ?? null,
                'vigencia_inicio'    => $validated['vigencia_inicio'],
                'vigencia_fin'       => $validated['vigencia_fin'],
                'prioridad'          => $validated['prioridad'],
                'modo_superposicion' => $validated['modo_superposicion'],
                'activa'             => $validated['activa'],
                'vigencia_dias'      => null,
            ]);

            $taskIds = collect($validated['tareas'])
                ->pluck('task_id')
                ->unique()
                ->values();

            $catalogo = Tareas::query()
                ->where('id_portal', $validated['id_portal'])
                ->whereNull('deleted_at')
                ->whereIn('id', $taskIds)
                ->get()
                ->keyBy('id');

            if ($catalogo->count() !== $taskIds->count()) {
                abort(response()->json([
                    'ok'   => false,
                    'code' => 'INVALID_TASKS',
                    'data' => null,
                ], 422));
            }

            foreach ($validated['tareas'] as $item) {
                $task = $catalogo->get($item['task_id']);

                PlantillaTarea::create([
                    'id_portal'            => $validated['id_portal'],
                    'plantilla_id'         => $plantilla->id,
                    'tarea_catalogo_id'    => $task->id,
                    'orden'                => $item['orden'],
                    'clave_snapshot'       => $task->clave,
                    'nombre_snapshot'      => $task->nombre,
                    'descripcion_snapshot' => $task->descripcion,
                    'requiere_evidencia'   => (bool) $task->requiere_evidencia,
                    'permite_comentarios'  => (bool) $task->permite_comentarios,
                    'tiempo_estimado_min'  => $task->tiempo_estimado_min,
                    'activa'               => (bool) $task->activa,
                ]);
            }

            $plantilla->load(['tareas']);

            return $plantilla;
        });

        return response()->json([
            'ok'   => true,
            'code' => 'PLANTILLA_CREATED',
            'data' => [
                'id'                 => $result->id,
                'id_portal'          => $result->id_portal,
                'clave'              => $result->clave,
                'nombre'             => $result->nombre,
                'descripcion'        => $result->descripcion,
                'vigencia_inicio'    => optional($result->vigencia_inicio)->format('Y-m-d'),
                'vigencia_fin'       => optional($result->vigencia_fin)->format('Y-m-d'),
                'prioridad'          => $result->prioridad,
                'modo_superposicion' => $result->modo_superposicion,
                'activa'             => (bool) $result->activa,
                'vigencia_dias'      => $result->vigencia_dias,
                'tareas'             => $result->tareas->map(function ($tarea) {
                    return [
                        'id'                => $tarea->id,
                        'task_id'           => $tarea->tarea_catalogo_id,
                        'tarea_catalogo_id' => $tarea->tarea_catalogo_id,
                        'orden'             => $tarea->orden,
                        'nombre'            => $tarea->nombre_snapshot,
                        'descripcion'       => $tarea->descripcion_snapshot,
                    ];
                })->values(),
            ],
        ], 201);
    }

    /**
     * PUT /api/comunicacion360/plantillas/{id}
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal'          => ['required', 'integer'],
            'nombre'             => ['required', 'string', 'max:150'],
            'descripcion'        => ['nullable', 'string'],
            'vigencia_inicio'    => ['required', 'date'],
            'vigencia_fin'       => ['required', 'date', 'after_or_equal:vigencia_inicio'],
            'prioridad'          => ['required', 'integer', 'min:1'],
            'modo_superposicion' => ['required', 'string', 'in:merge,override'],
            'activa'             => ['required', 'boolean'],
            'tareas'             => ['required', 'array', 'min:1'],
            'tareas.*.task_id'   => ['required', 'integer'],
            'tareas.*.orden'     => ['required', 'integer', 'min:1'],
        ]);

        $result = DB::connection('portal_main')->transaction(function () use ($validated, $id) {
            $plantilla = Plantilla::query()
                ->where('id', $id)
                ->where('id_portal', $validated['id_portal'])
                ->whereNull('deleted_at')
                ->firstOrFail();

            $plantilla->update([
                'nombre'             => $validated['nombre'],
                'descripcion'        => $validated['descripcion'] ?? null,
                'vigencia_inicio'    => $validated['vigencia_inicio'],
                'vigencia_fin'       => $validated['vigencia_fin'],
                'prioridad'          => $validated['prioridad'],
                'modo_superposicion' => $validated['modo_superposicion'],
                'activa'             => $validated['activa'],
            ]);

            $taskIds = collect($validated['tareas'])
                ->pluck('task_id')
                ->unique()
                ->values();

            $catalogo = Tareas::query()
                ->where('id_portal', $validated['id_portal'])
                ->whereNull('deleted_at')
                ->whereIn('id', $taskIds)
                ->get()
                ->keyBy('id');

            if ($catalogo->count() !== $taskIds->count()) {
                abort(response()->json([
                    'ok'   => false,
                    'code' => 'INVALID_TASKS',
                    'data' => null,
                ], 422));
            }

            PlantillaTarea::query()
                ->where('plantilla_id', $plantilla->id)
                ->where('id_portal', $validated['id_portal'])
                ->forceDelete();

            foreach ($validated['tareas'] as $item) {
                $task = $catalogo->get($item['task_id']);

                PlantillaTarea::create([
                    'id_portal'            => $validated['id_portal'],
                    'plantilla_id'         => $plantilla->id,
                    'tarea_catalogo_id'    => $task->id,
                    'orden'                => $item['orden'],
                    'clave_snapshot'       => $task->clave,
                    'nombre_snapshot'      => $task->nombre,
                    'descripcion_snapshot' => $task->descripcion,
                    'requiere_evidencia'   => (bool) $task->requiere_evidencia,
                    'permite_comentarios'  => (bool) $task->permite_comentarios,
                    'tiempo_estimado_min'  => $task->tiempo_estimado_min,
                    'activa'               => (bool) $task->activa,
                ]);
            }

            $plantilla->load(['tareas']);

            return $plantilla;
        });

        return response()->json([
            'ok'   => true,
            'code' => 'PLANTILLA_UPDATED',
            'data' => [
                'id'                 => $result->id,
                'id_portal'          => $result->id_portal,
                'clave'              => $result->clave,
                'nombre'             => $result->nombre,
                'descripcion'        => $result->descripcion,
                'vigencia_inicio'    => optional($result->vigencia_inicio)->format('Y-m-d'),
                'vigencia_fin'       => optional($result->vigencia_fin)->format('Y-m-d'),
                'prioridad'          => $result->prioridad,
                'modo_superposicion' => $result->modo_superposicion,
                'activa'             => (bool) $result->activa,
                'vigencia_dias'      => $result->vigencia_dias,
                'tareas'             => $result->tareas->map(function ($tarea) {
                    return [
                        'id'                => $tarea->id,
                        'task_id'           => $tarea->tarea_catalogo_id,
                        'tarea_catalogo_id' => $tarea->tarea_catalogo_id,
                        'orden'             => $tarea->orden,
                        'nombre'            => $tarea->nombre_snapshot,
                        'descripcion'       => $tarea->descripcion_snapshot,
                    ];
                })->values(),
            ],
        ]);
    }

    /**
     * DELETE /api/comunicacion360/plantillas/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $plantilla = Plantilla::query()
            ->where('id', $id)
            ->where('id_portal', $validated['id_portal'])
            ->whereNull('deleted_at')
            ->firstOrFail();

        // Soft delete de la plantilla
        $plantilla->delete();

        // Soft delete de sus tareas asociadas
        PlantillaTarea::query()
            ->where('plantilla_id', $plantilla->id)
            ->where('id_portal', $validated['id_portal'])
            ->delete();

        return response()->json([
            'ok'   => true,
            'code' => 'PLANTILLA_DELETED',
            'data' => [
                'id' => $id,
            ],
        ]);
    }

    /**
     * POST /api/comunicacion360/plantillas/{id}/asignar
     */
    public function asignar(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal'   => ['required', 'integer', 'min:1'],
            'empleados'   => ['required', 'array', 'min:1'],
            'empleados.*' => ['required', 'integer', 'min:1'],
        ]);

        $idPortal = (int) $validated['id_portal'];

        $empleadosIds = collect($validated['empleados'])
            ->map(fn($item) => (int) $item)
            ->filter(fn($item) => $item > 0)
            ->unique()
            ->values();

        $plantilla = Plantilla::query()
            ->with(['tareas'])
            ->where('id', $id)
            ->where('id_portal', $idPortal)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $empleados = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.eliminado',
                'e.fecha_salida',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id', $empleadosIds->all())
            ->where('e.status', 1)
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->get()
            ->keyBy('id');

        $detalle      = [];
        $procesados   = 0;
        $asignados    = 0;
        $actualizados = 0;
        $fallidos     = 0;

        foreach ($empleadosIds as $empleadoId) {
            $procesados++;

            $empleado = $empleados->get($empleadoId);

            if (! $empleado) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleadoId,
                    'ok'   => false,
                    'code' => 'EMPLOYEE_NOT_FOUND',
                ];
                continue;
            }

            $tieneAcceso = ! empty($empleado->password) && (int) $empleado->status === 1;

            if (! $tieneAcceso) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EMPLOYEE_WITHOUT_ACCESS',
                ];
                continue;
            }

            if (! empty($empleado->fecha_salida)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EMPLOYEE_EXIT_DATE',
                ];
                continue;
            }

            DB::connection('portal_main')->beginTransaction();

            try {
                $asignacion = DB::connection('portal_main')
                    ->table('comunicacion360_plantilla_asignaciones')
                    ->where('id_portal', $idPortal)
                    ->where('plantilla_id', $plantilla->id)
                    ->where('empleado_id', $empleado->id)
                    ->whereNull('deleted_at')
                    ->first();

                $fechaAsignacion = now();

                if ($asignacion) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_plantilla_asignaciones')
                        ->where('id', $asignacion->id)
                        ->update([
                            'estatus'            => 'asignada',
                            'progreso'           => 0,
                            'fecha_asignacion'   => $fechaAsignacion,
                            'fecha_inicio'       => null,
                            'fecha_limite'       => null,
                            'fecha_finalizacion' => null,
                            'updated_at'         => now(),
                        ]);

                    $plantillaAsignacionId = (int) $asignacion->id;

                    DB::connection('portal_main')
                        ->table('comunicacion360_empleado_tareas')
                        ->where('id_portal', $idPortal)
                        ->where('plantilla_asignacion_id', $plantillaAsignacionId)
                        ->delete();

                    $actualizados++;
                    $resultadoCode = 'ASSIGNMENT_UPDATED';
                } else {
                    $plantillaAsignacionId = DB::connection('portal_main')
                        ->table('comunicacion360_plantilla_asignaciones')
                        ->insertGetId([
                            'id_portal'          => $idPortal,
                            'plantilla_id'       => $plantilla->id,
                            'empleado_id'        => $empleado->id,
                            'estatus'            => 'asignada',
                            'progreso'           => 0,
                            'fecha_asignacion'   => $fechaAsignacion,
                            'fecha_inicio'       => null,
                            'fecha_limite'       => null,
                            'fecha_finalizacion' => null,
                            'created_at'         => now(),
                            'updated_at'         => now(),
                        ]);

                    $asignados++;
                    $resultadoCode = 'ASSIGNED';
                }

                foreach ($plantilla->tareas as $tarea) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_empleado_tareas')
                        ->insert([
                            'id_portal'               => $idPortal,
                            'empleado_id'             => $empleado->id,
                            'plantilla_id'            => $plantilla->id,
                            'plantilla_asignacion_id' => $plantillaAsignacionId,
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
                            'fecha_asignacion'        => $fechaAsignacion,
                            'fecha_inicio'            => null,
                            'fecha_fin'               => null,
                            'created_at'              => now(),
                            'updated_at'              => now(),
                        ]);
                }

                DB::connection('portal_main')->commit();

                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => true,
                    'code' => $resultadoCode,
                ];
            } catch (\Throwable $e) {
                DB::connection('portal_main')->rollBack();

                \Log::error('Error al asignar plantilla Comunicación 360', [
                    'plantilla_id' => $plantilla->id,
                    'empleado_id'  => $empleado->id ?? $empleadoId,
                    'error'        => $e->getMessage(),
                ]);

                $fallidos++;
                $detalle[] = [
                    'id'   => (int) ($empleado->id ?? $empleadoId),
                    'ok'   => false,
                    'code' => 'PROCESS_FAILED',
                ];
            }
        }

        return response()->json([
            'ok'   => true,
            'code' => ($fallidos > 0) ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED_SUCCESS',
            'data' => [
                'procesados'   => $procesados,
                'asignados'    => $asignados,
                'actualizados' => $actualizados,
                'fallidos'     => $fallidos,
                'detalle'      => $detalle,
            ],
        ]);
    }

    /**
     * GET /api/comunicacion360/empleados/{id}/plantillas?id_portal=1
     */
    public function empleadoPlantillas(Request $request, int $id)
    {
        $validated = $request->validate([
            'id_portal' => ['required', 'integer', 'min:1'],
        ]);

        $idPortal   = (int) $validated['id_portal'];
        $empleadoId = (int) $id;

        $empleado = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.eliminado',
                'e.fecha_salida',
            ])
            ->where('e.id_portal', $idPortal)
            ->where('e.id', $empleadoId)
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->first();

        if (! $empleado) {
            return response()->json([
                'ok'   => false,
                'code' => 'EMPLOYEE_NOT_FOUND',
                'data' => null,
            ], 404);
        }

        $tieneAcceso = ! empty($empleado->password) && (int) $empleado->status === 1;

        $plantillas = DB::connection('portal_main')
            ->table('comunicacion360_plantilla_asignaciones as a')
            ->join('comunicacion360_plantillas as p', function ($join) {
                $join->on('p.id', '=', 'a.plantilla_id')
                    ->on('p.id_portal', '=', 'a.id_portal');
            })
            ->select([
                'a.id',
                'a.id_portal',
                'a.plantilla_id',
                'a.empleado_id',
                'a.estatus',
                'a.progreso',
                'a.fecha_asignacion',
                'a.fecha_inicio',
                'a.fecha_limite',
                'a.fecha_finalizacion',
                'a.created_at',
                'a.updated_at',

                'p.clave',
                'p.nombre',
                'p.descripcion',
                'p.vigencia_inicio',
                'p.vigencia_fin',
                'p.prioridad',
                'p.modo_superposicion',
                'p.activa',
                'p.vigencia_dias',
            ])
            ->where('a.id_portal', $idPortal)
            ->where('a.empleado_id', $empleadoId)
            ->whereNull('a.deleted_at')
            ->whereNull('p.deleted_at')
            ->orderByDesc('a.fecha_asignacion')
            ->get();

        return response()->json([
            'ok'   => true,
            'code' => 'EMPLOYEE_TEMPLATES_LIST',
            'data' => [
                'empleado'   => [
                    'id'              => (int) $empleado->id,
                    'id_portal'       => (int) $empleado->id_portal,
                    'id_cliente'      => $empleado->id_cliente !== null ? (int) $empleado->id_cliente : null,
                    'id_empleado'     => $empleado->id_empleado,
                    'nombre'          => $empleado->nombre,
                    'paterno'         => $empleado->paterno,
                    'materno'         => $empleado->materno,
                    'nombre_completo' => trim(collect([
                        $empleado->nombre,
                        $empleado->paterno,
                        $empleado->materno,
                    ])->filter()->implode(' ')),
                    'correo'          => $empleado->correo,
                    'status'          => (int) $empleado->status,
                    'tiene_acceso'    => $tieneAcceso,
                    'fecha_salida'    => $empleado->fecha_salida,
                ],
                'plantillas' => $plantillas->map(function ($item) {
                    return [
                        'asignacion_id'      => (int) $item->id,
                        'id_portal'          => (int) $item->id_portal,
                        'plantilla_id'       => (int) $item->plantilla_id,
                        'empleado_id'        => (int) $item->empleado_id,
                        'clave'              => $item->clave,
                        'nombre'             => $item->nombre,
                        'descripcion'        => $item->descripcion,
                        'estatus'            => $item->estatus,
                        'progreso'           => (int) $item->progreso,
                        'fecha_asignacion'   => $item->fecha_asignacion,
                        'fecha_inicio'       => $item->fecha_inicio,
                        'fecha_limite'       => $item->fecha_limite,
                        'fecha_finalizacion' => $item->fecha_finalizacion,
                        'vigencia_inicio'    => $item->vigencia_inicio,
                        'vigencia_fin'       => $item->vigencia_fin,
                        'prioridad'          => $item->prioridad !== null ? (int) $item->prioridad : null,
                        'modo_superposicion' => $item->modo_superposicion,
                        'activa'             => (bool) $item->activa,
                        'vigencia_dias'      => $item->vigencia_dias !== null ? (int) $item->vigencia_dias : null,
                        'created_at'         => $item->created_at,
                        'updated_at'         => $item->updated_at,
                    ];
                })->values(),
            ],
        ]);
    }

    public function desasignarEmpleado(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal'   => ['required', 'integer'],
            'empleado_id' => ['required', 'integer'],
        ]);

        $idPortal    = (int) $data['id_portal'];
        $empleadoId  = (int) $data['empleado_id'];
        $plantillaId = (int) $id;

        try {
            $resultado = \DB::connection('portal_main')->transaction(function () use (
                $idPortal,
                $empleadoId,
                $plantillaId
            ) {
                $asignacion = \DB::connection('portal_main')
                    ->table('comunicacion360_plantilla_asignaciones')
                    ->where('id_portal', $idPortal)
                    ->where('plantilla_id', $plantillaId)
                    ->where('empleado_id', $empleadoId)
                    ->first();

                if (! $asignacion) {
                    return [
                        'ok'      => false,
                        'code'    => 'ASSIGNMENT_NOT_FOUND',
                        'message' => 'No se encontró una asignación activa para esta plantilla y empleado.',
                        'http'    => 404,
                    ];
                }

                $tareasConAvance = \DB::connection('portal_main')
                    ->table('comunicacion360_empleado_tareas')
                    ->where('id_portal', $idPortal)
                    ->where('empleado_id', $empleadoId)
                    ->where('plantilla_asignacion_id', $asignacion->id)
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->where('estatus', '<>', 'pendiente')
                            ->orWhere('porcentaje_avance', '>', 0)
                            ->orWhere('tiene_evidencia', 1)
                            ->orWhere('total_comentarios', '>', 0)
                            ->orWhereNotNull('fecha_inicio')
                            ->orWhereNotNull('fecha_fin');
                    })
                    ->count();

                if ($tareasConAvance > 0) {
                    return [
                        'ok'      => false,
                        'code'    => 'TEMPLATE_HAS_PROGRESS',
                        'message' => 'La plantilla no puede quitarse porque ya tiene tareas con avance.',
                        'http'    => 422,
                    ];
                }

                $tareasEliminadas = \DB::connection('portal_main')
                    ->table('comunicacion360_empleado_tareas')
                    ->where('id_portal', $idPortal)
                    ->where('empleado_id', $empleadoId)
                    ->where('plantilla_asignacion_id', $asignacion->id)
                    ->delete();

                $asignacionEliminada = \DB::connection('portal_main')
                    ->table('comunicacion360_plantilla_asignaciones')
                    ->where('id', $asignacion->id)
                    ->delete();

                return [
                    'ok'      => true,
                    'code'    => 'TEMPLATE_UNASSIGNED',
                    'message' => 'La plantilla fue desasignada correctamente.',
                    'http'    => 200,
                    'data'    => [
                        'plantilla_id'            => $plantillaId,
                        'empleado_id'             => $empleadoId,
                        'plantilla_asignacion_id' => $asignacion->id,
                        'tareas_eliminadas'       => $tareasEliminadas,
                        'asignacion_eliminada'    => (bool) $asignacionEliminada,
                    ],
                ];
            });

            return response()->json(
                [
                    'ok'      => $resultado['ok'],
                    'code'    => $resultado['code'],
                    'message' => $resultado['message'],
                    'data'    => $resultado['data'] ?? null,
                ],
                $resultado['http']
            );
        } catch (\Throwable $e) {
            \Log::error('Error al desasignar plantilla de empleado', [
                'plantilla_id' => $plantillaId,
                'empleado_id'  => $empleadoId,
                'id_portal'    => $idPortal,
                'error'        => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'code'    => 'PROCESS_FAILED',
                'message' => 'Ocurrió un error al desasignar la plantilla.',
            ], 500);
        }
    }
}
