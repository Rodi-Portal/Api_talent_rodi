<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Models\SolicitudRenovacionArchivo;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\EmployeeDocumentPathService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SolicitudRenovacionArchivoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private EmployeeDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'id_cliente'  => ['required', 'integer', 'min:1'],
            'id_empleado' => ['nullable', 'integer', 'min:1'],
            'estado'      => [
                'nullable',
                'string',
                'in:pendiente,aprobada,rechazada,cancelada',
            ],
            'per_page'    => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $clientId = (int) $clientIds[0];

        if (! empty($data['id_empleado'])) {
            Empleado::query()
                ->where('id', (int) $data['id_empleado'])
                ->where(
                    'id_portal',
                    (int) $administrator->id_portal
                )
                ->where('id_cliente', $clientId)
                ->firstOrFail();
        }

        $query = SolicitudRenovacionArchivo::query()
            ->select([
                'id',
                'id_portal',
                'id_cliente',
                'id_empleado',
                'tipo',
                'id_origen',
                'archivo_actual',
                'edicion_origen',
                'nombre_original',
                'mime_type',
                'size_bytes',
                'estado',
                'id_usuario_resolvio',
                'comentario_colaborador',
                'comentario_resolucion',
                'creacion',
                'edicion',
                'resolucion',
            ])
            ->with([
                'empleado' => function ($employeeQuery) {
                    $employeeQuery->select([
                        'id',
                        'id_portal',
                        'id_cliente',
                        'nombre',
                        'paterno',
                        'materno',
                    ]);
                },
            ])
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('id_cliente', $clientId);

        if (! empty($data['id_empleado'])) {
            $query->where(
                'id_empleado',
                (int) $data['id_empleado']
            );
        }

        $query->where(
            'estado',
            $data['estado'] ?? SolicitudRenovacionArchivo::ESTADO_PENDIENTE
        );

        $paginator = $query
            ->orderByDesc('creacion')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        $items = collect($paginator->items())
            ->map(function (
                SolicitudRenovacionArchivo $request
            ) {
                $employee = $request->empleado;

                return [
                    'id'                     => (int) $request->id,
                    'id_empleado'            => (int) $request->id_empleado,
                    'empleado'               => $employee
                        ? trim(implode(' ', array_filter([
                        $employee->nombre,
                        $employee->paterno,
                        $employee->materno,
                    ])))
                        : null,
                    'tipo'                   => $request->tipo,
                    'id_origen'              => (int) $request->id_origen,
                    'archivo_actual'         => $request->archivo_actual,
                    'nombre_original'        => $request->nombre_original,
                    'mime_type'              => $request->mime_type,
                    'size_bytes'             => (int) $request->size_bytes,
                    'estado'                 => $request->estado,
                    'comentario_colaborador' =>
                    $request->comentario_colaborador,
                    'comentario_resolucion'  =>
                    $request->comentario_resolucion,
                    'creacion'               => $request->creacion,
                    'resolucion'             => $request->resolucion,
                ];
            })
            ->values();

        return response()->json([
            'solicitudes' => $items,
            'paginacion'  => [
                'pagina_actual' => $paginator->currentPage(),
                'ultima_pagina' => $paginator->lastPage(),
                'por_pagina'    => $paginator->perPage(),
                'total'         => $paginator->total(),
            ],
        ]);
    }
    public function proposedFile(
        Request $request,
        int $solicitud
    ) {
        $administrator = $this->administrator($request);

        $renewal = SolicitudRenovacionArchivo::query()
            ->where('id', $solicitud)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $renewal->id_cliente]
        );

        /*
     * Resuelve propuestas antiguas desde images_path
     * y propuestas nuevas desde documents_path.
     */
        try {
            $filePath = realpath(
                $this->documentPaths->renewalAbsolutePath(
                    (string) $renewal->storage_path
                )
            );
        } catch (
            \InvalidArgumentException  | \RuntimeException $e
        ) {
            abort(404, $e->getMessage());
        }

        if (
            $filePath === false
            || ! is_file($filePath)
        ) {
            abort(
                404,
                'Archivo propuesto no encontrado.'
            );
        }

        $mimeType = mime_content_type($filePath)
            ?: 'application/octet-stream';

        $displayName = basename(
            (string) $renewal->nombre_original
        );

        if ($displayName === '') {
            $displayName = basename($filePath);
        }

        return response()->file($filePath, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' =>
            'inline; filename="' . $displayName . '"',
        ]);
    }
    public function reject(
        Request $request,
        int $solicitud
    ) {
        $data = $request->validate([
            'comentario_resolucion' => [
                'required',
                'string',
                'max:2000',
            ],
        ]);

        $administrator = $this->administrator($request);

        $renewal = SolicitudRenovacionArchivo::query()
            ->where('id', $solicitud)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $renewal->id_cliente]
        );
        $employee = Empleado::query()
            ->where('id', (int) $renewal->id_empleado)
            ->where('id_portal', (int) $renewal->id_portal)
            ->where('id_cliente', (int) $renewal->id_cliente)
            ->firstOrFail();
        $renewalBefore = $renewal->toArray();
        $renewalAfter  = null;
        DB::connection('portal_main')->transaction(
            function () use (
                $renewal,
                $administrator,
                $data,
                &$renewalAfter
            ): void {
                $lockedRenewal = SolicitudRenovacionArchivo::query()
                    ->where('id', $renewal->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $lockedRenewal->estado !==
                    SolicitudRenovacionArchivo::ESTADO_PENDIENTE
                ) {
                    abort(
                        409,
                        'La solicitud ya fue resuelta.'
                    );
                }

                $lockedRenewal->update([
                    'estado'                =>
                    SolicitudRenovacionArchivo::ESTADO_RECHAZADA,
                    'id_usuario_resolvio'   =>
                    (int) $administrator->id,
                    'comentario_resolucion' =>
                    trim($data['comentario_resolucion']),
                    'resolucion'            => now(),
                ]);
                $renewalAfter = $lockedRenewal->toArray();
            }
        );
        $trashPath         = null;
        $trashErrorMessage = null;

        try {
            $trashPath = $this->documentPaths->moveRenewalToTrash(
                $employee,
                (int) $renewal->id,
                (string) $renewal->storage_path,
                'rechazados'
            );

            Log::info('🗑️ Propuesta rechazada movida a borrados', [
                'solicitud_id' => (int) $renewal->id,
                'trash_path'   => $trashPath,
            ]);
        } catch (\Throwable $trashError) {
            /*
            * La solicitud ya quedó rechazada.
            * Si falla el traslado, la propuesta permanece en origen.
            */
            $trashErrorMessage = $trashError->getMessage();
            Log::error('⚠️ No se pudo mover la propuesta rechazada', [
                'solicitud_id' => (int) $renewal->id,
                'message'      => $trashError->getMessage(),
            ]);
        }
        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        if ($actorNombre === '') {
            $actorNombre = $administrator->email ?? $administrator->correo ?? null;
        }

        $trasladoCompletado = $trashPath !== null;

        $this->auditoria->registrar([
            'id_portal'    => (int) $renewal->id_portal,
            'id_cliente'   => (int) $renewal->id_cliente,

            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' => $actorNombre,

            'modulo'       => 'empleados',
            'entidad_tipo' => (string) $renewal->tipo,
            'entidad_id'   => (int) $renewal->id_origen,

            'accion'       => 'rechazar_renovacion',

            'resultado'    => $trasladoCompletado
                ? 'exitoso'
                : 'exitoso_con_advertencia',

            'descripcion'  => $trasladoCompletado
                ? "Se rechazó la renovación del {$renewal->tipo}."
                : "Se rechazó la renovación del {$renewal->tipo}, pero la propuesta no pudo trasladarse.",

            'datos_anteriores' => $renewalBefore,
            'datos_nuevos'     => $renewalAfter,

            'metadatos'        => [
                'solicitud_id'          => (int) $renewal->id,
                'employee_id'           => (int) $renewal->id_empleado,
                'archivo_actual'        =>
                (string) $renewal->archivo_actual,
                'archivo_propuesto'     =>
                (string) $renewal->archivo_propuesto,
                'propuesta_respaldo'    => $trashPath,
                'traslado_completado'   => $trasladoCompletado,
                'error_traslado'        => $trashErrorMessage,
                'comentario_resolucion' =>
                trim($data['comentario_resolucion']),
            ],
        ], $request);
        return response()->json([
            'message'      => 'Solicitud rechazada correctamente.',
            'trash_path'   => $trashPath,
            'solicitud_id' => (int) $renewal->id,
            'estado'       =>
            SolicitudRenovacionArchivo::ESTADO_RECHAZADA,
        ]);
    }
    public function approve(
        Request $request,
        int $solicitud
    ) {
        $data = $request->validate([
            'fecha_vencimiento'     => [
                'required',
                'date',
                'after:today',
            ],
            'comentario_resolucion' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $administrator = $this->administrator($request);

        $renewal = SolicitudRenovacionArchivo::query()
            ->where('id', $solicitud)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $renewal->id_cliente]
        );
        $renewalBefore        = $renewal->toArray();
        $renewalAfter         = null;
        $originBefore         = null;
        $originAfter          = null;
        $proposedPath         = null;
        $destinationPath      = null;
        $newFileCopied        = false;
        $previousStoredValue  = null;
        $category             = null;
        $employeeForTrash     = null;
        $originId             = null;
        $trashPath            = null;
        $trashErrorMessage    = null;
        $proposalRemovalError = null;

        try {
            DB::connection('portal_main')->transaction(
                function () use (
                    $renewal,
                    $administrator,
                    $data,
                    &$proposedPath,
                    &$destinationPath,
                    &$newFileCopied,
                    &$previousStoredValue,
                    &$category,
                    &$employeeForTrash,
                    &$originId,
                    &$renewalAfter,
                    &$originBefore,
                    &$originAfter
                ): void {
                    $lockedRenewal =
                    SolicitudRenovacionArchivo::query()
                        ->where('id', $renewal->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (
                        $lockedRenewal->estado !==
                        SolicitudRenovacionArchivo::ESTADO_PENDIENTE
                    ) {
                        abort(
                            409,
                            'La solicitud ya fue resuelta.'
                        );
                    }

                    [$modelClass, $category] =
                    $this->originConfiguration(
                        $lockedRenewal->tipo
                    );

                    $origin = $modelClass::query()
                        ->where(
                            'id',
                            (int) $lockedRenewal->id_origen
                        )
                        ->where(
                            'employee_id',
                            (int) $lockedRenewal->id_empleado
                        )
                        ->where('status', '!=', 999)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $originBefore = $origin->only([
                        'id',
                        'employee_id',
                        'name',
                        'nameDocument',
                        'id_opcion',
                        'description',
                        'expiry_date',
                        'expiry_reminder',
                        'status',
                        'share_scope',
                        'collaborator_can_replace',
                        'edicion',
                    ]);
                    $employeeForTrash = Empleado::query()
                        ->where(
                            'id',
                            (int) $lockedRenewal->id_empleado
                        )
                        ->where(
                            'id_portal',
                            (int) $lockedRenewal->id_portal
                        )
                        ->where(
                            'id_cliente',
                            (int) $lockedRenewal->id_cliente
                        )
                        ->firstOrFail();

                    $originId = (int) $origin->id;

                    $previousStoredValue = trim(
                        (string) $origin->name
                    );

                    $currentName = basename(
                        str_replace(
                            '\\',
                            '/',
                            $previousStoredValue
                        )
                    );

                    if (
                        $currentName === ''
                        || $currentName !==
                        (string) $lockedRenewal->archivo_actual
                    ) {
                        abort(
                            409,
                            'El archivo vigente cambió después de crear la solicitud.'
                        );
                    }

                    $expectedEdition =
                    $lockedRenewal->edicion_origen
                        ? $lockedRenewal->edicion_origen
                        ->format('Y-m-d H:i:s')
                        : null;

                    $currentEdition =
                    $origin->getRawOriginal('edicion')
                        ? Carbon::parse(
                        $origin->getRawOriginal('edicion')
                    )->format('Y-m-d H:i:s')
                        : null;

                    if ($expectedEdition !== $currentEdition) {
                        abort(
                            409,
                            'El registro vigente cambió después de crear la solicitud.'
                        );
                    }

                    try {
                        $proposedPath = realpath(
                            $this->documentPaths
                                ->renewalAbsolutePath(
                                    (string) $lockedRenewal
                                        ->storage_path
                                )
                        );
                    } catch (
                        \InvalidArgumentException
                         | \RuntimeException $e
                    ) {
                        abort(404, $e->getMessage());
                    }

                    if (
                        $proposedPath === false
                        || ! is_file($proposedPath)
                    ) {
                        abort(
                            404,
                            'Archivo propuesto no encontrado.'
                        );
                    }

                    $newName = basename(
                        (string) $lockedRenewal
                            ->archivo_propuesto
                    );

                    if ($newName === '') {
                        abort(
                            422,
                            'El nombre del archivo propuesto no es válido.'
                        );
                    }

                    /*
                 * Confirma que la versión vigente todavía existe,
                 * ya sea antigua o nueva.
                 */
                    try {
                        $currentPath =
                        $this->documentPaths->absolutePath(
                            $category,
                            $previousStoredValue
                        );
                    } catch (
                        \InvalidArgumentException
                         | \RuntimeException $e
                    ) {
                        abort(409, $e->getMessage());
                    }

                    if (! is_file($currentPath)) {
                        abort(
                            409,
                            'El archivo vigente ya no existe.'
                        );
                    }

                    $newStoredValue =
                    $this->documentPaths->storedPath(
                        $employeeForTrash,
                        $newName
                    );

                    $destinationPath =
                    $this->documentPaths->absolutePath(
                        $category,
                        $newStoredValue
                    );

                    $destinationDirectory = dirname(
                        $destinationPath
                    );

                    if (
                        ! is_dir($destinationDirectory)
                        && ! mkdir(
                            $destinationDirectory,
                            0755,
                            true
                        )
                        && ! is_dir($destinationDirectory)
                    ) {
                        abort(
                            500,
                            'No se pudo crear el directorio de destino.'
                        );
                    }

                    if (is_file($destinationPath)) {
                        abort(
                            409,
                            'Ya existe un archivo con el nombre propuesto.'
                        );
                    }

                    /*
                 * Se copia primero. La propuesta se conserva hasta
                 * que la transacción quede confirmada.
                 */
                    if (! copy($proposedPath, $destinationPath)) {
                        abort(
                            500,
                            'No se pudo instalar el archivo propuesto.'
                        );
                    }

                    $newFileCopied = true;

                    @chmod($destinationPath, 0664);

                    $resolvedAt = now();

                    $origin->update([
                        'name'        => $newStoredValue,
                        'expiry_date' =>
                        $data['fecha_vencimiento'],
                        'edicion'     => $resolvedAt,
                    ]);

                    $lockedRenewal->update([
                        'estado'                     =>
                        SolicitudRenovacionArchivo::ESTADO_APROBADA,
                        'id_usuario_resolvio'        =>
                        (int) $administrator->id,
                        'comentario_resolucion'      =>
                        isset($data['comentario_resolucion'])
                            ? trim(
                            $data['comentario_resolucion']
                        )
                            : null,
                        'fecha_vencimiento_aprobada' =>
                        $data['fecha_vencimiento'],
                        'storage_path'               =>
                        $category . '/' . $newStoredValue,
                        'resolucion'                 => $resolvedAt,
                    ]);
                    $originAfter = $origin->only([
                        'id',
                        'employee_id',
                        'name',
                        'nameDocument',
                        'id_opcion',
                        'description',
                        'expiry_date',
                        'expiry_reminder',
                        'status',
                        'share_scope',
                        'collaborator_can_replace',
                        'edicion',
                    ]);

                    $renewalAfter = $lockedRenewal->toArray();
                }
            );
        } catch (\Throwable $exception) {
            /*
         * Si la BD falla, se retira la copia nueva.
         * La propuesta y el archivo vigente permanecen intactos.
         */
            if (
                $newFileCopied
                && is_string($destinationPath)
                && is_file($destinationPath)
            ) {
                @unlink($destinationPath);
            }

            throw $exception;
        }

        /*
     * La propuesta temporal se elimina solo después del commit.
     */
        if (
            is_string($proposedPath)
            && is_file($proposedPath)
        ) {
            if (! @unlink($proposedPath)) {
                $proposalRemovalError =
                    'No se pudo eliminar la propuesta temporal.';

                Log::warning(
                    '⚠️ No se pudo retirar la propuesta aprobada',
                    [
                        'solicitud_id' => (int) $renewal->id,
                        'path'         => $proposedPath,
                    ]
                );
            }
        }

        /*
     * La versión anterior va a borrados después de confirmar
     * la nueva referencia en la BD.
     */
        if (
            $employeeForTrash instanceof Empleado
            && is_string($category)
            && is_string($previousStoredValue)
            && $previousStoredValue !== ''
            && is_int($originId)
        ) {
            try {
                $trashPath = $this->documentPaths->moveToTrash(
                    $category,
                    $employeeForTrash,
                    $originId,
                    $previousStoredValue,
                    'reemplazados'
                );
            } catch (\Throwable $trashError) {
                $trashErrorMessage = $trashError->getMessage();

                Log::error(
                    '⚠️ No se pudo mover la versión renovada anterior',
                    [
                        'solicitud_id' => (int) $renewal->id,
                        'message'      => $trashError->getMessage(),
                    ]
                );
            }
        }
        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        if ($actorNombre === '') {
            $actorNombre = $administrator->email ?? $administrator->correo ?? null;
        }

        $versionAnteriorRespaldada = $trashPath !== null;

        $resultadoAuditoria =
        $versionAnteriorRespaldada
        && $proposalRemovalError === null
            ? 'exitoso'
            : 'exitoso_con_advertencia';

        $this->auditoria->registrar([
            'id_portal'    => (int) $renewal->id_portal,
            'id_cliente'   => (int) $renewal->id_cliente,

            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' => $actorNombre,

            'modulo'       => 'empleados',
            'entidad_tipo' => (string) $renewal->tipo,
            'entidad_id'   => (int) $renewal->id_origen,

            'accion'       => 'aprobar_renovacion',
            'resultado'    => $resultadoAuditoria,

            'descripcion'  =>
            $resultadoAuditoria === 'exitoso'
                ? "Se aprobó la renovación del {$renewal->tipo}."
                : "Se aprobó la renovación del {$renewal->tipo} con advertencias en el manejo de archivos.",

            'datos_anteriores' => [
                'origen'    => $originBefore,
                'solicitud' => $renewalBefore,
            ],

            'datos_nuevos'     => [
                'origen'    => $originAfter,
                'solicitud' => $renewalAfter,
            ],

            'metadatos'        => [
                'solicitud_id'                => (int) $renewal->id,
                'employee_id'                 => (int) $renewal->id_empleado,
                'categoria_almacenamiento'    => $category,
                'archivo_anterior'            => $previousStoredValue,
                'archivo_nuevo'               =>
                $originAfter['name'] ?? null,
                'archivo_respaldo'            => $trashPath,
                'version_anterior_respaldada' =>
                $versionAnteriorRespaldada,
                'error_traslado_anterior'     =>
                $trashErrorMessage,
                'error_eliminar_propuesta'    =>
                $proposalRemovalError,
                'fecha_vencimiento_aprobada'  =>
                $data['fecha_vencimiento'],
            ],
        ], $request);
        return response()->json([
            'message'           =>
            'Solicitud aprobada correctamente.',
            'solicitud_id'      => (int) $renewal->id,
            'estado'            =>
            SolicitudRenovacionArchivo::ESTADO_APROBADA,
            'fecha_vencimiento' =>
            $data['fecha_vencimiento'],
            'trash_path'        => $trashPath,
        ]);
    }

    private function originConfiguration(
        string $type
    ): array {
        return match ($type) {
            SolicitudRenovacionArchivo::TIPO_DOCUMENTO => [
                DocumentEmpleado::class,
                '_documentEmpleado',
            ],
            SolicitudRenovacionArchivo::TIPO_CURSO     => [
                CursoEmpleado::class,
                '_cursos',
            ],
            SolicitudRenovacionArchivo::TIPO_EXAMEN    => [
                ExamEmpleado::class,
                '_examEmpleado',
            ],
            default                                    => abort(
                422,
                'Tipo de archivo no válido.'
            ),
        };
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
