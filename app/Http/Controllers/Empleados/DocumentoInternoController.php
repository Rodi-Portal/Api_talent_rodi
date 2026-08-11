<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\ClienteInformacionInterna;
use App\Models\DocumentoInterno;
use App\Models\DocumentoInternoEmpleado;
use App\Models\Empleado;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentoInternoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}
    public function store(
        Request $request,
        ClienteInformacionInterna $informacion
    ) {
        $data = $request->validate([
            'file'              => ['required', 'file', 'max:10240'],
            'fecha_vencimiento' => ['nullable', 'date'],
            'dias_antes'        => ['nullable', 'integer', 'min:0'],
            'share_scope'       => ['nullable', 'integer', 'in:0,1,2,3'],
            'employee_ids'      => ['nullable', 'array'],
            'employee_ids.*'    => ['integer', 'distinct', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $this->authorizeInformation(
            $administrator,
            $informacion
        );

        $shareScope = (int) ($data['share_scope'] ?? 0);

        $employeeIds = $this->validateEmployeeIds(
            $informacion,
            $shareScope,
            $data['employee_ids'] ?? []
        );

        $basePath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        if ($basePath === '') {
            return response()->json([
                'status'  => false,
                'code'    => 'STORAGE_PATH_NOT_CONFIGURED',
                'message' => 'La ruta de almacenamiento no está configurada.',
            ], 500);
        }

        $file = $request->file('file');

        $originalName = $file->getClientOriginalName();
        $mimeType     = $file->getClientMimeType();
        $sizeBytes    = $file->getSize();

        $folderRel = "_internos/{$informacion->id_portal}/"
        . $informacion->id_cliente;

        $targetDir = $basePath
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $folderRel);

        if (
            ! is_dir($targetDir) &&
            ! mkdir($targetDir, 0775, true) &&
            ! is_dir($targetDir)
        ) {
            return response()->json([
                'status'  => false,
                'code'    => 'STORAGE_DIRECTORY_ERROR',
                'message' => 'No se pudo crear el directorio de almacenamiento.',
            ], 500);
        }

        $extension = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            (string) $file->getClientOriginalExtension()
        );

        $storedFilename = bin2hex(random_bytes(16));

        if ($extension !== '') {
            $storedFilename .= '.' . strtolower($extension);
        }

        $file->move($targetDir, $storedFilename);

        $storagePath = $folderRel . '/' . $storedFilename;
        $fullPath    = $targetDir . DIRECTORY_SEPARATOR . $storedFilename;
        $now         = Carbon::now('America/Mexico_City');

        try {
            $doc = DB::connection('portal_main')->transaction(
                function () use (
                    $informacion,
                    $administrator,
                    $originalName,
                    $mimeType,
                    $sizeBytes,
                    $storagePath,
                    $data,
                    $shareScope,
                    $employeeIds,
                    $now
                ) {
                    $documento = DocumentoInterno::create([
                        'id_informacion_interna' => (int) $informacion->id,
                        'id_usuario'             => (int) $administrator->id,
                        'nombre'                 => $originalName,
                        'typo'                   => $mimeType,
                        'size'                   => $sizeBytes,
                        'storage_path'           => $storagePath,
                        'fecha_vencimiento'      =>
                        $data['fecha_vencimiento'] ?? null,
                        'dias_antes'             =>
                        (int) ($data['dias_antes'] ?? 0),
                        'eliminado'              => 0,
                        'share_scope'            => $shareScope,
                        'creacion'               => $now,
                        'edicion'                => $now,
                    ]);

                    foreach ($employeeIds as $employeeId) {
                        DocumentoInternoEmpleado::create([
                            'id_documento_interno' =>
                            (int) $documento->id,
                            'id_empleado'          =>
                            (int) $employeeId,
                            'id_usuario_asigno'    =>
                            (int) $administrator->id,
                            'eliminado'            => 0,
                            'creacion'             => $now,
                            'edicion'              => $now,
                        ]);
                    }

                    return $documento;
                }
            );
        } catch (\Throwable $exception) {
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }

            throw $exception;
        }

        $doc->load([
            'asignacionesEmpleados.empleado' => function ($query) {
                $query->select([
                    'id',
                    'id_portal',
                    'id_cliente',
                    'nombre',
                    'paterno',
                    'materno',
                ]);
            },
        ]);

        return response()->json($doc, 201);
    }

    public function download(
        Request $request,
        DocumentoInterno $documento
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeDocument(
            $administrator,
            $documento
        );

        $basePath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        if ($basePath === '') {
            return response()->json([
                'status'  => false,
                'code'    => 'STORAGE_PATH_NOT_CONFIGURED',
                'message' => 'La ruta de almacenamiento no está configurada.',
            ], 500);
        }

        $fullPath = $basePath
        . DIRECTORY_SEPARATOR
        . $documento->storage_path;

        if (! file_exists($fullPath)) {
            return response()->json([
                'message' => 'Archivo no encontrado.',
            ], 404);
        }

        return response()->download(
            $fullPath,
            $documento->nombre
        );
    }
    public function updateSharing(
        Request $request,
        DocumentoInterno $documento
    ) {
        $data = $request->validate([
            'share_scope'    => ['required', 'integer', 'in:0,1,2,3'],
            'employee_ids'   => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'distinct', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $informacion = $this->authorizeDocument(
            $administrator,
            $documento
        );

        $shareScope = (int) $data['share_scope'];

        $employeeIds = $this->validateEmployeeIds(
            $informacion,
            $shareScope,
            $data['employee_ids'] ?? []
        );

        $now = Carbon::now('America/Mexico_City');

        DB::connection('portal_main')->transaction(
            function () use (
                $documento,
                $administrator,
                $shareScope,
                $employeeIds,
                $now
            ) {
                $documento->share_scope = $shareScope;
                $documento->edicion     = $now;
                $documento->save();

                // Desactivar empleados que ya no están seleccionados.
                $assignmentsToDisable = DocumentoInternoEmpleado::query()
                    ->withoutGlobalScope('no_eliminado')
                    ->where(
                        'id_documento_interno',
                        (int) $documento->id
                    );

                if ($employeeIds !== []) {
                    $assignmentsToDisable->whereNotIn(
                        'id_empleado',
                        $employeeIds
                    );
                }

                $assignmentsToDisable->update([
                    'eliminado' => 1,
                    'edicion'   => $now,
                ]);

                // Crear o reactivar las asignaciones seleccionadas.
                foreach ($employeeIds as $employeeId) {
                    $assignment = DocumentoInternoEmpleado::query()
                        ->withoutGlobalScope('no_eliminado')
                        ->firstOrNew([
                            'id_documento_interno' => (int) $documento->id,
                            'id_empleado'          => (int) $employeeId,
                        ]);

                    if (! $assignment->exists) {
                        $assignment->creacion = $now;
                    }

                    $assignment->id_usuario_asigno =
                    (int) $administrator->id;

                    $assignment->eliminado = 0;
                    $assignment->edicion   = $now;
                    $assignment->save();
                }
            }
        );

        $documento->load([
            'asignacionesEmpleados.empleado' => function ($query) {
                $query->select([
                    'id',
                    'id_portal',
                    'id_cliente',
                    'nombre',
                    'paterno',
                    'materno',
                ]);
            },
        ]);

        return response()->json([
            'message'   => 'Compartición actualizada correctamente.',
            'documento' => $documento,
        ]);
    }

    public function destroy(
        Request $request,
        DocumentoInterno $documento
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeDocument(
            $administrator,
            $documento
        );

        $now = Carbon::now('America/Mexico_City');

        DB::connection('portal_main')->transaction(
            function () use ($documento, $now) {
                $documento->eliminado = 1;
                $documento->edicion   = $now;
                $documento->save();

                DocumentoInternoEmpleado::query()
                    ->withoutGlobalScope('no_eliminado')
                    ->where(
                        'id_documento_interno',
                        (int) $documento->id
                    )
                    ->update([
                        'eliminado' => 1,
                        'edicion'   => $now,
                    ]);
            }
        );

        return response()->json(['ok' => true]);
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

    private function authorizeInformation(
        AdministradorAuth $administrator,
        ClienteInformacionInterna $informacion
    ): void {
        if (
            (int) $informacion->id_portal !==
            (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'La información interna no pertenece al portal autenticado.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $informacion->id_cliente]
        );
    }

    private function authorizeDocument(
        AdministradorAuth $administrator,
        DocumentoInterno $documento
    ): ClienteInformacionInterna {
        $documento->loadMissing('informacionInterna');

        $informacion = $documento->informacionInterna;

        if (! $informacion) {
            throw new AuthorizationException(
                'El documento no tiene un directorio interno válido.'
            );
        }

        $this->authorizeInformation($administrator, $informacion);

        return $informacion;
    }

    private function validateEmployeeIds(
        ClienteInformacionInterna $informacion,
        int $shareScope,
        array $employeeIds
    ): array {
        $employeeIds = collect($employeeIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        // Sin compartir con colaborador no se conservan asignaciones.
        if (! in_array($shareScope, [1, 3], true)) {
            return [];
        }

        if ($employeeIds === []) {
            throw ValidationException::withMessages([
                'employee_ids' => [
                    'Selecciona al menos un empleado para compartir el documento.',
                ],
            ]);
        }

        $validEmployeeIds = Empleado::query()
            ->whereIn('id', $employeeIds)
            ->where('id_portal', (int) $informacion->id_portal)
            ->where('id_cliente', (int) $informacion->id_cliente)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $invalidEmployeeIds = array_values(
            array_diff($employeeIds, $validEmployeeIds)
        );

        if ($invalidEmployeeIds !== []) {
            throw ValidationException::withMessages([
                'employee_ids' => [
                    'Uno o más empleados no pertenecen al portal y sucursal del directorio.',
                ],
            ]);
        }

        return $validEmployeeIds;
    }
}
