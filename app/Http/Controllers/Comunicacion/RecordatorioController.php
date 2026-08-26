<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicacion\RecordatorioRequest;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion\Recordatorio;
use App\Models\Comunicacion\RecordatorioEvidencia;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\ReminderDocumentPathService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class RecordatorioController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private ReminderDocumentPathService $reminderPaths,
        private AuditoriaService $auditoria
    ) {}
    /* ===== LISTA con filtros ===== */
    public function index(Request $request)
    {
        $administrator = $this->administrator($request);

        $clientsRaw = trim(
            (string) $request->query('clientes', '')
        );

        $clientIds = collect(explode(',', $clientsRaw))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($clientIds === []) {
            return response()->json([
                'message' => 'Debe seleccionar al menos una sucursal.',
            ], 422);
        }

        $authorizedClientIds =
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            $clientIds
        );

        $search = $request->query('q');
        $from   = $request->query('desde');
        $until  = $request->query('hasta');
        $active = $request->query('activo');

        $allowedSorts = [
            'nombre',
            'proxima_fecha',
            'tipo',
            'activo',
        ];

        $sort = in_array(
            $request->query('sort'),
            $allowedSorts,
            true
        )
            ? $request->query('sort')
            : 'proxima_fecha';

        $direction =
        strtolower((string) $request->query('dir')) === 'desc'
            ? 'desc'
            : 'asc';

        $perPage = min(
            100,
            max(1, (int) $request->query('per_page', 20))
        );

        $page = max(1, (int) $request->query('page', 1));

        $query = Recordatorio::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $authorizedClientIds)
            ->vigentes()
            ->when(
                $search,
                fn($builder) => $builder->where(
                    function ($nested) use ($search) {
                        $nested
                            ->where(
                                'nombre',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'descripcion',
                                'like',
                                "%{$search}%"
                            );
                    }
                )
            )
            ->when(
                $from,
                fn($builder) =>
                $builder->where('fecha_base', '>=', $from)
            )
            ->when(
                $until,
                fn($builder) =>
                $builder->where('fecha_base', '<=', $until)
            )
            ->when(
                $active === '0' || $active === '1',
                fn($builder) =>
                $builder->where('activo', (int) $active)
            )
            ->orderBy($sort, $direction);

        $result = $query->paginate(
            $perPage,
            ['*'],
            'page',
            $page
        );

        return response()->json([
            'data'     => $result->items(),
            'page'     => $result->currentPage(),
            'per_page' => $result->perPage(),
            'total'    => $result->total(),
        ]);
    }

    /* ===== CREAR usando /_recordadorios/{portal}/{cliente} ===== */
    public function storeForPortalCliente(
        $idPortal,
        $idCliente,
        RecordatorioRequest $request
    ) {
        $administrator = $this->administrator($request);

        if (
            (int) $idPortal
            !== (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'El portal solicitado no corresponde a la sesión.'
            );
        }

        $clientId = (int) $idCliente;

        $this->authorizeClient(
            $administrator,
            $clientId
        );

        $data = $request->validated();
        $now  = now()->format('Y-m-d H:i:s');

        $payload = array_merge($data, [
            'id_portal'      =>
            (int) $administrator->id_portal,
            'id_cliente'     => $clientId,
            'id_usuario'     => (int) $administrator->id,
            'proxima_fecha'  => $this->calcProximaFecha(
                $data['tipo'],
                $data['fecha_base'],
                $data['intervalo_meses'] ?? null
            ),
            'creado_en'      => $now,
            'actualizado_en' => $now,
        ]);

        $id = Recordatorio::insertGetId($payload);
        $this->registerAudit(
            $request,
            $administrator,
            $clientId,
            'recordatorio',
            (int) $id,
            'recordatorio_creado',
            'exitoso',
            'Se creó un recordatorio.',
            [
                'tipo'   => $payload['tipo'],
                'activo' => (int) $payload['activo'],
            ],
            null,
            $payload
        );
        return response()->json(['id' => $id], 201);
    }

    /* ===== ACTUALIZAR usando /_recordadorios/{portal}/{cliente}/{id} ===== */
    public function updateForPortalCliente(
        $idPortal,
        $idCliente,
        $id,
        RecordatorioRequest $request
    ) {
        $administrator = $this->administrator($request);

        if (
            (int) $idPortal
            !== (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'El portal solicitado no corresponde a la sesión.'
            );
        }
        $clientId = (int) $idCliente;

        $this->authorizeClient(
            $administrator,
            $clientId
        );

        $reminder = $this->authorizedReminder(
            $administrator,
            (int) $id
        );

        if ((int) $reminder->id_cliente !== $clientId) {
            abort(404, 'Recordatorio no encontrado.');
        }
     $previousData = $reminder->only([
            'nombre',
            'descripcion',
            'tipo',
            'fecha_base',
            'intervalo_meses',
            'dias_anticipacion',
            'proxima_fecha',
            'activo',
        ]);
        $data = $request->validated();

        if (
            isset($data['tipo'])
            || isset($data['fecha_base'])
            || isset($data['intervalo_meses'])
        ) {
            $type     = $data['tipo'] ?? $reminder->tipo;
            $baseDate = $data['fecha_base'] ?? $reminder->fecha_base->format('Y-m-d');
            $interval = $data['intervalo_meses'] ?? $reminder->intervalo_meses;

            $data['proxima_fecha'] =
            $this->calcProximaFecha(
                $type,
                $baseDate,
                $interval
            );
        }

        $data['id_usuario']     = (int) $administrator->id;
        $data['actualizado_en'] =
        now()->format('Y-m-d H:i:s');

        $reminder->fill($data)->save();
        $newData = $reminder->fresh()->only([
            'nombre',
            'descripcion',
            'tipo',
            'fecha_base',
            'intervalo_meses',
            'dias_anticipacion',
            'proxima_fecha',
            'activo',
        ]);

        $this->registerAudit(
            $request,
            $administrator,
            $clientId,
            'recordatorio',
            (int) $reminder->id,
            'recordatorio_actualizado',
            'exitoso',
            'Se actualizó un recordatorio.',
            null,
            $previousData,
            $newData
        );
        return response()->json([
            'ok' => true,
            'id' => (int) $reminder->id,
        ]);
    }

    /* ===== ELIMINAR (borrado lógico) ===== */
    public function destroy(Request $request, $id)
    {
        $administrator = $this->administrator($request);

        $reminder = $this->authorizedReminder(
            $administrator,
            (int) $id
        );
        $previousData = $reminder->only([
            'nombre',
            'descripcion',
            'tipo',
            'fecha_base',
            'proxima_fecha',
            'activo',
            'eliminado',
        ]);
        $portalId = (int) $administrator->id_portal;
        $clientId = (int) $reminder->id_cliente;

        $evidences = $reminder->evidencias()
            ->whereNull('eliminado_en')
            ->get();

        $movedFiles = [];

        DB::connection('portal_main')->beginTransaction();

        try {
            $now = now()->format('Y-m-d H:i:s');

            foreach ($evidences as $evidence) {
                $originalStoredPath = (string) (
                    $evidence->url_archivo ?? ''
                );

                $movedFile = null;

                if ($originalStoredPath !== '') {
                    $movedFile = $this->reminderPaths->moveToTrash(
                        $originalStoredPath,
                        $portalId,
                        $clientId
                    );
                }

                if ($movedFile) {
                    $movedFiles[] = $movedFile;

                    $evidence->url_archivo =
                        $movedFile['ruta_borrado'];
                }

                $evidence->eliminado_en   = $now;
                $evidence->actualizado_en = $now;
                $evidence->id_usuario     =
                (int) $administrator->id;

                $evidence->save();
            }

            $reminder->id_usuario =
            (int) $administrator->id;
            $reminder->eliminado      = 1;
            $reminder->actualizado_en = $now;
            $reminder->save();

            DB::connection('portal_main')->commit();
        } catch (\Throwable $exception) {
            DB::connection('portal_main')->rollBack();

            foreach (array_reverse($movedFiles) as $movedFile) {
                try {
                    $this->reminderPaths->restoreMovedFile(
                        $movedFile['ruta_borrado'],
                        $movedFile['ruta_anterior'],
                        $portalId,
                        $clientId
                    );
                } catch (\Throwable $restoreException) {
                    \Log::critical(
                        'No se pudo restaurar una evidencia al revertir la eliminación de un recordatorio.',
                        [
                            'recordatorio_id' => (int) $reminder->id,
                            'ruta_origen'     =>
                            $movedFile['ruta_anterior'],
                            'ruta_borrado'    =>
                            $movedFile['ruta_borrado'],
                            'message'         =>
                            $restoreException->getMessage(),
                        ]
                    );
                }
            }

            throw $exception;
        }
        $this->registerAudit(
            $request,
            $administrator,
            $clientId,
            'recordatorio',
            (int) $reminder->id,
            'recordatorio_eliminado',
            'exitoso',
            'Se eliminó lógicamente un recordatorio y se respaldaron sus evidencias.',
            [
                'evidencias_eliminadas' => $evidences->count(),
                'archivos_movidos'      => count($movedFiles),
                'rutas_borrado'         =>
                array_column($movedFiles, 'ruta_borrado'),
            ],
            $previousData,
            [
                'eliminado' => 1,
            ]
        );

        return response()->json([
            'ok'                    => true,
            'recordatorio_id'       => (int) $reminder->id,
            'evidencias_eliminadas' => $evidences->count(),
            'archivos_movidos'      => count($movedFiles),
        ]);
    }

    /* ===== EVIDENCIAS ===== */
    public function evidenciasIndex(
        Request $request,
        $id
    ) {
        $administrator = $this->administrator($request);

        $reminder = $this->authorizedReminder(
            $administrator,
            (int) $id
        );

        $data = $reminder->evidencias()
            ->whereNull('eliminado_en')
            ->orderByDesc('id')
            ->get([
                'id',
                'tipo',
                'titulo',
                'descripcion',
                'monto',
                'moneda',
                'referencia',
                'url_archivo',
                'actualizado_en',
            ]);

        return response()->json(['data' => $data]);
    }

    public function evidenciasStore(
        Request $request,
        $id
    ) {
        $administrator = $this->administrator($request);

        $reminder = $this->authorizedReminder(
            $administrator,
            (int) $id
        );

        $request->validate([
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => [
                'required',
                'file',
                'max:8192',
                'mimes:pdf,jpg,jpeg,png,webp,heic',
            ],
        ]);

        $insertedIds  = [];
        $writtenFiles = [];
        $auditFiles   = [];
        DB::connection('portal_main')->beginTransaction();

        try {
            foreach ($request->file('files', []) as $file) {
                $extension = strtolower(
                    $file->getClientOriginalExtension() ?: 'bin'
                );

                $filename = now()->format('Ymd_His')
                . '_'
                . Str::random(12)
                    . '.'
                    . $extension;

                $originalName = basename(
                    $file->getClientOriginalName()
                );

                $portalId =
                (int) $administrator->id_portal;
                $clientId =
                (int) $reminder->id_cliente;

                $absoluteDirectory =
                $this->reminderPaths->activeDirectoryPath(
                    $portalId,
                    $clientId
                );

                $storedPath =
                $this->reminderPaths->activeStoredPath(
                    $portalId,
                    $clientId,
                    $filename
                );

                $this->ensureDir($absoluteDirectory);

                $file->move(
                    $absoluteDirectory,
                    $filename
                );

                $absolutePath = $absoluteDirectory
                    . DIRECTORY_SEPARATOR
                    . $filename;

                @chmod($absolutePath, 0664);

                $writtenFiles[] = $absolutePath;

                $evidenceId = (int) RecordatorioEvidencia::insertGetId([
                    'id_portal'       => $portalId,
                    'id_cliente'      => $clientId,
                    'id_recordatorio' =>
                    (int) $reminder->id,
                    'tipo'            => 'archivo',
                    'titulo'          => $originalName,
                    'descripcion'     => null,
                    'url_archivo'     => $storedPath,
                    'id_usuario'      =>
                    (int) $administrator->id,
                ]);

                $insertedIds[] = $evidenceId;

                $auditFiles[] = [
                    'id'            => $evidenceId,
                    'storage_path'  => $storedPath,
                    'original_name' => $originalName,
                    'physical_name' => $filename,
                    'mime_type'     =>
                    File::mimeType($absolutePath)
                        ?: 'application/octet-stream',
                    'size_bytes'    =>
                    filesize($absolutePath) ?: 0,
                ];
            }

            DB::connection('portal_main')->commit();
        } catch (\Throwable $exception) {
            DB::connection('portal_main')->rollBack();

            foreach (
                array_reverse($writtenFiles) as $writtenFile
            ) {
                if (is_file($writtenFile)) {
                    @unlink($writtenFile);
                }
            }

            throw $exception;
        }
                foreach ($auditFiles as $auditFile) {
            $this->registerAudit(
                $request,
                $administrator,
                (int) $reminder->id_cliente,
                'recordatorio_evidencia',
                (int) $auditFile['id'],
                'evidencia_recordatorio_cargada',
                'exitoso',
                'Se cargó una evidencia para un recordatorio.',
                [
                    'id_recordatorio' =>
                        (int) $reminder->id,
                    'storage_path'    =>
                        $auditFile['storage_path'],
                    'nombre_original' =>
                        $auditFile['original_name'],
                    'nombre_fisico'   =>
                        $auditFile['physical_name'],
                    'mime_type'       =>
                        $auditFile['mime_type'],
                    'size_bytes'      =>
                        $auditFile['size_bytes'],
                ]
            );
        }
        return response()->json([
            'subidos' => count($insertedIds),
            'ids'     => $insertedIds,
        ], 201);
    }

    public function evidenciasDestroy(
        Request $request,
        $docId
    ) {
        $administrator = $this->administrator($request);

        $evidence = $this->authorizedEvidence(
            $administrator,
            (int) $docId
        );

        $portalId = (int) $administrator->id_portal;
        $clientId = (int) $evidence->id_cliente;

        $originalStoredPath = (string) (
            $evidence->url_archivo ?? ''
        );
        $previousData = [
            'url_archivo'  => $originalStoredPath,
            'eliminado_en' => $evidence->eliminado_en,
        ];
        $movedFile = null;

        DB::connection('portal_main')->beginTransaction();

        try {
            if ($originalStoredPath !== '') {
                $movedFile = $this->reminderPaths->moveToTrash(
                    $originalStoredPath,
                    $portalId,
                    $clientId
                );
            }

            $now = now()->format('Y-m-d H:i:s');

            $evidence->eliminado_en   = $now;
            $evidence->actualizado_en = $now;
            $evidence->id_usuario     =
            (int) $administrator->id;

            if ($movedFile) {
                $evidence->url_archivo =
                    $movedFile['ruta_borrado'];
            }

            $evidence->save();

            DB::connection('portal_main')->commit();
        } catch (\Throwable $exception) {
            DB::connection('portal_main')->rollBack();

            if ($movedFile) {
                try {
                    $this->reminderPaths->restoreMovedFile(
                        $movedFile['ruta_borrado'],
                        $movedFile['ruta_anterior'],
                        $portalId,
                        $clientId
                    );
                } catch (\Throwable $restoreException) {
                    \Log::critical(
                        'No se pudo restaurar una evidencia de recordatorio.',
                        [
                            'evidencia_id' => (int) $evidence->id,
                            'ruta_origen'  =>
                            $movedFile['ruta_anterior'],
                            'ruta_borrado' =>
                            $movedFile['ruta_borrado'],
                            'message'      =>
                            $restoreException->getMessage(),
                        ]
                    );
                }
            }

            throw $exception;
        }
        $this->registerAudit(
            $request,
            $administrator,
            $clientId,
            'recordatorio_evidencia',
            (int) $evidence->id,
            'evidencia_recordatorio_eliminada',
            'exitoso',
            'Se eliminó lógicamente una evidencia y se movió a borrados.',
            [
                'id_recordatorio' => (int) $evidence->id_recordatorio,
                'archivo_movido'  => $movedFile !== null,
                'ruta_anterior'   => $originalStoredPath,
                'ruta_borrado'    =>
                $movedFile['ruta_borrado'] ?? null,
            ],
            $previousData,
            [
                'url_archivo'  => (string) $evidence->url_archivo,
                'eliminado_en' => $evidence->eliminado_en,
            ]
        );
        return response()->json([
            'ok'             => true,
            'evidencia_id'   => (int) $evidence->id,
            'archivo_movido' => $movedFile !== null,
        ]);
    }

    /* ===== Toggle activo ===== */
    public function toggle(
        Request $request,
        $id
    ) {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $reminder = $this->authorizedReminder(
            $administrator,
            (int) $id
        );
        $previousActive = (int) $reminder->activo;
        $reminder->activo         = (int) $data['activo'];
        $reminder->id_usuario     = (int) $administrator->id;
        $reminder->actualizado_en =
        now()->format('Y-m-d H:i:s');
        $reminder->save();
                $this->registerAudit(
            $request,
            $administrator,
            (int) $reminder->id_cliente,
            'recordatorio',
            (int) $reminder->id,
            'recordatorio_estado_actualizado',
            'exitoso',
            'Se actualizó el estado de un recordatorio.',
            [
                'estado_anterior' => $previousActive,
                'estado_nuevo'    => (int) $reminder->activo,
            ],
            [
                'activo' => $previousActive,
            ],
            [
                'activo' => (int) $reminder->activo,
            ]
        );
        return response()->json(['ok' => true]);
    }
    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user('sanctum');

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Se requiere una sesión administrativa válida.'
            );
        }

        return $administrator;
    }

    private function authorizeClient(
        AdministradorAuth $administrator,
        int $clientId
    ): void {
        if ($clientId <= 0) {
            throw new AuthorizationException(
                'La sucursal solicitada no es válida.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$clientId]
        );
    }

    private function authorizedReminder(
        AdministradorAuth $administrator,
        int $reminderId
    ): Recordatorio {
        $reminder = Recordatorio::query()
            ->where('id', $reminderId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->vigentes()
            ->firstOrFail();

        $this->authorizeClient(
            $administrator,
            (int) $reminder->id_cliente
        );

        return $reminder;
    }

    private function authorizedEvidence(
        AdministradorAuth $administrator,
        int $evidenceId
    ): RecordatorioEvidencia {
        $evidence = RecordatorioEvidencia::query()
            ->where('id', $evidenceId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereNull('eliminado_en')
            ->firstOrFail();

        $this->authorizeClient(
            $administrator,
            (int) $evidence->id_cliente
        );

        return $evidence;
    }
    /* ===== Utilidad: próxima fecha ===== */
    private function calcProximaFecha(string $tipo, string $fechaBase, ?int $intervaloMeses): string
    {
        $dt = Carbon::createFromFormat('Y-m-d', $fechaBase);
        if ($tipo === 'mensual' && $intervaloMeses && $intervaloMeses > 0) {
            $target = $dt->copy()->addMonthsNoOverflow($intervaloMeses);
            return $target->format('Y-m-d');
        }
        return $dt->format('Y-m-d');
    }

    /* ===== Utilidades de archivos ===== */

    private function ensureDir(string $directory): void
    {
        if (! File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    public function evidenciasShow($docId, Request $request)
    {
        $administrator = $this->administrator($request);

        $evidence = $this->authorizedEvidence(
            $administrator,
            (int) $docId
        );

        try {
            $absolutePath = $this->reminderPaths->existingAbsolutePath(
                (string) $evidence->url_archivo,
                (int) $administrator->id_portal,
                (int) $evidence->id_cliente
            );
        } catch (\Throwable $exception) {
            return response()->json([
                'ok'      => false,
                'message' => 'El archivo no existe o su ruta no es válida.',
            ], 404);
        }

        $displayName = $evidence->titulo ?: basename($absolutePath);
        $displayName = Str::of($displayName)
            ->replaceMatches('/[^\w\-. ]+/u', '')
            ->trim()
            ->value();

        if ($displayName === '') {
            $displayName = basename($absolutePath);
        }

        $mime = File::mimeType($absolutePath)
            ?: 'application/octet-stream';
                $this->registerAudit(
            $request,
            $administrator,
            (int) $evidence->id_cliente,
            'recordatorio_evidencia',
            (int) $evidence->id,
            'evidencia_recordatorio_visualizada',
            'exitoso',
            'Se visualizó una evidencia de recordatorio.',
            [
                'id_recordatorio' =>
                    (int) $evidence->id_recordatorio,
                'modo'            => 'visualizacion',
                'storage_path'    =>
                    (string) $evidence->url_archivo,
                'mime_type'       => $mime,
            ]
        );
        return response()->file($absolutePath, [
            'Content-Type'           => $mime,
            'Content-Disposition'    =>
            'inline; filename="' . $displayName . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          =>
            'private, no-store, no-cache, must-revalidate',
            'Pragma'                 => 'no-cache',
        ]);
    }

    public function evidenciasDownload($docId, Request $request)
    {
        $administrator = $this->administrator($request);

        $evidence = $this->authorizedEvidence(
            $administrator,
            (int) $docId
        );

        try {
            $absolutePath = $this->reminderPaths->existingAbsolutePath(
                (string) $evidence->url_archivo,
                (int) $administrator->id_portal,
                (int) $evidence->id_cliente
            );
        } catch (\Throwable $exception) {
            return response()->json([
                'ok'      => false,
                'message' => 'El archivo no existe o su ruta no es válida.',
            ], 404);
        }

        $downloadName = $evidence->titulo ?: basename($absolutePath);
        $downloadName = Str::of($downloadName)
            ->replaceMatches('/[^\w\-. ]+/u', '')
            ->trim()
            ->value();

        if ($downloadName === '') {
            $downloadName = basename($absolutePath);
        }
            $this->registerAudit(
            $request,
            $administrator,
            (int) $evidence->id_cliente,
            'recordatorio_evidencia',
            (int) $evidence->id,
            'evidencia_recordatorio_descargada',
            'exitoso',
            'Se descargó una evidencia de recordatorio.',
            [
                'id_recordatorio' =>
                    (int) $evidence->id_recordatorio,
                'modo'            => 'descarga',
                'storage_path'    =>
                    (string) $evidence->url_archivo,
            ]
        );
        return response()->download(
            $absolutePath,
            $downloadName,
            [
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          =>
                'private, no-store, no-cache, must-revalidate',
                'Pragma'                 => 'no-cache',
            ]
        );
    }
    private function registerAudit(
        Request $request,
        AdministradorAuth $administrator,
        int $clientId,
        string $entityType,
        ?int $entityId,
        string $action,
        string $result,
        string $description,
        ?array $metadata = null,
        ?array $previousData = null,
        ?array $newData = null
    ): void {
        $payload = [
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   => $clientId,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion_interna',
            'entidad_tipo' => $entityType,
            'entidad_id'   => $entityId,
            'accion'       => $action,
            'resultado'    => $result,
            'descripcion'  => $description,
        ];

        if ($metadata !== null) {
            $payload['metadatos'] = $metadata;
        }

        if ($previousData !== null) {
            $payload['datos_anteriores'] = $previousData;
        }

        if ($newData !== null) {
            $payload['datos_nuevos'] = $newData;
        }

        $this->auditoria->registrar(
            $payload,
            $request
        );
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
