<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Auth\AdministradorAuth;
use App\Models\Candidato;
use App\Models\CandidatoPruebas;
use App\Models\CursoEmpleado;
use App\Models\CursosOption;
use App\Models\DocumentEmpleado;
use App\Models\DocumentOption;
use App\Models\Doping;
use App\Models\ExamEmpleado;
use App\Models\ExamOption;
use App\Models\Medico;
use App\Models\Psicometrico;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminEmployeeScopeService;
use App\Services\Auth\PermissionService;
use App\Services\Documents\EmployeeDocumentPathService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class DocumentOptionController extends Controller
{
    public function __construct(
        private AdminEmployeeScopeService $employeeScope,
        private PermissionService $permissions,
        private EmployeeDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}

    public function getExamsByEmployeeId(
        Request $request,
        $employeeId
    ) {
        // Validar el ID del empleado
        if (
            ! is_numeric($employeeId) ||
            (int) $employeeId < 1
        ) {
            return response()->json([
                'error' => 'ID de empleado no válido.',
            ], 422);
        }

        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            (int) $employeeId
        );

        $employeeId = (int) $employee->id;

        // Buscar documentos del empleado junto con las opciones
        $exam = ExamEmpleado::with('examOption')
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 999)
            ->get();

        // Verificar si se encontraron documentos
        if ($exam->isEmpty()) {
            return response()->json(['message' => 'No se encontraron documentos para el empleado.'], 404);
        }

        // Obtener el id_candidato de los exámenes
        $idCandidatos = $exam->pluck('id_candidato')->unique();

        // Consultar CandidatoPruebas y Candidato para obtener los campos deseados
        $candidatosPruebas = CandidatoPruebas::whereIn('id_candidato', $idCandidatos)->get();
        $candidatos        = Candidato::with('medico', 'doping')->whereIn('id', $idCandidatos)->get(); // Cargar la relación del doping
        $psicometrico      = Candidato::with('psicometrico')->whereIn('id', $idCandidatos)->get();
        // Log::info('Psicométrico obtenido:', ['psicometrico' => $psicometrico]);

        // Mapear los documentos para incluir los nuevos campos
        $examConOpciones = $exam->map(function ($documento) use ($candidatosPruebas, $candidatos) {
            $candidatoPrueba = $candidatosPruebas->firstWhere('id_candidato', $documento->id_candidato);
            $candidato       = $candidatos->firstWhere('id', $documento->id_candidato);
            $medico          = $candidato->medico ?? null;
            $doping          = $candidato->doping ?? null;       // Obtener el doping
            $psicometrico    = $candidato->psicometrico ?? null; // Obtener el psicométrico

            switch ($candidato->status_bgc ?? null) {
                case 1:
                case 4:
                    $icono_resultado = 'icono_resultado_aprobado';
                    break;
                case 2:
                    $icono_resultado = 'icono_resultado_reprobado';
                    break;
                case 3:
                    $icono_resultado = 'icono_resultado_revision';
                    break;
                default:
                    $icono_resultado = 'icono_resultado_espera';
                    break;
            }

            return [
                'id'                       => $documento->id,
                'nameDocument'             => $documento->name,
                'optionName'               => $documento->examOption ? $documento->examOption->name : null,
                //'optionType'      => $documento->examOption ? $documento->examOption->type : null,
                'description'              => $documento->description,
                'upload_date'              => \Carbon\Carbon::parse($documento->upload_date)->format('Y-m-d'),
                'expiry_date'              => $documento->expiry_date,
                'nameAlterno'              => $documento->nameDocument,
                'statusexm'                => $documento->status,
                'expiry_reminder'          => $documento->expiry_reminder,
                'share_scope'              => (int) (
                    $documento->share_scope ?? 0
                ),
                'collaborator_can_replace' => (bool) (
                    $documento->collaborator_can_replace ?? false
                ),
                'id_candidato'             => $documento->id_candidato,
                'socioeconomico'           => $candidatoPrueba->socioeconomico ?? null,
                'medico'                   => $candidatoPrueba->medico ?? null,
                'tipo_antidoping'          => $candidatoPrueba->tipo_antidoping ?? null,
                'antidoping'               => $candidatoPrueba->antidoping ?? null,
                'psicometrico'             => $candidatoPrueba->psicometrico ?? null,
                'medicoDetalle'            => [
                    'id'                    => $medico->id ?? null,
                    'imagen'                => $medico->imagen_historia_clinica ?? null,
                    'conclusion'            => $medico->conclusion ?? null,
                    'descripcion'           => $medico->descripcion ?? null,
                    'archivo_examen_medico' => $medico->archivo_examen_medico ?? null,
                ],
                'psicometricoDet'          => [
                    'id'                   => $psicometrico->id ?? null,
                    'archivo_psicometrico' => $psicometrico->archivo ?? null,
                ],
                'doping'                   => [
                    'id'               => $doping->id ?? null,
                    'doping_hecho'     => $candidatoPrueba->status_doping ?? null,
                    'fecha_resultado'  => $doping->fecha_resultado ?? null,
                    'resultado_doping' => $doping->resultado ?? null,
                    'statusDoping'     => $doping->status ?? null,
                ],
                'liberado'                 => $candidato->liberado ?? null,
                'status_bgc'               => $candidato->status_bgc ?? null,
                'cancelado'                => $candidato->cancelado ?? null,
                'icono_resultado'          => $icono_resultado,
            ];
        });

        // Devolver los documentos
        return response()->json(['documentos' => $examConOpciones], 200);
    }
    public function guardarOpcion(Request $request)
    {
        $id_portal = $request->input('id_portal');
        $tabla     = $request->input('tabla');
        $opciones  = $request->input('opciones', []); // array de opciones con id y name

        Log::info('Guardando opciones', ['tabla' => $tabla, 'id_portal' => $id_portal, 'opciones' => $opciones]);

        // Determinar el modelo a utilizar según la tabla
        $model = match ($tabla) {
            '_documentEmpleado' => DocumentOption::class,
            '_examEmpleado'     => ExamOption::class,
            '_cursos'           => CursosOption::class,
            default             => null,
        };

        if (! $model) {
            return response()->json(['error' => 'Tabla no válida'], 400);
        }

        // Validar que opciones sea array
        if (! is_array($opciones)) {
            return response()->json(['error' => 'Opciones inválidas'], 400);
        }

        foreach ($opciones as $opcion) {
            // Validar estructura mínima
            if (! isset($opcion['name'])) {
                continue; // O puedes devolver error si prefieres
            }

            if (isset($opcion['id'])) {
                // Actualizar opción existente
                $registro = $model::where('id', $opcion['id'])
                    ->where(function ($q) use ($id_portal) {
                        $q->where('id_portal', $id_portal)->orWhereNull('id_portal');
                    })->first();

                if ($registro) {
                    $registro->name = $opcion['name'];
                    $registro->save();
                }
            } else {
                // Crear nueva opción
                $model::create([
                    'name'      => $opcion['name'],
                    'id_portal' => $id_portal,
                    // Otros campos si los hay...
                ]);
            }
        }

        return response()->json(['message' => 'Opciones guardadas correctamente']);
    }

    public function eliminarOpcion(Request $request)
    {
        $id    = $request->input('id');
        $tabla = $request->input('tabla');

        $model = match ($tabla) {
            '_documentEmpleado' => DocumentOption::class,
            '_examEmpleado'     => ExamOption::class,
            '_cursos'           => CursosOption::class,
            default             => null,
        };

        if (! $model) {
            return response()->json(['error' => 'Tabla no válida'], 400);
        }

        $opcion = $model::find($id);

        if (! $opcion) {
            return response()->json(['error' => 'Opción no encontrada'], 404);
        }

        $opcion->delete();

        return response()->json(['message' => 'Opción eliminada correctamente']);
    }

    public function index(Request $request)
    {
        // Verificar si se recibió id_portal
        $id_portal = $request->input('id_portal');
        $tabla     = $request->input('tabla');
        Log::info('📥 Tabla recibida:', ['tabla' => $tabla]);

        // Determinar el modelo a utilizar
        $model = match ($tabla) {
            '_documentEmpleado' => DocumentOption::class,
            '_examEmpleado'     => ExamOption::class,
            '_cursos'           => CursosOption::class,
            default             => null,
        };

        if (! $model) {
            return response()->json(['error' => 'Tabla no válida'], 400);
        }

        // Construir la consulta
        $query = $model::query();

        if ($id_portal) {
            $query->where(function ($q) use ($id_portal) {
                $q->where('id_portal', $id_portal)
                    ->orWhereNull('id_portal');
            });
        } else {
            $query->whereNull('id_portal');
        }

        // Ejecutar la consulta para obtener los resultados
        $documentOptions = $query->ordered()->get();

        // Filtrar por nombre si se proporciona
        if ($request->has('name')) {
            $name     = $request->input('name');
            $filtered = $documentOptions->filter(function ($option) use ($name) {
                return stripos($option->name, $name) !== false; // Comparación no sensible a mayúsculas
            });

            return $filtered->isNotEmpty()
                ? response()->json($filtered->pluck('id'))
                : response()->json([], 404);
        }

        // Devolver todos los resultados si no se busca por nombre
        return response()->json($documentOptions);
    }

    // verificar  si existe  la opcion
    public function buscar_insertar_opcion(Request $request)
    {
        $id_portal = $request->input('id_portal');
        $name      = $request->input('name');
        $tabla     = $request->input('tabla');

        // Validar parámetros requeridos
        if (! $name || ! $tabla) {
            return response()->json(['error' => 'Faltan parámetros necesarios.'], 400);
        }

        // Mapeo tabla → modelo
        $modelMap = [
            'documentos' => \App\Models\DocumentOption::class,
            'examenes'   => \App\Models\ExamOption::class,
            'cursos'     => \App\Models\CursosOption::class,
        ];

        $modelClass = $modelMap[$tabla] ?? null;

        if (! $modelClass) {
            return response()->json(['error' => 'Tabla no válida'], 400);
        }

        // Buscar opción existente
        $documentOption = $modelClass::where(function ($query) use ($id_portal) {
            $query->where('id_portal', $id_portal)
                ->orWhereNull('id_portal');
        })
            ->where('name', $name)
            ->first();

        if ($documentOption) {
            return response()->json(['id_opciones' => $documentOption->id], 200);
        }

        // Insertar nueva opción si no existe
        try {
            $newOption = $modelClass::create([
                'name'      => $name,
                'id_portal' => $id_portal,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json(['id_opciones' => $newOption->id], 201);
    }
    public function store(Request $request)
    {
        $traceId = (string) Str::ulid();
        $t0      = microtime(true);
        Log::withContext(['traceId' => $traceId, 'endpoint' => 'document.store']);

        try {
            $now = Carbon::now('America/Mexico_City');

                                                                                      // === Detectar UPDATE por _method=PUT por método/ID de ruta ===
            $routeId  = $request->route('id') ?? $request->route('document') ?? null; // ajusta al nombre de tu parámetro
            $isPut    = $request->isMethod('PUT') || strtoupper($request->input('_method', '')) === 'PUT';
            $isUpdate = $isPut || ! empty($routeId);
            $docId    = $isUpdate ? ($routeId ?? $request->integer('document_id')) : null;

            Log::info('⌛ Inicio STORE', [
                'ip'           => $request->ip(),
                'user_id'      => $request->id_usuario ?? null,
                'method'       => $request->method(),
                'uri'          => $request->path(),
                'is_update'    => $isUpdate,
                'doc_id'       => $docId,
                'content_type' => $request->header('Content-Type'),
                'files_count'  => count($request->files->all()),

            ]);

            if ($request->has('file') && $request->input('file') === 'null') {
                $request->request->remove('file');
            }

            // === Validación (document_id requerido solo en update si no viene routeId) ===
            $rules = [
                'employee_id'              => 'required|integer',
                'name'                     => 'required|string|max:255',
                'description'              => 'nullable|string|max:500',
                'expiry_date'              => 'nullable|date',
                'expiry_reminder'          => 'nullable|integer',
                'file'                     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:15360',
                'status'                   => [
                    'required',
                    'integer',
                    'in:1,2,3',
                ],
                'document_context'         => [
                    'nullable',
                    'string',
                    'in:expediente,salida',
                ],
                'share_scope'              => [
                    'nullable',
                    'integer',
                    'in:0,1,2,3',
                ],
                'collaborator_can_replace' => [
                    'nullable',
                    'boolean',
                ],
                'carpeta'                  => 'nullable|string|max:255',
            ];
            if ($isUpdate && empty($routeId)) {
                $rules['document_id'] = 'required|integer|exists:document_empleados,id';
            }
            $validator = Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return response()->json(['traceId' => $traceId, 'errors' => $validator->errors()], 422);
            }
            $administrator = $request->user();

            if (! $administrator instanceof AdministradorAuth) {
                return response()->json([
                    'status'  => false,
                    'code'    => 'ADMIN_TOKEN_INVALID',
                    'message' => 'Token administrativo no válido.',
                ], 403);
            }

            $employee = $this->employeeScope->authorizeEmployee(
                $administrator,
                (int) $request->input('employee_id')
            );

            $idPortal   = (int) $administrator->id_portal;
            $idUsuario  = (int) $administrator->id;
            $shareScope = (int) $request->input(
                'share_scope',
                0
            );

            $expiryReminder = (int) $request->input(
                'expiry_reminder',
                0
            );

            $hasExpiryDate = filled(
                $request->input('expiry_date')
            );

            $collaboratorCanReplace =
            in_array($shareScope, [1, 3], true)
            && $hasExpiryDate
            && $expiryReminder > 0
            && $request->boolean('collaborator_can_replace');
            $archivoRecibido =
            $request->hasFile('file')
            && $request->file('file')->isValid();

            $archivoOriginal = $archivoRecibido
                ? $request->file('file')->getClientOriginalName()
                : null;

            $archivoMime = $archivoRecibido
                ? $request->file('file')->getClientMimeType()
                : null;

            $archivoTamano = $archivoRecibido
                ? $request->file('file')->getSize()
                : null;
            // === Resolver catálogo como ya lo tenías ===
            $documentOption = \App\Models\DocumentOption::where(
                function ($q) use ($idPortal) {
                    $q->where('id_portal', $idPortal)
                        ->orWhereNull('id_portal');
                }
            )
                ->where('name', (string) $request->input('name'))
                ->first();

            $idOpcion     = $documentOption?->id;
            $nameDocument = $idOpcion ? null : (string) $request->input('name');

            Log::info('🔎 Opción de documento', [
                'found'        => (bool) $documentOption,
                'id_opcion'    => $idOpcion,
                'nameDocument' => $nameDocument,
            ]);

            // === Si es UPDATE, buscar el existente y validar pertenencia al empleado ===
            $existing = null;
            if ($isUpdate) {
                $existing = \App\Models\DocumentEmpleado::find($docId);
                if (! $existing) {
                    return response()->json(['traceId' => $traceId, 'error' => 'Documento no encontrado.'], 404);
                }
                if ((int) $existing->employee_id !== (int) $employee->id) {
                    return response()->json(['traceId' => $traceId, 'error' => 'El documento no pertenece al empleado indicado.'], 409);
                }
            }

            // === Determinar nombre físico y valor para BD ===
            $newFileName   = null;
            $newStoredPath = null;

            if ($isUpdate) {
                $existingStoredPath = trim(
                    (string) ($existing->name ?? '')
                );

                $docAnterior = trim(
                    (string) $request->input('doc_anterior', '')
                );

                $sourceName = $docAnterior !== ''
                    ? $docAnterior
                    : $existingStoredPath;

                $newFileName = basename(
                    str_replace('\\', '/', $sourceName)
                );

                /*
                * Si no llega un archivo nuevo, se conserva exactamente
                * el valor anterior, incluyendo una posible ruta nueva.
                */
                $newStoredPath = $existingStoredPath;
            }

            if ($request->hasFile('file')) {
                $file = $request->file('file');

                Log::info('📦 Archivo detectado', [
                    'client_name' => $file->getClientOriginalName(),
                    'ext'         => $file->getClientOriginalExtension(),
                    'size_bytes'  => $file->getSize(),
                    'mime_client' => $file->getClientMimeType(),
                    'mime_detect' => $file->getMimeType(),
                    'is_valid'    => $file->isValid(),
                ]);

                if (! $file->isValid()) {
                    return response()->json([
                        'traceId' => $traceId,
                        'error'   => 'Archivo inválido',
                    ], 400);
                }

                if ($newFileName === null || $newFileName === '') {
                    $fileExtension = strtolower(
                        (string) $file->getClientOriginalExtension()
                    );

                    $newFileName = "{$employee->id}_"
                    . Str::random(8)
                        . ".{$fileExtension}";
                }

                $uploadFolder = $this->documentPaths->uploadFolder(
                    '_documentEmpleado',
                    $employee
                );

                try {
                    $uploadRequest = new Request();
                    $uploadRequest->files->set('file', $file);
                    $uploadRequest->merge([
                        'file_name' => $newFileName,
                        'carpeta'   => $uploadFolder,
                    ]);

                    $uploadResponse = app(
                        DocumentController::class
                    )->upload(
                        $uploadRequest,
                        'documents'
                    );

                    $status = $uploadResponse->getStatusCode();
                    $resp   = json_decode(
                        $uploadResponse->getContent(),
                        true
                    );

                    Log::info('↩️ Respuesta de upload()', [
                        'status' => $status,
                        'body'   => $resp,
                    ]);

                    if ($status !== 200) {
                        return response()->json([
                            'traceId' => $traceId,
                            'error'   => 'Error al subir el documento',
                            'detail'  => $resp,
                        ], 500);
                    }

                    $newStoredPath = $this->documentPaths->storedPath(
                        '_documentEmpleado',
                        $employee,
                        $newFileName
                    );
                } catch (\Throwable $e) {
                    Log::error('💥 Excepción subiendo archivo', [
                        'msg'  => $e->getMessage(),
                        'line' => $e->getLine(),
                    ]);

                    return response()->json([
                        'traceId' => $traceId,
                        'error'   => 'Excepción al subir el archivo',
                        'detail'  => $e->getMessage(),
                    ], 500);
                }
            } elseif ($newStoredPath === null || $newStoredPath === '') {
                /*
                 Se conserva el comportamiento existente para registros
                 creados sin archivo físico.
                */
                $newStoredPath = (int) $employee->id
                . '_sin_documento_'
                . Str::random(6);
            }

            // === Persistencia: UPDATE si había ID, CREATE si no
            if ($isUpdate) {
                $existing->fill([
                    'edicion'                  => $now,
                    'employee_id'              => (int) $employee->id,
                    'id_usuario'               => $idUsuario,
                    'name'                     => $newStoredPath,
                    'nameDocument'             => $nameDocument,
                    'id_opcion'                => $idOpcion,
                    'description'              => $request->input('description'),
                    'expiry_date'              => $request->input('expiry_date'),
                    'expiry_reminder'          => $request->input('expiry_reminder'),
                    'status'                   => (int) $request->input('status', 1),
                    'document_context'         => (string) (
                        $existing->document_context ?? 'expediente'
                    ),

                    'share_scope'              => $shareScope,
                    'collaborator_can_replace' => $collaboratorCanReplace,
                ])->save();

                $ms = (int) ((microtime(true) - $t0) * 1000);
                Log::info('✅ Fin UPDATE STORE', ['dur_ms' => $ms, 'id' => $existing->id]);

                return response()->json([
                    'traceId'  => $traceId,
                    'message'  => 'Documento actualizado correctamente.',
                    'document' => $existing,
                    'dur_ms'   => $ms,
                ], 200);
            }

            // CREATE
            $documentEmpleado = \App\Models\DocumentEmpleado::create([
                'creacion'                 => $now,
                'edicion'                  => $now,
                'employee_id'              => (int) $employee->id,
                'id_usuario'               => $idUsuario,
                'name'                     => $newStoredPath,
                'nameDocument'             => $nameDocument,
                'id_opcion'                => $idOpcion,
                'description'              => $request->input('description'),
                'expiry_date'              => $request->input('expiry_date'),
                'expiry_reminder'          => $request->input('expiry_reminder'),
                'status'                   => (int) $request->input('status', 1),
                'document_context'         => (string) $request->input(
                    'document_context',
                    'expediente'
                ),
                'share_scope'              => $shareScope,
                'collaborator_can_replace' => $collaboratorCanReplace,
            ]);
            $actorNombre = trim(implode(' ', array_filter([
                $administrator->nombre ?? null,
                $administrator->paterno ?? null,
                $administrator->materno ?? null,
            ])));

            if ($actorNombre === '') {
                $actorNombre = $administrator->email ?? $administrator->correo ?? null;
            }

            $auditFields = [
                'id',
                'employee_id',
                'id_usuario',
                'name',
                'nameDocument',
                'id_opcion',
                'description',
                'expiry_date',
                'expiry_reminder',
                'status',
                'share_scope',
                'collaborator_can_replace',
                'creacion',
                'edicion',
            ];

            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => (int) $employee->id_cliente,

                'actor_tipo'       => 'administrador',
                'actor_id'         => $idUsuario,
                'actor_nombre'     => $actorNombre,

                'modulo'           => 'empleados',
                'entidad_tipo'     => 'documento',
                'entidad_id'       => (int) $documentEmpleado->id,

                'accion'           => 'crear',
                'resultado'        => 'exitoso',
                'descripcion'      =>
                'Se creó un documento para el empleado.',

                'datos_anteriores' => null,
                'datos_nuevos'     =>
                $documentEmpleado->only($auditFields),

                'metadatos'        => [
                    'employee_id'              => (int) $employee->id,
                    'categoria_almacenamiento' =>
                    '_documentEmpleado',
                    'archivo_recibido'         => $archivoRecibido,
                    'archivo_guardado'         =>
                    (string) $documentEmpleado->name,
                    'nombre_original'          => $archivoOriginal,
                    'mime_type'                => $archivoMime,
                    'tamano_bytes'             => $archivoTamano,
                    'trace_id'                 => $traceId,
                ],

                /*
                * Relaciona la auditoría con los logs técnicos
                * generados por este mismo store().
                */
                'request_id'       => $traceId,
            ], $request);

            $ms = (int) ((microtime(true) - $t0) * 1000);
            Log::info('✅ Fin CREATE STORE', ['dur_ms' => $ms, 'id' => $documentEmpleado->id]);

            return response()->json([
                'traceId'  => $traceId,
                'message'  => 'Documento agregado exitosamente.',
                'document' => $documentEmpleado,
                'dur_ms'   => $ms,
            ], 201);

        } catch (\Throwable $e) {
            Log::critical('⚡ Error inesperado STORE', ['msg' => $e->getMessage(), 'line' => $e->getLine()]);
            return response()->json([
                'traceId' => $traceId,
                'error'   => 'Error inesperado al procesar la solicitud.',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }

    //  registrar  nuevos  examenes
    public function storeExams(Request $request)
    {
        $creacion = Carbon::now('America/Mexico_City')->format('Y-m-d H:i:s');
        $edicion  = $creacion;

        Log::info('[EXAMEN] ⏱ Iniciando registro', ['payload' => $request->all()]);

        // Sanitizar "file" si viene como string "null"
        if ($request->has('file') && $request->input('file') === 'null') {
            Log::debug('[EXAMEN] 🧼 El campo "file" venía como string "null". Eliminado.');
            $request->request->remove('file');
        }

        // Validación y autorización
        $validator = Validator::make($request->all(), [
            'employee_id'              => [
                'required',
                'integer',
                'min:1',
            ],
            'name'                     => [
                'required',
                'string',
                'max:255',
            ],
            'description'              => [
                'nullable',
                'string',
                'max:500',
            ],
            'expiry_date'              => ['nullable', 'date'],
            'expiry_reminder'          => [
                'nullable',
                'integer',
                'min:0',
            ],
            'file'                     => [
                'nullable',
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:15360',
            ],
            'carpeta'                  => [
                'nullable',
                'string',
                'max:255',
            ],
            'status'                   => ['required', 'integer'],
            'share_scope'              => [
                'nullable',
                'integer',
                'in:0,1,2,3',
            ],
            'collaborator_can_replace' => [
                'nullable',
                'boolean',
            ],
        ]);

        if ($validator->fails()) {
            Log::warning(
                '[EXAMEN] ❌ Validación fallida',
                $validator->errors()->toArray()
            );

            return response()->json(
                $validator->errors(),
                422
            );
        }

        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            (int) $request->input('employee_id')
        );

        $employeeId = (int) $employee->id;
        $idPortal   = (int) $administrator->id_portal;

        $shareScope     = (int) $request->input('share_scope', 0);
        $expiryReminder = (int) $request->input(
            'expiry_reminder',
            0
        );

        $collaboratorCanReplace =
        in_array($shareScope, [1, 3], true) &&
        $request->filled('expiry_date') &&
        $expiryReminder > 0 &&
        $request->boolean('collaborator_can_replace');
        $archivoRecibido =
        $request->hasFile('file')
        && $request->file('file')->isValid();

        $archivoOriginal = $archivoRecibido
            ? $request->file('file')->getClientOriginalName()
            : null;

        $archivoMime = $archivoRecibido
            ? $request->file('file')->getClientMimeType()
            : null;

        $archivoTamano = $archivoRecibido
            ? $request->file('file')->getSize()
            : null;
        // === [2] Obtener o insertar opción ===
        $opcionRequest = new Request([
            'id_portal' => $idPortal,
            'name'      => $request->input('name'),
            'creacion'  => $creacion,
            'tabla'     => 'examenes',
        ]);
        $idOpcion = null;

        $documentOption = ExamOption::where(function ($query) use ($idPortal) {
            $query->where('id_portal', $idPortal)
                ->orWhereNull('id_portal');
        })
            ->where('name', $request->input('name'))
            ->first();

        if ($documentOption) {
            $idOpcion = $documentOption->id;
        }
        if ($idOpcion == null) {
            $nameDocument = $request->input('name');
        } else {
            $nameDocument = null;
        }

        // === [3] Procesar archivo (si existe) ===
        $newFileName = null;

        if ($archivoRecibido) {
            try {
                $randomString  = $this->generateRandomString();
                $fileExtension = $request->file('file')->getClientOriginalExtension();
                $newFileName   = "{$employeeId}_{$randomString}.{$fileExtension}";

                $uploadRequest = new Request();
                $uploadRequest->files->set('file', $request->file('file'));
                $uploadFolder = $this->documentPaths->uploadFolder(
                    '_examEmpleado',
                    $employee
                );

                $uploadRequest->merge([
                    'file_name' => $newFileName,
                    'carpeta'   => $uploadFolder,
                ]);

                $uploadResponse = app(
                    DocumentController::class
                )->upload(
                    $uploadRequest,
                    'documents'
                );

                if ($uploadResponse->getStatusCode() !== 200) {
                    Log::error('[EXAMEN] ❌ Error al subir el archivo.', ['response' => $uploadResponse->getContent()]);
                    return response()->json(['error' => 'Error al subir el documento.'], 500);
                }
                $newFileName = $this->documentPaths->storedPath(
                    '_examEmpleado',
                    $employee,
                    $newFileName
                );
            } catch (\Exception $e) {
                Log::error('[EXAMEN] ⚠️ Excepción al subir archivo.', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
            }
        } else {
            $newFileName = $employeeId . '_sin_examen_' . uniqid();
            Log::info('[Examen] 🗂 No se recibió archivo. Se asigna nombre genérico', ['name' => $newFileName]);
        }

        // === [4] Crear registro en BD ===
        try {
            $examEmpleado = ExamEmpleado::create([
                'creacion'                 => $creacion,
                'edicion'                  => $edicion,
                'employee_id'              => $employeeId,
                'name'                     => $newFileName,
                'nameDocument'             => $nameDocument,
                'id_opcion'                => $idOpcion,
                'description'              => $request->input('description'),
                'expiry_date'              => $request->input('expiry_date'),
                'expiry_reminder'          => $expiryReminder,
                'status'                   => $request->input('status'),
                'share_scope'              => $shareScope,
                'collaborator_can_replace' => $collaboratorCanReplace,

            ]);
        } catch (\Exception $e) {
            Log::error('[EXAMEN] ❌ Error al guardar en BD.', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Error al guardar el examen.'], 500);
        }

        Log::info('[EXAMEN] ✅ Examen registrado correctamente.', ['exam' => $examEmpleado]);
        $actorNombre = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        if ($actorNombre === '') {
            $actorNombre = $administrator->email ?? $administrator->correo ?? null;
        }

        $auditFields = [
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
            'creacion',
            'edicion',
        ];

        $this->auditoria->registrar([
            'id_portal'        => $idPortal,
            'id_cliente'       => (int) $employee->id_cliente,

            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $actorNombre,

            'modulo'           => 'empleados',
            'entidad_tipo'     => 'examen',
            'entidad_id'       => (int) $examEmpleado->id,

            'accion'           => 'crear',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Se creó un examen para el empleado.',

            'datos_anteriores' => null,
            'datos_nuevos'     =>
            $examEmpleado->only($auditFields),

            'metadatos'        => [
                'employee_id'              => $employeeId,
                'categoria_almacenamiento' =>
                '_examEmpleado',
                'archivo_recibido'         => $archivoRecibido,
                'archivo_guardado'         =>
                (string) $examEmpleado->name,
                'nombre_original'          => $archivoOriginal,
                'mime_type'                => $archivoMime,
                'tamano_bytes'             => $archivoTamano,
            ],
        ], $request);
        return response()->json([
            'message'  => 'Examen agregado exitosamente.',
            'document' => $examEmpleado,
        ], 201);
    }

    public function getDocumentsByEmployeeId(
        Request $request,
        $employeeId
    ) {
        // Validar el ID del empleado
        if (! is_numeric($employeeId)) {
            return response()->json(['error' => 'ID de empleado no válido.'], 422);
        }
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            (int) $employeeId
        );

        $filters = $request->validate([
            'document_context' => [
                'nullable',
                'string',
                'in:expediente,salida',
            ],
            'status'           => [
                'nullable',
                'integer',
                'in:1,2,3,999',
            ],
        ]);

        $documentContext = (string) (
            $filters['document_context'] ?? 'expediente'
        );

        $status = $filters['status'] ?? null;

        $query = DocumentEmpleado::with('documentOption')
            ->where(
                'employee_id',
                (int) $employee->id
            )
            ->where(
                'document_context',
                $documentContext
            );

        if ($status !== null) {
            $query->where(
                'status',
                (int) $status
            );
        } else {
            $query->where('status', '!=', 999);
        }

        // Log para verificar los documentos encontrados
        $documentos = $query->get();
        // Verificar si se encontraron documentos
        if ($documentos->isEmpty()) {
            return response()->json(['message' => 'No se encontraron documentos para el empleado.'], 404);
        }

        // Mapear los documentos para incluir el nombre de la opción
        $documentosConOpciones = $documentos->map(function ($documento) {
            return [
                'id'                       => $documento->id,
                'nameDocument'             => $documento->name,
                'optionName'               => $documento->documentOption ? $documento->documentOption->name : null,
                'description'              => $documento->description,
                'upload_date'              => \Carbon\Carbon::parse($documento->upload_date)->format('Y-m-d'),
                'expiry_date'              => $documento->expiry_date,
                'expiry_reminder'          => $documento->expiry_reminder,
                'nameAlterno'              => $documento->nameDocument,
                'status'                   => $documento->status,
                'status_check'             => $documento->status_check === null
                    ? null
                    : (int) $documento->status_check,
                'share_scope'              => (int) $documento->share_scope,
                'collaborator_can_replace' =>
                (bool) $documento->collaborator_can_replace,
                // Agrega otros campos que necesites
            ];
        });

        // Devolver los documentos
        return response()->json(['documentos' => $documentosConOpciones], 200);
    }

    public function updateDocuments(Request $request, $id)
    {
        Log::info('🔁 Entró a updateDocuments', [
            'id'      => $id,
            'keys'    => array_keys($request->all()),
            'hasFile' => $request->hasFile('file'),
        ]);

        // 1) Validar datos aceptados
        $request->validate([
            'carpeta'                  => [
                'required',
                'string',
                'in:_documentEmpleado,_cursos,_examEmpleado',
            ],
            'name'                     => ['nullable', 'string', 'max:255'],
            'description'              => ['nullable', 'string', 'max:2000'],
            'expiry_date'              => ['nullable', 'date'],
            'expiry_reminder'          => ['nullable', 'integer', 'min:0'],
            'status'                   => ['nullable', 'integer'],
            'doc_anterior'             => ['nullable', 'string', 'max:255'],
            'employee_id'              => ['nullable', 'integer', 'min:1'],
            'share_scope'              => [
                'nullable',
                'integer',
                'in:0,1,2,3',
            ],
            'collaborator_can_replace' => ['nullable', 'boolean'],
            'file'                     => ['nullable', 'file', 'max:51200'],
        ]);

        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        // 2) No caer en falso "sin datos"
        $sinCampos = empty(
            $request->except(['id', '_method'])
        ) && ! $request->hasFile('file');

        if ($sinCampos) {
            Log::warning(
                '⚠️ No se enviaron datos útiles (sin campos ni archivo)'
            );

            return response()->json([
                'message' => 'No se enviaron datos, considera eliminar el documento.',
            ], 400);
        }

        $carpeta     = (string) $request->input('carpeta');
        $docAnterior = (string) $request->input('doc_anterior');

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file('file');

        // 3) Configuración autorizada para cada origen
        $fuentes = [
            '_documentEmpleado' => [
                'model'        => DocumentEmpleado::class,
                'tabla'        => 'documentos',
                'entidad_tipo' => 'documento',
                'permission'   =>
                'empleados.expediente.documentos.editar',
            ],
            '_cursos'           => [
                'model'        => CursoEmpleado::class,
                'tabla'        => 'cursos',
                'entidad_tipo' => 'curso',
                'permission'   => 'empleados.cursos.editar',
            ],
            '_examEmpleado'     => [
                'model'        => ExamEmpleado::class,
                'tabla'        => 'examenes',
                'entidad_tipo' => 'examen',
                'permission'   =>
                'empleados.expediente.bgv_examenes.editar',
            ],
        ];

        $fuente      = $fuentes[$carpeta];
        $modelClass  = $fuente['model'];
        $tabla       = $fuente['tabla'];
        $permission  = $fuente['permission'];
        $entidadTipo = $fuente['entidad_tipo'];
        if (! $this->permissions->canAdminGlobal(
            (int) $administrator->id,
            (int) $administrator->id_rol,
            $permission
        )) {
            return response()->json([
                'status'     => false,
                'code'       => 'PERMISSION_DENIED',
                'message'    => 'No tienes permiso para actualizar este tipo de archivo.',
                'permission' => $permission,
            ], 403);
        }

        /** @var \Illuminate\Database\Eloquent\Model|null $document */
        $document = $modelClass::query()->find($id);

        if (! $document) {
            return response()->json([
                'message' => 'Documento no encontrado.',
            ], 404);
        }

        // El employee_id oficial siempre sale del registro encontrado.
        $employeeId = (int) $document->employee_id;

        if (
            $request->filled('employee_id') &&
            (int) $request->input('employee_id') !== $employeeId
        ) {
            return response()->json([
                'status'  => false,
                'code'    => 'EMPLOYEE_MISMATCH',
                'message' => 'El documento no pertenece al empleado indicado.',
            ], 422);
        }

        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            $employeeId
        );

        $idPortal  = (int) $administrator->id_portal;
        $idUsuario = (int) $administrator->id;
        /*
        * Se conserva la referencia anterior para trasladarla
        * después de confirmar el reemplazo en la BD.
        */
        $previousStoredValue = (string) $document->name;
        $auditFields         = [
            'id',
            'employee_id',
            'name',
            'nameDocument',
            'id_opcion',
            'description',
            'expiry_date',
            'expiry_reminder',
            'status',
            'document_context',
            'status_check',
            'share_scope',
            'collaborator_can_replace',
            'edicion',
        ];

        $datosAnteriores = $document->only($auditFields);
        DB::beginTransaction();
        try {
            $input     = $request->all();
            $publicUrl = null;

            /*
            * Solo los documentos de empleado utilizan por ahora
            * la nueva infraestructura documental.
            */
            $usesEmployeeStorage = in_array(
                $carpeta,
                [
                    '_documentEmpleado',
                    '_cursos',
                    '_examEmpleado',
                ],
                true
            ); // 4) ARCHIVO: si llega, generar nombre nuevo y subir
            if ($file) {
                $ext = strtolower(
                    $file->getClientOriginalExtension() ?: 'bin'
                );
                $empId = (string) $employeeId;
                $tipo  = (string) (
                    $input['name'] ?? $document->nameDocument ?? 'documento'
                );
                $slug = Str::slug(
                    str_replace('_sin_', '', $tipo),
                    '-'
                ) ?: 'doc';
                $stamp = date('YmdHis');
                $rand  = substr(sha1(uniqid('', true)), 0, 6);

                $nuevoNombre = "EMP{$empId}_{$slug}_{$stamp}_{$rand}.{$ext}";

                Log::info('🆕 Generado nombre NUEVO', [
                    'nombre' => $nuevoNombre,
                ]);

                /*
                * El backend construye la carpeta oficial.
                * No se utiliza la estructura enviada por el frontend.
                */
                $uploadFolder = $this->documentPaths->uploadFolder(
                    $carpeta,
                    $employee
                );

                $uploadReq = new Request([
                    'file_name' => $nuevoNombre,
                    'carpeta'   => $uploadFolder,
                ]);
                $uploadReq->files->set('file', $file);

                $uploadResp = app(
                    DocumentController::class
                )->upload(
                    $uploadReq,
                    'documents'
                );

                if ($uploadResp->getStatusCode() !== 200) {
                    Log::error(
                        '❌ Falló la carga del archivo nuevo (upload).'
                    );

                    DB::rollBack();

                    return $uploadResp;
                }

                $uploadData = json_decode(
                    $uploadResp->getContent(),
                    true
                );

                $publicUrl = is_array($uploadData)
                    ? ($uploadData['public_url'] ?? null)
                    : null;

                /*
                * Para documentos nuevos se guarda la ruta relativa.
                * Los otros flujos conservan por ahora el nombre simple.
                */
                $document->name = $this->documentPaths->storedPath(
                    $carpeta,
                    $employee,
                    $nuevoNombre
                );
            } else {
                /*
                * Sin archivo se conserva directamente el valor actual
                * de la BD. No se acepta doc_anterior como autoridad.
                */
                Log::info('📎 Sin archivo; se conserva el documento actual', [
                    'name' => $document->name,
                ]);
            }

            // 5) Tipo de documento
            if (array_key_exists('name', $input)) {
                $name = (string) ($input['name'] ?? '');

                $resp = $this->buscar_insertar_opcion(new Request([
                    'id_portal' => $idPortal,
                    'name'      => $name,
                    'tabla'     => $tabla,
                ]));

                $data = json_decode($resp->getContent(), true);

                Log::info('🚀 buscar_insertar_opcion resp:', $data);

                if (isset($data['id_opciones'])) {
                    $document->id_opcion    = $data['id_opciones'];
                    $document->nameDocument = null;
                } else {
                    $document->id_opcion    = null;
                    $document->nameDocument = $name !== ''
                        ? $name
                        : null;
                }
            }

            // 6) Metadatos (sin id_portal) — usar conexión del modelo para Schema
            $table      = $document->getTable();
            $conn       = $document->getConnectionName() ?: config('database.default');
            $schemaConn = Schema::connection($conn);

            // campos lógicos que aceptas en el request
            $metasLogicos = [
                'expiry_date',
                'expiry_reminder',
                'status',
                'description',
                'share_scope',
            ];
            // posibles alias por si cambia el nombre en DB (description ya existe en tu fillable)
            $alias = [
                'description'     => ['description', 'descripcion', 'notes', 'observaciones'],
                'expiry_date'     => ['expiry_date', 'fecha_expira', 'fecha_vencimiento'],
                'expiry_reminder' => ['expiry_reminder', 'recordatorio', 'dias_aviso'],
                'status'          => ['status', 'estado', 'estatus'],
                'share_scope'     => ['share_scope'],
            ];

            foreach ($metasLogicos as $logical) {
                if (! array_key_exists($logical, $input)) {
                    continue; // no vino ese campo
                }

                // Normaliza valor (permite vaciar)
                $val = $input[$logical];
                if ($val === '' || $val === null) {
                    $val = null;
                }

                // Encuentra columna real (primer alias que exista en ESTA conexión)
                $posibles = $alias[$logical] ?? [$logical];
                $colname  = null;
                foreach ($posibles as $cand) {
                    if ($schemaConn->hasColumn($table, $cand)) {
                        $colname = $cand;
                        break;
                    }
                }

                if ($colname) {
                    // casteo simple para status/employee_id si vienen string numérico
                    if (
                        in_array(
                            $colname,
                            ['status', 'expiry_reminder', 'share_scope'],
                            true
                        ) &&
                        $val !== null
                    ) {
                        $val = is_numeric($val) ? (int) $val : $val;
                    }
                    $document->setAttribute($colname, $val);
                    Log::info("📝 Meta actualizado [{$logical}] → [{$colname}] en {$conn}.{$table}: " . var_export($val, true));
                } else {
                    Log::warning("⚠️ No hay columna para [{$logical}] en {$conn}.{$table} (probadas: " . implode(',', $posibles) . ")");
                }
            }
            // La renovación solo puede habilitarse para el colaborador
            // cuando el documento está compartido con él, tiene vencimiento
            // y tiene un recordatorio mayor que cero.
            if ($schemaConn->hasColumn($table, 'collaborator_can_replace')) {
                $requestedReplacement = array_key_exists(
                    'collaborator_can_replace',
                    $input
                )
                    ? $request->boolean('collaborator_can_replace')
                    : (bool) $document->getAttribute(
                    'collaborator_can_replace'
                );

                $shareScope = (int) (
                    $document->getAttribute('share_scope') ?? 0
                );

                $expiryDate = $document->getAttribute('expiry_date');

                $expiryReminder = (int) (
                    $document->getAttribute('expiry_reminder') ?? 0
                );

                $collaboratorCanReplace =
                in_array($shareScope, [1, 3], true) &&
                ! empty($expiryDate) &&
                $expiryReminder > 0 &&
                    $requestedReplacement;

                $document->setAttribute(
                    'collaborator_can_replace',
                    $collaboratorCanReplace
                );
            }
            if ($schemaConn->hasColumn($table, 'edicion')) {
                $document->setAttribute('edicion', now());
            }
            // 7) Guardar
            Log::info('🧾 Cambios detectados', ['changes' => $document->getChanges()]);
            $document->save();

            DB::commit();

            $trashedPreviousPath = null;

            /*
            * La versión anterior se mueve solamente después de que:
            * 1. Llegó un archivo nuevo.
            * 2. Es un documento de empleado.
            * 3. La BD confirmó la nueva referencia.
            */
            if (
                $file
                && $usesEmployeeStorage
                && $previousStoredValue !== ''
                && $previousStoredValue !== (string) $document->name
            ) {
                try {
                    $trashedPreviousPath = $this->documentPaths->moveToTrash(
                        $carpeta,
                        $employee,
                        (int) $document->id,
                        $previousStoredValue,
                        'reemplazados'
                    );

                    Log::info('🗑️ Versión anterior movida a borrados', [
                        'document_id' => (int) $document->id,
                        'previous'    => $previousStoredValue,
                        'trash_path'  => $trashedPreviousPath,
                    ]);
                } catch (\Throwable $trashError) {
                    /*
                    * La actualización ya fue confirmada.
                    * No se revierte ni se pierde el archivo nuevo si falla
                    * el traslado de la versión anterior.
                    */
                    Log::error('⚠️ No se pudo mover la versión anterior', [
                        'document_id' => (int) $document->id,
                        'previous'    => $previousStoredValue,
                        'message'     => $trashError->getMessage(),
                    ]);
                }
            }
            $archivoReemplazado =
            $file !== null
            && $previousStoredValue !== ''
            && $previousStoredValue !== (string) $document->name;

            $actorNombre = trim(implode(' ', array_filter([
                $administrator->nombre ?? null,
                $administrator->paterno ?? null,
                $administrator->materno ?? null,
            ])));

            if ($actorNombre === '') {
                $actorNombre = $administrator->email ?? $administrator->correo ?? null;
            }
            $auditModule = (
                (string) $document->document_context === 'salida'
            )
                ? 'exempleados'
                : 'empleados';
            $this->auditoria->registrar([
                'id_portal'    => $idPortal,
                'id_cliente'   => (int) $employee->id_cliente,

                'actor_tipo'   => 'administrador',
                'actor_id'     => $idUsuario,
                'actor_nombre' => $actorNombre,

                'modulo' => $auditModule,
                'entidad_tipo' => $entidadTipo,
                'entidad_id'   => (int) $document->id,

                'accion'       => $archivoReemplazado
                    ? 'reemplazar_archivo'
                    : 'actualizar',

                'resultado'    => 'exitoso',

                'descripcion'  => $archivoReemplazado
                    ? "Se reemplazó el archivo del {$entidadTipo}."
                    : "Se actualizaron los datos del {$entidadTipo}.",

                'datos_anteriores' => $datosAnteriores,

                'datos_nuevos' => $document->only($auditFields),

                'metadatos' => [
                    'employee_id'              => $employeeId,
                    'categoria_almacenamiento' => $carpeta,
                    'archivo_anterior'         => $previousStoredValue,
                    'archivo_nuevo'            => (string) $document->name,
                    'archivo_respaldo'         => $trashedPreviousPath,
                    'archivo_recibido'         => $file !== null,
                    'archivo_reemplazado'      => $archivoReemplazado,
                ],
            ], $request);

            Log::info('✅ Documento actualizado correctamente', [
                'id'         => $id,
                'name'       => $document->name,
                'trash_path' => $trashedPreviousPath,
            ]);
            return response()->json([
                'message'                  => 'Documento actualizado correctamente.',
                'id'                       => (int) $id,
                'name'                     => $document->name,
                'publicUrl'                => $publicUrl,
                'previous_file_trash'      => $trashedPreviousPath,
                'share_scope'              => (int) (
                    $document->getAttribute('share_scope') ?? 0
                ),
                'collaborator_can_replace' => (bool) (
                    $document->getAttribute(
                        'collaborator_can_replace'
                    ) ?? false
                ), // puede ser null si upload() no lo devuelve
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('🟥 DOC_UPDATE_ERR', [
                'id'   => $id,
                'msg'  => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['message' => 'Error al actualizar el documento.'], 500);
        }
    }

    public function updateExpiry(Request $request, $id)
    {
        // Validación simple y clara
        $data = $request->validate([
            'expiry_date'     => ['required', 'date'],
            'expiry_reminder' => ['nullable', 'integer'],
            'status'          => ['nullable', 'integer'],
        ]);

        // Buscar en modelos posibles (sin carpeta)
        $document = \App\Models\ExamEmpleado::find($id) ?? \App\Models\DocumentEmpleado::find($id) ?? \App\Models\CursoEmpleado::find($id);

        if (! $document) {
            return response()->json([
                'message' => 'Documento no encontrado',
            ], 404);
        }

        // Actualizar SOLO lo necesario
        if (array_key_exists('expiry_date', $data)) {
            $document->expiry_date = $data['expiry_date'];
        }

        if (array_key_exists('expiry_reminder', $data)) {
            $document->expiry_reminder = $data['expiry_reminder'];
        }

        if (array_key_exists('status', $data)) {
            $document->status = $data['status'];
        }

        $document->save();

        return response()->json([
            'message' => 'Expiración actualizada correctamente',
            'id'      => $document->id,
        ]);
    }

    public function deleteDocument(Request $request)
    {
        $data = $request->validate([
            'tabla' => [
                'required',
                'string',
                'in:documentos,cursos,examenes',
            ],
            'id'    => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'code'    => 'ADMIN_TOKEN_INVALID',
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        /*
     * Configuración autorizada desde el backend.
     * El frontend solo indica el tipo lógico.
     */
        $sources = [
            'documentos' => [
                'model'        => DocumentEmpleado::class,
                'category'     => '_documentEmpleado',
                'entidad_tipo' => 'documento',
                'permission'   =>
                'empleados.expediente.documentos.eliminar',
            ],
            'cursos'     => [
                'model'        => CursoEmpleado::class,
                'category'     => '_cursos',
                'entidad_tipo' => 'curso',
                'permission'   =>
                'empleados.cursos.eliminar',
            ],
            'examenes'   => [
                'model'        => ExamEmpleado::class,
                'category'     => '_examEmpleado',
                'entidad_tipo' => 'examen',
                'permission'   =>
                'empleados.expediente.bgv_examenes.eliminar',
            ],
        ];

        $source      = $sources[$data['tabla']];
        $modelClass  = $source['model'];
        $category    = $source['category'];
        $permission  = $source['permission'];
        $entidadTipo = $source['entidad_tipo'];
        $documentId  = (int) $data['id'];

        /** @var \Illuminate\Database\Eloquent\Model|null $document */
        $document = $modelClass::query()->find($documentId);

        if (! $document) {
            return response()->json([
                'message' => 'Documento no encontrado.',
            ], 404);
        }

        if ((int) $document->status === 999) {
            return response()->json([
                'message' => 'El documento ya fue eliminado.',
                'id'      => $documentId,
            ], 200);
        }
        $auditFields = [
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
            'creacion',
            'edicion',
        ];

        $datosAnteriores = $document->only($auditFields);
        /*
     * El empleado y su alcance se obtienen desde el registro.
     * No se confía en un employee_id enviado por el frontend.
     */
        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            (int) $document->employee_id
        );

        if (! $this->permissions->canAdminGlobal(
            (int) $administrator->id,
            (int) $administrator->id_rol,
            $permission
        )) {
            return response()->json([
                'status'     => false,
                'code'       => 'PERMISSION_DENIED',
                'message'    =>
                'No tienes permiso para eliminar este archivo.',
                'permission' => $permission,
            ], 403);
        }

        /*
     * La referencia física siempre procede de la BD.
     */
        $storedValue = trim((string) $document->name);

        DB::beginTransaction();

        try {
            $document->update([
                'status' => 999,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('❌ No se pudo aplicar el borrado lógico', [
                'tabla'       => $data['tabla'],
                'document_id' => $documentId,
                'message'     => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo eliminar el archivo.',
            ], 500);
        }

        $trashPath         = null;
        $trashErrorMessage = null;

        /*
     * Solo después de confirmar la BD se traslada el archivo.
     * absolutePath() permite tomar archivos antiguos o nuevos.
     */
        if ($storedValue !== '') {
            try {
                $trashPath = $this->documentPaths->moveToTrash(
                    $category,
                    $employee,
                    $documentId,
                    $storedValue,
                    'eliminados'
                );

                Log::info('🗑️ Archivo movido a borrados', [
                    'tabla'        => $data['tabla'],
                    'document_id'  => $documentId,
                    'stored_value' => $storedValue,
                    'trash_path'   => $trashPath,
                ]);
            } catch (\Throwable $trashError) {
                /*
             * El registro ya está marcado como eliminado.
             * Si falla el traslado, el archivo permanece en origen.
             */
                $trashErrorMessage = $trashError->getMessage();
                Log::error('⚠️ No se pudo trasladar el archivo eliminado', [
                    'tabla'        => $data['tabla'],
                    'document_id'  => $documentId,
                    'stored_value' => $storedValue,
                    'message'      => $trashError->getMessage(),
                ]);
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

        $trasladoEsperado = $storedValue !== '';

        $trasladoCompletado =
        ! $trasladoEsperado
            || $trashPath !== null;

        $resultadoAuditoria = $trasladoCompletado
            ? 'exitoso'
            : 'exitoso_con_advertencia';

        $this->auditoria->registrar([
            'id_portal'    => (int) $administrator->id_portal,
            'id_cliente'   => (int) $employee->id_cliente,

            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' => $actorNombre,

            'modulo'       => 'empleados',
            'entidad_tipo' => $entidadTipo,
            'entidad_id'   => $documentId,

            'accion'       => 'eliminar',
            'resultado'    => $resultadoAuditoria,

            'descripcion'  => $trasladoCompletado
                ? "Se eliminó el {$entidadTipo}."
                : "Se eliminó lógicamente el {$entidadTipo}, pero su archivo no pudo trasladarse.",

            'datos_anteriores' => $datosAnteriores,
            'datos_nuevos'     => $document->only($auditFields),

            'metadatos'        => [
                'employee_id'              => (int) $employee->id,
                'categoria_almacenamiento' => $category,
                'archivo_anterior'         => $storedValue,
                'archivo_respaldo'         => $trashPath,
                'traslado_esperado'        => $trasladoEsperado,
                'traslado_completado'      => $trasladoCompletado,
                'error_traslado'           => $trashErrorMessage,
                'borrado_logico'           => true,
                'status_eliminado'         => 999,
            ],
        ], $request);
        return response()->json([
            'message'    => 'Archivo eliminado correctamente.',
            'id'         => $documentId,
            'tabla'      => $data['tabla'],
            'trash_path' => $trashPath,
        ], 200);
    }
    public function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

}
