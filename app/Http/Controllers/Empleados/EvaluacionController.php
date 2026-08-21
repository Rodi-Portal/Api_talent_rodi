<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Evaluacion;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\EvaluationDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class EvaluacionController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private EvaluationDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}

    public function getEvaluations(Request $request)
    {
        $data = $request->validate([
            'id_portal'  => ['nullable', 'integer', 'min:1'],
            'id_cliente' => ['required', 'integer', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $this->authorizeClient(
            $administrator,
            (int) $data['id_cliente']
        );

        if (
            isset($data['id_portal'])
            && (int) $data['id_portal'] !==
            (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'El portal solicitado no coincide con el portal autenticado.'
            );
        }

        $evaluations = Evaluacion::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $data['id_cliente']
            )
            ->where('eliminado', 0)
            ->orderByDesc('id')
            ->get()
            ->map(function (Evaluacion $evaluation) {
                $result                     = $evaluation->toArray();
                $result['statusEvaluacion'] =
                $this->checkDocumentStatus($evaluation);

                return $result;
            })
            ->values();

        return response()->json($evaluations);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_cliente'           => ['required', 'integer', 'min:1'],
            'name'                 => ['required', 'string', 'max:255'],
            'numero_participantes' => ['nullable', 'integer', 'min:1'],
            'departamento'         => ['nullable', 'string', 'max:250'],
            'description'          => ['nullable', 'string'],
            'conclusiones'         => ['nullable', 'string'],
            'acciones'             => ['nullable', 'string'],
            'expiry_date'          => ['required', 'date'],
            'expiry_reminder'      => [
                'nullable',
                'integer',
                'in:0,1,7,15,30',
            ],
            'origen'               => ['nullable', 'integer', 'in:1,2'],
            'file'                 => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ]);

        $administrator = $this->administrator($request);
        $clientId      = (int) $data['id_cliente'];

        $this->authorizeClient($administrator, $clientId);

        $file = $request->file('file');

        $mimeType  = $file->getClientMimeType();
        $sizeBytes = $file->getSize();

        $extension = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            strtolower(
                (string) $file->getClientOriginalExtension()
            )
        );

        $physicalName = 'eval_'
        . $clientId
        . '_'
        . bin2hex(random_bytes(12));

        if ($extension !== '') {
            $physicalName .= '.' . $extension;
        }

        $targetDirectory = $this->documentPaths
            ->activeDirectory(
                (int) $administrator->id_portal,
                $clientId
            );

        if (
            ! is_dir($targetDirectory)
            && ! @mkdir($targetDirectory, 0755, true)
            && ! is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de evaluaciones.'
            );
        }

        $storedPath = $this->documentPaths->storedPath(
            (int) $administrator->id_portal,
            $clientId,
            $physicalName
        );

        $file->move($targetDirectory, $physicalName);

        $absolutePath = $targetDirectory
            . DIRECTORY_SEPARATOR
            . $physicalName;

        @chmod($absolutePath, 0664);

        $now = Carbon::now('America/Mexico_City');

        try {
            $evaluation = DB::connection('portal_main')
                ->transaction(function () use (
                    $administrator,
                    $clientId,
                    $data,
                    $storedPath,
                    $now
                ) {
                    $evaluation = new Evaluacion();

                    $evaluation->id_portal =
                    (int) $administrator->id_portal;

                    $evaluation->id_usuario =
                    (int) $administrator->id;

                    $evaluation->id_cliente = $clientId;
                    $evaluation->name       = $data['name'];

                    $evaluation->numero_participantes =
                    $data['numero_participantes'] ?? null;

                    $evaluation->departamento =
                    $data['departamento'] ?? null;

                    $evaluation->name_document = $storedPath;

                    $evaluation->description =
                    $data['description'] ?? null;

                    $evaluation->conclusiones =
                    $data['conclusiones'] ?? null;

                    $evaluation->acciones =
                    $data['acciones'] ?? null;

                    $evaluation->expiry_date =
                        $data['expiry_date'];

                    $evaluation->expiry_reminder =
                    (int) ($data['expiry_reminder'] ?? 0);

                    $evaluation->origen =
                    (int) ($data['origen'] ?? 1);

                    $evaluation->eliminado = 0;
                    $evaluation->creacion  = $now;
                    $evaluation->edicion   = $now;
                    $evaluation->save();

                    return $evaluation;
                });
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            throw $exception;
        }

        $this->audit(
            $request,
            $administrator,
            $evaluation,
            'evaluacion_cargada',
            null,
            $this->auditData($evaluation),
            [
                'storage_origen' => 'nuevo',
                'mime_type'      => $mimeType,
                'size_bytes'     => $sizeBytes,
            ]
        );

        return response()->json([
            'message'    => 'Evaluación agregada exitosamente.',
            'evaluacion' => $evaluation,
        ], 201);
    }

    public function download(
        Request $request,
        Evaluacion $evaluacion
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeEvaluation(
            $administrator,
            $evaluacion
        );

        if ((int) $evaluacion->eliminado !== 0) {
            abort(404, 'La evaluación no está disponible.');
        }

        try {
            $absolutePath = $this->documentPaths
                ->existingAbsolutePath(
                    (string) $evaluacion->name_document
                );
        } catch (
            InvalidArgumentException | RuntimeException $exception
        ) {
            return response()->json([
                'message' => 'Archivo no encontrado.',
            ], 404);
        }

        $downloadName = basename(
            str_replace(
                '\\',
                '/',
                (string) $evaluacion->name_document
            )
        );

        if (
            str_ends_with(
                strtolower($absolutePath),
                '.zip'
            )
            && ! str_ends_with(
                strtolower($downloadName),
                '.zip'
            )
        ) {
            $downloadName .= '.zip';
        }

        $this->audit(
            $request,
            $administrator,
            $evaluacion,
            'evaluacion_descargada',
            null,
            null,
            [
                'storage_path'   =>
                $evaluacion->name_document,
                'storage_origen' =>
                $this->documentPaths->storageOrigin(
                    (string) $evaluacion->name_document
                ),
                'size_bytes'     => filesize($absolutePath),
                'modo'           => 'descarga',
            ]
        );

        return response()->download(
            $absolutePath,
            $downloadName
        );
    }

    public function update(
        Request $request,
        Evaluacion $evaluacion
    ) {
        $data = $request->validate([
            'name'                 => ['sometimes', 'string', 'max:255'],
            'numero_participantes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
            ],
            'departamento'         => [
                'sometimes',
                'nullable',
                'string',
                'max:250',
            ],
            'description'          => ['sometimes', 'nullable', 'string'],
            'conclusiones'         => ['sometimes', 'nullable', 'string'],
            'acciones'             => ['sometimes', 'nullable', 'string'],
            'expiry_date'          => ['sometimes', 'date'],
            'expiry_reminder'      => [
                'sometimes',
                'nullable',
                'integer',
                'in:0,1,7,15,30',
            ],
            'origen'               => [
                'sometimes',
                'nullable',
                'integer',
                'in:1,2',
            ],
            'file'                 => [
                'sometimes',
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:10240',
            ],
        ]);

        $administrator = $this->administrator($request);

        $this->authorizeEvaluation(
            $administrator,
            $evaluacion
        );

        if ((int) $evaluacion->eliminado !== 0) {
            abort(404, 'La evaluación no está disponible.');
        }

        $previousData    = $this->auditData($evaluacion);
        $movement        = null;
        $newAbsolutePath = null;

        if ($request->hasFile('file')) {
            $file = $request->file('file');

            $extension = preg_replace(
                '/[^A-Za-z0-9]/',
                '',
                strtolower(
                    (string) $file->getClientOriginalExtension()
                )
            );

            $physicalName = 'eval_'
            . (int) $evaluacion->id
            . '_'
            . bin2hex(random_bytes(12));

            if ($extension !== '') {
                $physicalName .= '.' . $extension;
            }

            $targetDirectory = $this->documentPaths
                ->activeDirectory(
                    (int) $evaluacion->id_portal,
                    (int) $evaluacion->id_cliente
                );

            if (
                ! is_dir($targetDirectory)
                && ! @mkdir($targetDirectory, 0755, true)
                && ! is_dir($targetDirectory)
            ) {
                throw new RuntimeException(
                    'No se pudo crear el directorio de evaluaciones.'
                );
            }

            $newStoredPath = $this->documentPaths
                ->storedPath(
                    (int) $evaluacion->id_portal,
                    (int) $evaluacion->id_cliente,
                    $physicalName
                );

            $file->move(
                $targetDirectory,
                $physicalName
            );

            $newAbsolutePath = $targetDirectory
                . DIRECTORY_SEPARATOR
                . $physicalName;

            @chmod($newAbsolutePath, 0664);

            try {
                $movement = $this->documentPaths
                    ->moveToTrash($evaluacion);
            } catch (Throwable $exception) {
                @unlink($newAbsolutePath);
                throw $exception;
            }

            $data['name_document'] = $newStoredPath;
        }

        $allowedFields = [
            'name',
            'numero_participantes',
            'departamento',
            'description',
            'conclusiones',
            'acciones',
            'expiry_date',
            'expiry_reminder',
            'origen',
            'name_document',
        ];

        try {
            DB::connection('portal_main')->transaction(
                function () use (
                    $evaluacion,
                    $data,
                    $allowedFields
                ) {
                    foreach ($allowedFields as $field) {
                        if (array_key_exists($field, $data)) {
                            $evaluacion->{$field} = $data[$field];
                        }
                    }

                    $evaluacion->edicion = Carbon::now(
                        'America/Mexico_City'
                    );

                    $evaluacion->save();
                }
            );
        } catch (Throwable $exception) {
            if (
                $newAbsolutePath !== null
                && is_file($newAbsolutePath)
            ) {
                @unlink($newAbsolutePath);
            }

            if ($movement !== null) {
                try {
                    $this->documentPaths
                        ->rollbackMovement($movement);
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                }
            }

            throw $exception;
        }

        $evaluacion->refresh();

        $this->audit(
            $request,
            $administrator,
            $evaluacion,
            'evaluacion_actualizada',
            $previousData,
            $this->auditData($evaluacion),
            [
                'archivo_reemplazado'     =>
                $movement !== null,
                'archivo_compartido'      =>
                $movement['archivo_compartido'] ?? false,
                'storage_origen_anterior' =>
                $movement['origen'] ?? null,
            ]
        );

        return response()->json($evaluacion);
    }

    public function destroy(
        Request $request,
        Evaluacion $evaluacion
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeEvaluation(
            $administrator,
            $evaluacion
        );

        if ((int) $evaluacion->eliminado !== 0) {
            return response()->json(['ok' => true]);
        }

        $previousData = $this->auditData($evaluacion);

        $movement = $this->documentPaths
            ->moveToTrash($evaluacion);

        try {
            DB::connection('portal_main')->transaction(
                function () use (
                    $evaluacion,
                    $movement
                ) {
                    $evaluacion->eliminado = 1;

                    if ($movement !== null) {
                        $evaluacion->name_document =
                            $movement['ruta_borrado'];
                    }

                    $evaluacion->edicion = Carbon::now(
                        'America/Mexico_City'
                    );

                    $evaluacion->save();
                }
            );
        } catch (Throwable $exception) {
            if ($movement !== null) {
                try {
                    $this->documentPaths
                        ->rollbackMovement($movement);
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                }
            }

            throw $exception;
        }

        $this->audit(
            $request,
            $administrator,
            $evaluacion,
            'evaluacion_eliminada',
            $previousData,
            $this->auditData($evaluacion),
            [
                'archivo_procesado'  => $movement !== null,
                'archivo_compartido' =>
                $movement['archivo_compartido'] ?? false,
                'storage_origen'     =>
                $movement['origen'] ?? null,
            ]
        );

        return response()->json(['ok' => true]);
    }

    private function checkDocumentStatus(
        Evaluacion $evaluation
    ): string {
        $reminder = (int) ($evaluation->expiry_reminder ?? 0);

        if ($reminder <= 0) {
            return 'verde';
        }

        $today      = Carbon::today('America/Mexico_City');
        $expiryDate = Carbon::parse(
            $evaluation->expiry_date,
            'America/Mexico_City'
        )->startOfDay();

        $days = $today->diffInDays(
            $expiryDate,
            false
        );

        if ($days < 0 || $days <= $reminder) {
            return 'rojo';
        }

        if ($days <= $reminder + 7) {
            return 'amarillo';
        }

        return 'verde';
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

    private function authorizeClient(
        AdministradorAuth $administrator,
        int $clientId
    ): void {
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$clientId]
        );
    }

    private function authorizeEvaluation(
        AdministradorAuth $administrator,
        Evaluacion $evaluation
    ): void {
        if (
            (int) $evaluation->id_portal !==
            (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'La evaluación no pertenece al portal autenticado.'
            );
        }

        $this->authorizeClient(
            $administrator,
            (int) $evaluation->id_cliente
        );
    }

    private function auditData(
        Evaluacion $evaluation
    ): array {
        return [
            'nombre'               => $evaluation->name,
            'name_document'        =>
            $evaluation->name_document,
            'numero_participantes' =>
            $evaluation->numero_participantes,
            'departamento'         =>
            $evaluation->departamento,
            'expiry_date'          =>
            $evaluation->expiry_date,
            'expiry_reminder'      =>
            $evaluation->expiry_reminder,
            'origen'               =>
            $evaluation->origen,
            'eliminado'            =>
            (int) $evaluation->eliminado,
        ];
    }

    private function audit(
        Request $request,
        AdministradorAuth $administrator,
        Evaluacion $evaluation,
        string $action,
        ?array $previousData,
        ?array $newData,
        ?array $metadata = null
    ): void {
        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $evaluation->id_portal,
            'id_cliente'       =>
            (int) $evaluation->id_cliente,
            'actor_tipo'       =>
            'administrador',
            'actor_id'         =>
            (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           =>
            'evaluaciones',
            'entidad_tipo'     =>
            'evaluacion',
            'entidad_id'       =>
            (int) $evaluation->id,
            'accion'           =>
            $action,
            'resultado'        =>
            'exitoso',
            'datos_anteriores' =>
            $previousData,
            'datos_nuevos'     =>
            $newData,
            'metadatos'        =>
            $metadata,
        ], $request);
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
