<?php
namespace App\Http\Controllers\Api\PreEmpleado;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Auth\AdministradorAuth;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminEmployeeScopeService;
use App\Services\Auth\PermissionService;
use App\Services\Documents\EmployeeDocumentPathService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PreEmpleadoDocumentoController extends Controller
{
    public function __construct(
        private AdminEmployeeScopeService $employeeScope,
        private PermissionService $permissions,
        private EmployeeDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}

    public function documentos(Request $request, int $employeeId)
    {
        $administrator = $this->administrator($request);
        $employee      = $this->preEmpleado($administrator, $employeeId);

        return response()->json([
            'documentos' => DocumentEmpleado::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 999)
                ->orderByDesc('id')
                ->get()
                ->map(fn(DocumentEmpleado $document) => $this->documentoPayload($document))
                ->values(),
        ]);
    }

    public function examenes(Request $request, int $employeeId)
    {
        $administrator = $this->administrator($request);
        $employee      = $this->preEmpleado($administrator, $employeeId);

        return response()->json([
            'documentos' => ExamEmpleado::query()
                ->where('employee_id', $employee->id)
                ->where('status', '!=', 999)
                ->orderByDesc('id')
                ->get()
                ->map(fn(ExamEmpleado $exam) => $this->documentoPayload($exam))
                ->values(),
        ]);
    }

    public function verDocumento(Request $request, int $documentId)
    {
        return $this->servirArchivo(
            $request,
            DocumentEmpleado::class,
            $documentId,
            '_documentEmpleado'
        );
    }

    public function verExamen(Request $request, int $examId)
    {
        return $this->servirArchivo(
            $request,
            ExamEmpleado::class,
            $examId,
            '_examEmpleado'
        );
    }

    public function eliminarDocumento(Request $request, int $documentId)
    {
        return $this->eliminarArchivo(
            $request,
            DocumentEmpleado::class,
            $documentId,
            '_documentEmpleado',
            'documento',
            'pre_empleo.documentos.eliminar'
        );
    }

    public function eliminarExamen(Request $request, int $examId)
    {
        return $this->eliminarArchivo(
            $request,
            ExamEmpleado::class,
            $examId,
            '_examEmpleado',
            'examen',
            'pre_empleo.examenes.eliminar'
        );
    }
    public function cargarDocumento(
        Request $request,
        int $employeeId
    ) {
        return $this->cargarArchivo(
            $request,
            $employeeId,
            DocumentEmpleado::class,
            '_documentEmpleado',
            'documento',
            'pre_empleo.documentos.cargar'
        );
    }

    public function cargarExamen(
        Request $request,
        int $employeeId
    ) {
        return $this->cargarArchivo(
            $request,
            $employeeId,
            ExamEmpleado::class,
            '_examEmpleado',
            'examen',
            'pre_empleo.examenes.cargar'
        );
    }

    private function cargarArchivo(
        Request $request,
        int $employeeId,
        string $modelClass,
        string $category,
        string $entityType,
        string $permission
    ) {
        $administrator = $this->administrator($request);
        $employee      = $this->preEmpleado($administrator, $employeeId);

        if (! $this->permissions->canAdminGlobal(
            (int) $administrator->id,
            (int) $administrator->id_rol,
            $permission
        )) {
            throw new AuthorizationException(
                'No tienes permiso para cargar este archivo.'
            );
        }

        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string', 'max:500'],
            'expiry_date'     => ['nullable', 'date'],
            'expiry_reminder' => ['nullable', 'integer', 'min:0'],
            'status'          => ['nullable', 'integer', 'in:1,2,3'],
            'file'            => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:15360',
            ],
        ]);

        $file = $request->file('file');

        if (! $file instanceof \Illuminate\Http\UploadedFile
            || ! $file->isValid()
        ) {
            return response()->json([
                'message' => 'Archivo inválido.',
            ], 422);
        }
        $originalFileName = $file->getClientOriginalName();
        $fileMimeType     = $file->getClientMimeType();
        $fileSizeBytes    = $file->getSize();

        $extension = strtolower(
            (string) $file->getClientOriginalExtension()
        );

        $fileName = (int) $employee->id
        . '_'
        . Str::random(8)
            . '.'
            . $extension;

        try {
            $uploadRequest = new Request();
            $uploadRequest->files->set('file', $file);
            $uploadRequest->merge([
                'file_name' => $fileName,
                'carpeta'   => $this->documentPaths->uploadFolder(
                    $category,
                    $employee
                ),
            ]);

            $uploadResponse = app(DocumentController::class)->upload(
                $uploadRequest,
                'documents'
            );

            if ($uploadResponse->getStatusCode() !== 200) {
                Log::error('No fue posible cargar archivo de Preempleo.', [
                    'employee_id' => $employee->id,
                    'category'    => $category,
                    'response'    => $uploadResponse->getContent(),
                ]);

                return response()->json([
                    'message' => 'No fue posible almacenar el archivo.',
                ], 500);
            }
        } catch (\Throwable $exception) {
            Log::error('Error al cargar archivo de Preempleo.', [
                'employee_id' => $employee->id,
                'category'    => $category,
                'message'     => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'No fue posible almacenar el archivo.',
            ], 500);
        }

        $now        = Carbon::now('America/Mexico_City');
        $storedPath = $this->documentPaths->storedPath(
            $category,
            $employee,
            $fileName
        );

        $document = $modelClass::create([
            'creacion'        => $now,
            'edicion'         => $now,
            'employee_id'     => (int) $employee->id,
            'id_usuario'      => (int) $administrator->id,
            'name'            => $storedPath,
            'nameDocument'    => $data['name'],
            'description'     => $data['description'] ?? null,
            'expiry_date'     => $data['expiry_date'] ?? null,
            'expiry_reminder' => $data['expiry_reminder'] ?? 0,
            'status'          => $data['status'] ?? 1,
        ]);

        $auditFields = [
            'id', 'employee_id', 'id_usuario', 'name',
            'nameDocument', 'description', 'expiry_date',
            'expiry_reminder', 'status', 'creacion', 'edicion',
        ];

        $actorName = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorName
                ?: ($administrator->email ?? null),
            'modulo'           => 'pre_empleo',
            'entidad_tipo'     => $entityType,
            'entidad_id'       => (int) $document->id,
            'accion'           => 'crear',
            'resultado'        => 'exitoso',
            'datos_anteriores' => null,
            'datos_nuevos'     => $document->only($auditFields),
            'metadatos'        => [
                'employee_id'              => (int) $employee->id,
                'categoria_almacenamiento' => $category,
                'archivo_guardado'         => $storedPath,
                'nombre_original'          => $originalFileName,
                'mime_type'                => $fileMimeType,
                'tamano_bytes'             => $fileSizeBytes,
                'status_requerido'         => 3,
            ],
        ], $request);

        return response()->json([
            'message'  => 'Archivo cargado correctamente.',
            'document' => $this->documentoPayload($document),
        ], 201);
    }

    private function documentoPayload(object $document): array
    {
        return [
            'id'           => (int) $document->id,
            'nameDocument' => (string) ($document->name ?? ''),
            'nameAlterno'  => (string) ($document->nameDocument ?? $document->name ?? ''),
            'upload_date'  => (string) (
                $document->upload_date ?? $document->creacion ?? ''
            ),
            'status'       => (int) ($document->status ?? 0),
        ];
    }

    private function servirArchivo(
        Request $request,
        string $modelClass,
        int $documentId,
        string $category
    ) {
        $administrator = $this->administrator($request);

        $document = $modelClass::query()
            ->where('id', $documentId)
            ->where('status', '!=', 999)
            ->firstOrFail();

        $employee = $this->preEmpleado(
            $administrator,
            (int) $document->employee_id
        );

        $storedValue = trim((string) $document->name);

        if (
            $storedValue === ''
            || $this->documentPaths->isExternalUrl($storedValue)
        ) {
            abort(404, 'Archivo local no disponible.');
        }

        $filePath = $this->documentPaths->absolutePath(
            $category,
            $storedValue
        );

        if (! is_file($filePath)) {
            abort(404, 'Archivo no encontrado.');
        }

        return response()->file($filePath, [
            'Content-Type'        => mime_content_type($filePath)
                ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'
            . basename(str_replace('\\', '/', $storedValue))
            . '"',
        ]);
    }

    private function eliminarArchivo(
        Request $request,
        string $modelClass,
        int $documentId,
        string $category,
        string $entityType,
        string $permission
    ) {
        $administrator = $this->administrator($request);

        $document = $modelClass::query()
            ->where('id', $documentId)
            ->firstOrFail();

        $employee = $this->preEmpleado(
            $administrator,
            (int) $document->employee_id
        );

        if (! $this->permissions->canAdminGlobal(
            (int) $administrator->id,
            (int) $administrator->id_rol,
            $permission
        )) {
            throw new AuthorizationException(
                'No tienes permiso para eliminar este archivo.'
            );
        }

        if ((int) $document->status === 999) {
            return response()->json([
                'status'  => true,
                'id'      => $documentId,
                'message' => 'El archivo ya fue eliminado.',
            ]);
        }

        $auditFields = [
            'id', 'employee_id', 'name', 'nameDocument', 'description',
            'status', 'creacion', 'edicion',
        ];

        $previous    = $document->only($auditFields);
        $storedValue = trim((string) $document->name);

        DB::transaction(function () use ($document) {
            $document->update(['status' => 999]);
        });

        $trashPath  = null;
        $trashError = null;

        if ($storedValue !== '') {
            try {
                $trashPath = $this->documentPaths->moveToTrash(
                    $category,
                    $employee,
                    $documentId,
                    $storedValue,
                    'eliminados'
                );
            } catch (\Throwable $exception) {
                $trashError = $exception->getMessage();

                Log::error('No fue posible mover archivo de Preempleo a borrados.', [
                    'document_id' => $documentId,
                    'message'     => $trashError,
                ]);
            }
        }

        $actorName = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));
        $trasladoEsperado = $storedValue !== '';

        $trasladoCompletado = ! $trasladoEsperado
            || $trashPath !== null;

        $resultadoAuditoria = $trasladoCompletado
            ? 'exitoso'
            : 'exitoso_con_advertencia';
        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,

            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorName ?: ($administrator->email ?? null),

            'modulo'           => 'pre_empleo',
            'entidad_tipo'     => $entityType,
            'entidad_id'       => $documentId,
            'accion'           => 'eliminar',
            'resultado'        => $resultadoAuditoria,

            'datos_anteriores' => $previous,
            'datos_nuevos'     => $document->only($auditFields),

            'metadatos'        => [
                'employee_id'         => (int) $employee->id,
                'categoria'           => $category,
                'archivo_anterior'    => $storedValue,
                'archivo_respaldo'    => $trashPath,
                'traslado_esperado'   => $trasladoEsperado,
                'traslado_completado' => $trasladoCompletado,
                'error_traslado'      => $trashError,
                'status_requerido'    => 3,
                'borrado_logico'      => true,
            ],
        ], $request);

        return response()->json([
            'status'     => true,
            'id'         => $documentId,
            'trash_path' => $trashPath,
        ]);
    }

    public function eliminarCandidato(
        Request $request,
        int $employeeId
    ) {
        $administrator = $this->administrator($request);
        $employee      = $this->preEmpleado($administrator, $employeeId);

        if (! $this->permissions->canAdminGlobal(
            (int) $administrator->id,
            (int) $administrator->id_rol,
            'pre_empleo.candidatos.eliminar'
        )) {
            throw new AuthorizationException(
                'No tienes permiso para eliminar este candidato.'
            );
        }

        $documentos = DocumentEmpleado::query()
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 999)
            ->get();

        $examenes = ExamEmpleado::query()
            ->where('employee_id', $employee->id)
            ->where('status', '!=', 999)
            ->get();

        $erroresTraslado      = [];
        $documentosEliminados = 0;
        $examenesEliminados   = 0;

        foreach ($documentos as $documento) {
            $resultado = $this->eliminarArchivoDeCandidato(
                $request,
                $administrator,
                $employee,
                $documento,
                '_documentEmpleado',
                'documento'
            );

            $documentosEliminados++;

            if ($resultado['error_traslado'] !== null) {
                $erroresTraslado[] = $resultado['error_traslado'];
            }
        }

        foreach ($examenes as $examen) {
            $resultado = $this->eliminarArchivoDeCandidato(
                $request,
                $administrator,
                $employee,
                $examen,
                '_examEmpleado',
                'examen'
            );

            $examenesEliminados++;

            if ($resultado['error_traslado'] !== null) {
                $erroresTraslado[] = $resultado['error_traslado'];
            }
        }

        $candidateFields = [
            'id', 'id_portal', 'id_cliente', 'nombre', 'paterno',
            'materno', 'correo', 'status', 'eliminado',
        ];

        $previous = $employee->only($candidateFields);

        DB::transaction(function () use ($employee) {
            $employee->update([
                'eliminado' => 1,
            ]);
        });

        $actorName = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $trasladosCompletados = count($erroresTraslado) === 0;

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorName ?: ($administrator->email ?? null),
            'modulo'           => 'pre_empleo',
            'entidad_tipo'     => 'candidato',
            'entidad_id'       => (int) $employee->id,
            'accion'           => 'eliminar',
            'resultado'        => $trasladosCompletados
                ? 'exitoso'
                : 'exitoso_con_advertencia',
            'datos_anteriores' => $previous,
            'datos_nuevos'     => $employee->only($candidateFields),
            'metadatos'        => [
                'status_requerido'      => 3,
                'borrado_logico'        => true,
                'documentos_eliminados' => $documentosEliminados,
                'examenes_eliminados'   => $examenesEliminados,
                'traslados_completados' => $trasladosCompletados,
                'errores_traslado'      => $erroresTraslado,
            ],
        ], $request);

        return response()->json([
            'status'                => true,
            'employee_id'           => (int) $employee->id,
            'documentos_eliminados' => $documentosEliminados,
            'examenes_eliminados'   => $examenesEliminados,
        ]);
    }
    private function eliminarArchivoDeCandidato(
        Request $request,
        AdministradorAuth $administrator,
        Empleado $employee,
        object $document,
        string $category,
        string $entityType
    ): array {
        $documentId = (int) $document->id;

        $auditFields = [
            'id', 'employee_id', 'name', 'nameDocument',
            'description', 'status', 'creacion', 'edicion',
        ];

        $previous    = $document->only($auditFields);
        $storedValue = trim((string) $document->name);

        DB::transaction(function () use ($document) {
            $document->update(['status' => 999]);
        });

        $trashPath  = null;
        $trashError = null;

        if ($storedValue !== '') {
            try {
                $trashPath = $this->documentPaths->moveToTrash(
                    $category,
                    $employee,
                    $documentId,
                    $storedValue,
                    'eliminados'
                );
            } catch (\Throwable $exception) {
                $trashError = $exception->getMessage();

                Log::error(
                    'No fue posible mover archivo de candidato a borrados.',
                    [
                        'document_id' => $documentId,
                        'message'     => $trashError,
                    ]
                );
            }
        }

        $trasladoEsperado   = $storedValue !== '';
        $trasladoCompletado = ! $trasladoEsperado
            || $trashPath !== null;

        $actorName = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorName ?: ($administrator->email ?? null),
            'modulo'           => 'pre_empleo',
            'entidad_tipo'     => $entityType,
            'entidad_id'       => $documentId,
            'accion'           => 'eliminar',
            'resultado'        => $trasladoCompletado
                ? 'exitoso'
                : 'exitoso_con_advertencia',
            'datos_anteriores' => $previous,
            'datos_nuevos'     => $document->only($auditFields),
            'metadatos'        => [
                'employee_id'         => (int) $employee->id,
                'categoria'           => $category,
                'archivo_anterior'    => $storedValue,
                'archivo_respaldo'    => $trashPath,
                'traslado_esperado'   => $trasladoEsperado,
                'traslado_completado' => $trasladoCompletado,
                'error_traslado'      => $trashError,
                'status_requerido'    => 3,
                'borrado_logico'      => true,
                'origen'              => 'eliminar_candidato',
            ],
        ], $request);

        return [
            'trash_path'     => $trashPath,
            'error_traslado' => $trashError,
        ];
    }
    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException('Token administrativo no válido.');
        }

        return $administrator;
    }

    private function preEmpleado(
        AdministradorAuth $administrator,
        int $employeeId
    ): Empleado {
        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            $employeeId
        );

        if (
            (int) $employee->status !== 3
            || (int) ($employee->eliminado ?? 0) === 1
        ) {
            abort(404, 'Candidato de Preempleo no encontrado.');
        }

        return $employee;
    }

}
