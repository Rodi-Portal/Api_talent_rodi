<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Comunicacion360\Tareas;
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

        $task =Tareas::query()
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

    $task = Tareas ::query()
        ->where('id', $id)
        ->where('id_portal', $validated['id_portal'])
        ->firstOrFail();

    $task->delete();

    return response()->json([
        'ok' => true,
        'code' => 'TASK_DELETED',
        'data' => [
            'id' => $id,
        ],
    ]);
}
}
