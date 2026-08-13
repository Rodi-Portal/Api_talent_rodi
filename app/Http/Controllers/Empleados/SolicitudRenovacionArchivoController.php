<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Models\SolicitudRenovacionArchivo;
use App\Services\Auth\AdminClientScopeService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SolicitudRenovacionArchivoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
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

        $basePath = realpath(
            (string) config('paths.images_path')
        );

        if ($basePath === false) {
            abort(500, 'La ruta de archivos no está disponible.');
        }

        $relativePath = ltrim(
            str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                (string) $renewal->storage_path
            ),
            DIRECTORY_SEPARATOR
        );

        $filePath = realpath(
            $basePath
            . DIRECTORY_SEPARATOR
            . $relativePath
        );

        $basePrefix = rtrim(
            $basePath,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        if (
            $filePath === false ||
            ! str_starts_with($filePath, $basePrefix) ||
            ! is_file($filePath)
        ) {
            abort(404, 'Archivo propuesto no encontrado.');
        }

        $mimeType = mime_content_type($filePath)
            ?: 'application/octet-stream';

        $displayName = basename(
            (string) $renewal->nombre_original
        );

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

        DB::connection('portal_main')->transaction(
            function () use (
                $renewal,
                $administrator,
                $data
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
            }
        );

        return response()->json([
            'message'      => 'Solicitud rechazada correctamente.',
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

        $proposedPath    = null;
        $destinationPath = null;
        $currentPath     = null;
        $deletedPath     = null;
        $oldFileMoved    = false;
        $newFileMoved    = false;

        try {
            DB::connection('portal_main')->transaction(
                function () use (
                    $renewal,
                    $administrator,
                    $data,
                    &$proposedPath,
                    &$destinationPath,
                    &$currentPath,
                    &$deletedPath,
                    &$oldFileMoved,
                    &$newFileMoved
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
                        abort(409, 'La solicitud ya fue resuelta.');
                    }

                    [$modelClass, $folder] =
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

                    $currentName = basename(
                        (string) $origin->name
                    );

                    if (
                        $currentName === '' ||
                        $currentName !==
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

                    if (
                        $expectedEdition !== $currentEdition
                    ) {
                        abort(
                            409,
                            'El registro vigente cambió después de crear la solicitud.'
                        );
                    }

                    $basePath = realpath(
                        (string) config('paths.images_path')
                    );

                    if ($basePath === false) {
                        abort(
                            500,
                            'La ruta de archivos no está disponible.'
                        );
                    }

                    $basePrefix = rtrim(
                        $basePath,
                        DIRECTORY_SEPARATOR
                    ) . DIRECTORY_SEPARATOR;

                    $relativeProposedPath = ltrim(
                        str_replace(
                            ['/', '\\'],
                            DIRECTORY_SEPARATOR,
                            (string) $lockedRenewal->storage_path
                        ),
                        DIRECTORY_SEPARATOR
                    );

                    $proposedPath = realpath(
                        $basePath
                        . DIRECTORY_SEPARATOR
                        . $relativeProposedPath
                    );

                    if (
                        $proposedPath === false ||
                        ! str_starts_with(
                            $proposedPath,
                            $basePrefix
                        ) ||
                        ! is_file($proposedPath)
                    ) {
                        abort(
                            404,
                            'Archivo propuesto no encontrado.'
                        );
                    }

                    $folderPath = realpath(
                        $basePath
                        . DIRECTORY_SEPARATOR
                        . $folder
                    );

                    if (
                        $folderPath === false ||
                        ! str_starts_with(
                            $folderPath
                            . DIRECTORY_SEPARATOR,
                            $basePrefix
                        )
                    ) {
                        abort(
                            500,
                            'El directorio de destino no está disponible.'
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

                    $currentPath =
                        $folderPath
                        . DIRECTORY_SEPARATOR
                        . $currentName;

                    $destinationPath =
                        $folderPath
                        . DIRECTORY_SEPARATOR
                        . $newName;

                    if (! is_file($currentPath)) {
                        abort(
                            409,
                            'El archivo vigente ya no existe.'
                        );
                    }

                    if (is_file($destinationPath)) {
                        abort(
                            409,
                            'Ya existe un archivo con el nombre propuesto.'
                        );
                    }

                    $deletedDirectory =
                        $folderPath
                        . DIRECTORY_SEPARATOR
                        . '_borrados';

                    if (
                        ! is_dir($deletedDirectory) &&
                        ! mkdir(
                            $deletedDirectory,
                            0755,
                            true
                        ) &&
                        ! is_dir($deletedDirectory)
                    ) {
                        abort(
                            500,
                            'No se pudo crear el directorio de archivos anteriores.'
                        );
                    }

                    $deletedName =
                    'solicitud_'
                    . $lockedRenewal->id
                        . '_'
                        . $currentName;

                    $deletedPath =
                        $deletedDirectory
                        . DIRECTORY_SEPARATOR
                        . $deletedName;

                    if (is_file($deletedPath)) {
                        abort(
                            409,
                            'Ya existe el respaldo de esta solicitud.'
                        );
                    }

                    if (
                        ! rename(
                            $currentPath,
                            $deletedPath
                        )
                    ) {
                        abort(
                            500,
                            'No se pudo respaldar el archivo vigente.'
                        );
                    }

                    $oldFileMoved = true;

                    if (
                        ! rename(
                            $proposedPath,
                            $destinationPath
                        )
                    ) {
                        abort(
                            500,
                            'No se pudo instalar el archivo propuesto.'
                        );
                    }

                    $newFileMoved = true;

                    $resolvedAt = now();

                    $origin->update([
                        'name'        => $newName,
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
                        $folder . '/' . $newName,
                        'resolucion'                 => $resolvedAt,
                    ]);
                }
            );
        } catch (\Throwable $exception) {
            if (
                $newFileMoved &&
                is_string($destinationPath) &&
                is_file($destinationPath) &&
                is_string($proposedPath)
            ) {
                @rename(
                    $destinationPath,
                    $proposedPath
                );
            }

            if (
                $oldFileMoved &&
                is_string($deletedPath) &&
                is_file($deletedPath) &&
                is_string($currentPath)
            ) {
                @rename(
                    $deletedPath,
                    $currentPath
                );
            }

            throw $exception;
        }

        return response()->json([
            'message'           => 'Solicitud aprobada correctamente.',
            'solicitud_id'      => (int) $renewal->id,
            'estado'            =>
            SolicitudRenovacionArchivo::ESTADO_APROBADA,
            'fecha_vencimiento' =>
            $data['fecha_vencimiento'],
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
