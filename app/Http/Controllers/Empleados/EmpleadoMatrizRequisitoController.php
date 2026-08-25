<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\EmpleadoMatrizRequisito;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmpleadoMatrizRequisitoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}
    public function index(Request $request)
    {
        $administrator = $this->administrator($request);

        $filters = $request->validate([
            'tipo_destino' => [
                'nullable',
                'string',
                Rule::in([
                    'documentos',
                    'cursos',
                    'salida',
                ]),
            ],
            'activo'       => [
                'nullable',
                'boolean',
            ],
        ]);

        $query = EmpleadoMatrizRequisito::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('eliminado', 0);

        if (! empty($filters['tipo_destino'])) {
            $query->where(
                'tipo_destino',
                $filters['tipo_destino']
            );
        }

        if (array_key_exists('activo', $filters)) {
            $query->where(
                'activo',
                (int) $filters['activo']
            );
        }

        $matrices = $query
            ->orderByDesc('activo')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'matrices' => $matrices,
        ]);
    }

    public function store(Request $request)
    {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'nombre'                   => [
                'required',
                'string',
                'max:150',
            ],
            'descripcion'              => [
                'nullable',
                'string',
            ],
            'tipo_destino'             => [
                'required',
                'string',
                Rule::in([
                    'documentos',
                    'cursos',
                    'salida',
                ]),
            ],
            'requisitos'               => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'requisitos.*.nombre'      => [
                'required',
                'string',
                'max:150',
            ],
            'requisitos.*.descripcion' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $requisitos = collect($data['requisitos'])
            ->values()
            ->map(function (array $requisito, int $index) {
                return [
                    'nombre'      => trim(
                        $requisito['nombre']
                    ),
                    'descripcion' => filled(
                        $requisito['descripcion'] ?? null
                    )
                        ? trim($requisito['descripcion'])
                        : null,
                    'orden'       => $index + 1,
                ];
            })
            ->all();

        $matriz = EmpleadoMatrizRequisito::create([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_usuario'   =>
            (int) $administrator->id,
            'nombre'       => trim($data['nombre']),
            'descripcion'  => filled(
                $data['descripcion'] ?? null
            )
                ? trim($data['descripcion'])
                : null,
            'tipo_destino' => $data['tipo_destino'],
            'requisitos'   => $requisitos,
            'activo'       => 1,
            'eliminado'    => 0,
        ]);
        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => null,

            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorNombre,

            'modulo'           => 'exempleados',
            'entidad_tipo'     => 'plantilla_checklist_salida',
            'entidad_id'       => (int) $matriz->id,

            'accion'           => 'plantilla_creada',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Se creó una plantilla de checklist de salida.',

            'datos_anteriores' => null,
            'datos_nuevos'     => [
                'nombre'       => $matriz->nombre,
                'descripcion'  => $matriz->descripcion,
                'tipo_destino' => $matriz->tipo_destino,
                'requisitos'   => $matriz->requisitos,
                'activo'       => (int) $matriz->activo,
                'eliminado'    => (int) $matriz->eliminado,
            ],
            'metadatos'        => [
                'total_requisitos' => count($requisitos),
            ],
        ], $request);
        return response()->json([
            'message' => 'Plantilla creada correctamente.',
            'matriz'  => $matriz,
        ], 201);
    }
    public function update(
        Request $request,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'nombre'                   => [
                'required',
                'string',
                'max:150',
            ],
            'descripcion'              => [
                'nullable',
                'string',
            ],
            'tipo_destino'             => [
                'required',
                'string',
                Rule::in([
                    'documentos',
                    'cursos',
                    'salida',
                ]),
            ],
            'requisitos'               => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'requisitos.*.nombre'      => [
                'required',
                'string',
                'max:150',
            ],
            'requisitos.*.descripcion' => [
                'nullable',
                'string',
                'max:500',
            ],
        ]);

        $matriz = $this->findMatrix(
            $administrator,
            $id
        );
        $previousData = [
            'nombre'       => $matriz->nombre,
            'descripcion'  => $matriz->descripcion,
            'tipo_destino' => $matriz->tipo_destino,
            'requisitos'   => $matriz->requisitos,
            'activo'       => (int) $matriz->activo,
            'eliminado'    => (int) $matriz->eliminado,
        ];
        $requisitos = collect($data['requisitos'])
            ->values()
            ->map(function (
                array $requisito,
                int $index
            ) {
                return [
                    'nombre'      => trim(
                        $requisito['nombre']
                    ),
                    'descripcion' => filled(
                        $requisito['descripcion'] ?? null
                    )
                        ? trim($requisito['descripcion'])
                        : null,
                    'orden'       => $index + 1,
                ];
            })
            ->all();

        $matriz->fill([
            'id_usuario'   =>
            (int) $administrator->id,
            'nombre'       => trim($data['nombre']),
            'descripcion'  => filled(
                $data['descripcion'] ?? null
            )
                ? trim($data['descripcion'])
                : null,
            'tipo_destino' =>
            $data['tipo_destino'],
            'requisitos'   => $requisitos,
        ])->save();
        $matriz->refresh();

        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => null,

            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorNombre,

            'modulo'           => 'exempleados',
            'entidad_tipo'     => 'plantilla_checklist_salida',
            'entidad_id'       => (int) $matriz->id,

            'accion'           => 'plantilla_actualizada',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Se actualizó una plantilla de checklist de salida.',

            'datos_anteriores' => $previousData,
            'datos_nuevos'     => [
                'nombre'       => $matriz->nombre,
                'descripcion'  => $matriz->descripcion,
                'tipo_destino' => $matriz->tipo_destino,
                'requisitos'   => $matriz->requisitos,
                'activo'       => (int) $matriz->activo,
                'eliminado'    => (int) $matriz->eliminado,
            ],
            'metadatos'        => [
                'total_requisitos_anterior' =>
                count($previousData['requisitos'] ?? []),
                'total_requisitos_nuevo'    =>
                count($matriz->requisitos ?? []),
            ],
        ], $request);
        return response()->json([
            'message' =>
            'Plantilla actualizada correctamente.',
            'matriz'  => $matriz]);
    }

    public function updateStatus(
        Request $request,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'activo' => [
                'required',
                'boolean',
            ],
        ]);

        $matriz = $this->findMatrix(
            $administrator,
            $id
        );

        $matriz->fill([
            'id_usuario' =>
            (int) $administrator->id,
            'activo'     => (int) $data['activo'],
        ])->save();

        return response()->json([
            'message' =>
            'Estado de la plantilla actualizado correctamente.',
            'matriz'  => $matriz->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $matriz = $this->findMatrix(
            $administrator,
            $id
        );

        $matriz->fill([
            'id_usuario' =>
            (int) $administrator->id,
            'activo'     => 0,
            'eliminado'  => 1,
        ])->save();

        return response()->json([
            'message' =>
            'Plantilla eliminada correctamente.',
        ]);
    }
    public function updateDocumentCheck(
        Request $request,
        int $documentId
    ) {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'completed' => [
                'required',
                'boolean',
            ],
        ]);

        $document = DocumentEmpleado::query()
            ->where('id', $documentId)
            ->where('document_context', 'salida')
            ->where('status', '!=', 999)
            ->whereNotNull('status_check')
            ->firstOrFail();

        $employee = Empleado::query()
            ->where(
                'id',
                (int) $document->employee_id
            )
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('status', 2)
            ->where('eliminado', 0)
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );
        $previousData = [
            'status'       => (int) $document->status,
            'status_check' => (int) $document->status_check,
        ];
        $completed = (bool) $data['completed'];

        $document->fill([
            'id_usuario'   =>
            (int) $administrator->id,
            'status'       =>
            $completed ? 1 : 3,
            'status_check' =>
            $completed ? 1 : 0,
        ])->save();
        $document->refresh();

        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $employeeName = trim(implode(' ', array_filter([
            $employee->nombre ?? null,
            $employee->paterno ?? null,
            $employee->materno ?? null,
        ])));

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,

            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorNombre,

            'modulo'           => 'exempleados',
            'entidad_tipo'     => 'documento_checklist_salida',
            'entidad_id'       => (int) $document->id,

            'accion'           => $completed
                ? 'requisito_checklist_completado'
                : 'requisito_checklist_reabierto',

            'resultado'        => 'exitoso',
            'descripcion'      => $completed
                ? 'Se marcó como cumplido un requisito del checklist de salida.'
                : 'Se marcó nuevamente como pendiente un requisito del checklist de salida.',

            'datos_anteriores' => $previousData,
            'datos_nuevos'     => [
                'status'       => (int) $document->status,
                'status_check' => (int) $document->status_check,
            ],
            'metadatos'        => [
                'empleado_id'     => (int) $employee->id,
                'empleado_nombre' => $employeeName,
                'documento'       => $document->nameDocument,
            ],
        ], $request);
        return response()->json([
            'message'  =>
            'Checklist actualizado correctamente.',
            'document' => $document,
        ]);
    }
    public function assign(
        Request $request,
        int $employeeId
    ) {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'matriz_id' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $matriz = EmpleadoMatrizRequisito::query()
            ->where('id', (int) $data['matriz_id'])
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('tipo_destino', 'salida')
            ->where('activo', 1)
            ->where('eliminado', 0)
            ->firstOrFail();

        $employee = Empleado::query()
            ->where('id', $employeeId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('status', 2)
            ->where('eliminado', 0)
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        $requirements = is_array($matriz->requisitos)
            ? $matriz->requisitos
            : [];

        if (count($requirements) === 0) {
            return response()->json([
                'message' =>
                'La plantilla no contiene requisitos.',
            ], 422);
        }

        $documents = DB::connection('portal_main')
            ->transaction(function () use (
                $requirements,
                $employee,
                $administrator
            ) {
                return collect($requirements)
                    ->map(function (array $requirement) use (
                        $employee,
                        $administrator
                    ) {
                        return DocumentEmpleado::create([
                            'employee_id'              =>
                            (int) $employee->id,
                            'id_usuario'               =>
                            (int) $administrator->id,
                            'name'                     => null,
                            'nameDocument'             =>
                            trim($requirement['nombre']),
                            'description'              =>
                            filled(
                                $requirement['descripcion'] ?? null
                            )
                                ? trim(
                                $requirement['descripcion']
                            )
                                : null,
                            'expiry_date'              => null,
                            'expiry_reminder'          => null,
                            'status'                   => 3,
                            'document_context'         => 'salida',
                            'status_check'             => 0,
                            'share_scope'              => 0,
                            'collaborator_can_replace' => 0,
                        ]);
                    })
                    ->values();
            });
        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $employeeName = trim(implode(' ', array_filter([
            $employee->nombre ?? null,
            $employee->paterno ?? null,
            $employee->materno ?? null,
        ])));

        $assignedDocuments = $documents
            ->map(function (DocumentEmpleado $document) {
                return [
                    'document_id'  => (int) $document->id,
                    'nombre'       => $document->nameDocument,
                    'descripcion'  => $document->description,
                    'status'       => (int) $document->status,
                    'status_check' => (int) $document->status_check,
                ];
            })
            ->values()
            ->all();

        $this->auditoria->registrar([
            'id_portal'    => (int) $administrator->id_portal,
            'id_cliente'   => (int) $employee->id_cliente,

            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' => $actorNombre,

            'modulo'       => 'exempleados',
            'entidad_tipo' => 'empleado_checklist_salida',
            'entidad_id'   => (int) $employee->id,

            'accion'       => 'checklist_asignado',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            "Se asignó el checklist de salida al exempleado {$employeeName}.",

            'datos_anteriores' => null,
            'datos_nuevos'     => [
                'matriz_id'       => (int) $matriz->id,
                'plantilla'       => $matriz->nombre,
                'empleado_id'     => (int) $employee->id,
                'empleado_nombre' => $employeeName,
                'documentos'      => $assignedDocuments,
            ],
            'metadatos'        => [
                'total_requisitos' => count($assignedDocuments),
                'document_ids'     => array_column(
                    $assignedDocuments,
                    'document_id'
                ),
            ],
        ], $request);
        return response()->json([
            'message'   =>
            'Checklist asignado correctamente.',
            'documents' => $documents,
        ], 201);
    }
    private function findMatrix(
        AdministradorAuth $administrator,
        int $id
    ): EmpleadoMatrizRequisito {
        return EmpleadoMatrizRequisito::query()
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('eliminado', 0)
            ->firstOrFail();
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
}
