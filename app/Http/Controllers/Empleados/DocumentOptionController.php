<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
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
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DocumentOptionController extends Controller
{

    public function getExamsByEmployeeId($employeeId)
    {
        // Validar el ID del empleado
        if (! is_numeric($employeeId)) {
            return response()->json(['error' => 'ID de empleado no válido.'], 422);
        }

        // Buscar documentos del empleado junto con las opciones
        $exam = ExamEmpleado::with('examOption')->where('employee_id', $employeeId)->get();

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
                'id'              => $documento->id,
                'nameDocument'    => $documento->name,
                'optionName'      => $documento->examOption ? $documento->examOption->name : null,
                //'optionType'      => $documento->examOption ? $documento->examOption->type : null,
                'description'     => $documento->description,
                'upload_date'     => \Carbon\Carbon::parse($documento->upload_date)->format('Y-m-d'),
                'expiry_date'     => $documento->expiry_date,
                'nameAlterno'     => $documento->nameDocument,
                'statusexm'       => $documento->status,
                'expiry_reminder' => $documento->expiry_reminder,
                'id_candidato'    => $documento->id_candidato,
                'socioeconomico'  => $candidatoPrueba->socioeconomico ?? null,
                'medico'          => $candidatoPrueba->medico ?? null,
                'tipo_antidoping' => $candidatoPrueba->tipo_antidoping ?? null,
                'antidoping'      => $candidatoPrueba->antidoping ?? null,
                'psicometrico'    => $candidatoPrueba->psicometrico ?? null,
                'medicoDetalle'   => [
                    'id'                    => $medico->id ?? null,
                    'imagen'                => $medico->imagen_historia_clinica ?? null,
                    'conclusion'            => $medico->conclusion ?? null,
                    'descripcion'           => $medico->descripcion ?? null,
                    'archivo_examen_medico' => $medico->archivo_examen_medico ?? null,
                ],
                'psicometricoDet' => [
                    'id'                   => $psicometrico->id ?? null,
                    'archivo_psicometrico' => $psicometrico->archivo ?? null,
                ],
                'doping'          => [
                    'id'               => $doping->id ?? null,
                    'doping_hecho'     => $candidatoPrueba->status_doping ?? null,
                    'fecha_resultado'  => $doping->fecha_resultado ?? null,
                    'resultado_doping' => $doping->resultado ?? null,
                    'statusDoping'     => $doping->status ?? null,
                ],
                'liberado'        => $candidato->liberado ?? null,
                'status_bgc'      => $candidato->status_bgc ?? null,
                'cancelado'       => $candidato->cancelado ?? null,
                'icono_resultado' => $icono_resultado,
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
        try {
            $now = Carbon::now('America/Mexico_City');

                                 // === [0] Log de entrada ===
            $traceId = uniqid(); // ID único para rastrear este request
            Log::info("[DOCUMENTO][$traceId] ⏱ Iniciando registro", [
                'payload' => $request->all(),
                'ip'      => $request->ip(),
                'user_id' => $request->user()?->id,
            ]);

            // Normalizar campo "file" si viene como texto "null"
            if ($request->has('file') && $request->input('file') === 'null') {
                Log::debug("[DOCUMENTO][$traceId] 🧼 Campo 'file' venía como string 'null'. Eliminado para evitar errores de validación.");
                $request->request->remove('file');
            }

            // === [1] Validación de datos ===
            $validator = Validator::make($request->all(), [
                'employee_id'     => 'required|integer',
                'name'            => 'required|string|max:255',
                'description'     => 'nullable|string|max:500',
                'expiry_date'     => 'nullable|date',
                'expiry_reminder' => 'nullable|integer',
                'file'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'id_portal'       => 'required|integer',
                'status'          => 'required|integer',
                'carpeta'         => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning("[DOCUMENTO][$traceId] ⚠ Validación fallida", [
                    'errors'  => $validator->errors(),
                    'payload' => $request->all(),
                ]);
                return response()->json($validator->errors(), 422);
            }

            // === [2] Buscar coincidencia en DocumentOption ===
            $idOpcion       = null;
            $documentOption = DocumentOption::where(function ($query) use ($request) {
                $query->where('id_portal', $request->input('id_portal'))
                    ->orWhereNull('id_portal');
            })
                ->where('name', $request->input('name'))
                ->first();

            if ($documentOption) {
                $idOpcion = $documentOption->id;
                Log::info("[DOCUMENTO][$traceId] 🔍 Opción de documento encontrada", ['id' => $idOpcion]);
            } else {
                Log::info("[DOCUMENTO][$traceId] 🔍 No existe opción de documento, se usará nombre genérico", ['name' => $request->input('name')]);
            }

            $nameDocument = $idOpcion ? null : $request->input('name');

            // === [3] Procesar archivo si existe ===
            $newFileName = null;

            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                $file = $request->file('file');
                Log::info("[DOCUMENTO][$traceId] 📁 Archivo recibido", [
                    'original_name' => $file->getClientOriginalName(),
                    'size'          => $file->getSize(),
                    'mime'          => $file->getMimeType(),
                ]);

                try {
                    $employeeId    = $request->input('employee_id');
                    $randomString  = $this->generateRandomString();
                    $fileExtension = $file->getClientOriginalExtension();
                    $newFileName   = "{$employeeId}_{$randomString}.{$fileExtension}";

                    $uploadRequest = new Request();
                    $uploadRequest->files->set('file', $file);
                    $uploadRequest->merge([
                        'file_name' => $newFileName,
                        'carpeta'   => $request->input('carpeta'),
                    ]);

                    $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

                    if ($uploadResponse->getStatusCode() !== 200) {
                        Log::error("[DOCUMENTO][$traceId] ❌ Error al subir archivo", ['response' => $uploadResponse->getContent()]);
                        return response()->json(['error' => 'Error al subir el documento.'], 500);
                    }

                    Log::info("[DOCUMENTO][$traceId] ✅ Archivo subido exitosamente", ['new_name' => $newFileName]);

                } catch (\Exception $e) {
                    Log::error("[DOCUMENTO][$traceId] 💥 Excepción al subir archivo", ['exception' => $e]);
                    return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
                }

            } else {
                $newFileName = $request->input('employee_id') . '_sin_documento_' . uniqid();
                Log::info("[DOCUMENTO][$traceId] 🗂 No se recibió archivo. Se asigna nombre genérico", ['name' => $newFileName]);
            }

            // === [4] Crear registro en la base de datos ===
            try {
                $documentEmpleado = DocumentEmpleado::create([
                    'creacion'        => $now,
                    'edicion'         => $now,
                    'employee_id'     => $request->input('employee_id'),
                    'name'            => $newFileName,
                    'nameDocument'    => $nameDocument,
                    'id_opcion'       => $idOpcion,
                    'description'     => $request->input('description'),
                    'expiry_date'     => $request->input('expiry_date'),
                    'expiry_reminder' => $request->input('expiry_reminder'),
                    'status'          => $request->input('status', 1),
                ]);

                Log::info("[DOCUMENTO][$traceId] 📄 Documento registrado exitosamente", ['document' => $documentEmpleado->toArray()]);

            } catch (\Exception $e) {
                Log::error("[DOCUMENTO][$traceId] 💥 Error al crear documento en BD", ['exception' => $e]);
                return response()->json(['error' => 'Error al guardar el documento.'], 500);
            }

            return response()->json([
                'message'  => 'Documento agregado exitosamente.',
                'document' => $documentEmpleado,
            ], 201);

        } catch (\Exception $e) {
            Log::critical("[DOCUMENTO][$traceId] ⚡ Error inesperado", [
                'exception' => $e,
                'payload'   => $request->all(),
            ]);
            return response()->json(['error' => 'Error inesperado al procesar la solicitud.'], 500);
        }
    }

    /*
    public function store(Request $request)
    {
        try {
            $now = Carbon::now('America/Mexico_City');

            // Log de entrada
            Log::info('[DOCUMENTO] ⏱ Iniciando registro', ['payload' => $request->all()]);

            // Normalizar campo "file" si viene como texto "null"
            if ($request->has('file') && $request->input('file') === 'null') {
                Log::debug('[DOCUMENTO] 🧼 El campo "file" venía como string "null". Eliminado para evitar errores de validación.');
                $request->request->remove('file');
            }

            // === [1] Validación de datos ===
            $validator = Validator::make($request->all(), [
                'employee_id'     => 'required|integer',
                'name'            => 'required|string|max:255',
                'description'     => 'nullable|string|max:500',
                'expiry_date'     => 'nullable|date',
                'expiry_reminder' => 'nullable|integer',
                'file'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
                'id_portal'       => 'required|integer',
                'status'          => 'required|integer',
                'carpeta'         => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            // === [2] Buscar coincidencia en DocumentOption ===
            $idOpcion = null;

            $documentOption = DocumentOption::where(function ($query) use ($request) {
                $query->where('id_portal', $request->input('id_portal'))
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

            // === [3] Procesar archivo si existe ===
            $newFileName = null;

            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                try {
                    $employeeId    = $request->input('employee_id');
                    $randomString  = $this->generateRandomString();
                    $fileExtension = $request->file('file')->getClientOriginalExtension();
                    $newFileName   = "{$employeeId}_{$randomString}.{$fileExtension}";

                    $uploadRequest = new Request();
                    $uploadRequest->files->set('file', $request->file('file'));
                    $uploadRequest->merge([
                        'file_name' => $newFileName,
                        'carpeta'   => $request->input('carpeta'),
                    ]);

                    $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

                    if ($uploadResponse->getStatusCode() !== 200) {
                        Log::error('Error al subir el archivo.', ['response' => $uploadResponse->getContent()]);
                        return response()->json(['error' => 'Error al subir el documento.'], 500);
                    }
                } catch (\Exception $e) {
                    Log::error('Excepción al subir el archivo.', ['exception' => $e->getMessage()]);
                    return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
                }
            } else {
                $newFileName = $request->input('employee_id') . '_sin_documento_' . uniqid();
                Log::info('[CURSO] 🗂 No se recibió archivo. Se asigna nombre genérico', ['name' => $newFileName]);
            }

            // === [4] Crear registro en la base de datos ===
            try {
                $documentEmpleado = DocumentEmpleado::create([
                    'creacion'        => $now,
                    'edicion'         => $now,
                    'employee_id'     => $request->input('employee_id'),
                    'name'            => $newFileName,  // nombre físico del archivo
                    'nameDocument'    => $nameDocument, // nombre real del documento
                    'id_opcion'       => $idOpcion,     // solo si existe opción
                    'description'     => $request->input('description'),
                    'expiry_date'     => $request->input('expiry_date'),
                    'expiry_reminder' => $request->input('expiry_reminder'),
                    'status'          => $request->input('status', 1),
                ]);
            } catch (\Exception $e) {
                Log::error('Error al crear el documento en BD.', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Error al guardar el documento.'], 500);
            }

            Log::info('Documento registrado exitosamente.', ['document' => $documentEmpleado]);

            return response()->json([
                'message'  => 'Documento agregado exitosamente.',
                'document' => $documentEmpleado,
            ], 201);

        } catch (\Exception $e) {
            Log::critical('Error inesperado.', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Error inesperado al procesar la solicitud.'], 500);
        }
    }
    */
    //  registrar  nuevos  documentos
    /*public function store(Request $request)
    {
        $creacion = Carbon::now('America/Mexico_City')->format('Y-m-d H:i:s');
        $edicion  = Carbon::now('America/Mexico_City')->format('Y-m-d H:i:s');
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'employee_id'     => 'required|integer',
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string|max:500',
            'expiry_date'     => 'nullable|date',
            'expiry_reminder' => 'nullable|integer',
            'file'            => 'required|file|mimes:pdf,application/pdf,application/x-pdf,application/acrobat,application/vnd.pdf,jpg,jpeg,png|max:10240',

            'id_portal'       => 'required|integer',
            'status'          => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Log de los datos recibidos
        Log::info('Datos recibidos en el store:', $request->all());

        // Verificar si se recibió un archivo
        if (! $request->hasFile('file')) {
            Log::error('No se recibió ningún archivo en la solicitud.');
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // Asegurarse de que el archivo es válido
        if (! $request->file('file')->isValid()) {
            Log::error('El archivo recibido no es válido.');
            return response()->json(['error' => 'El archivo recibido no es válido.'], 400);
        }

        // Llamar a buscar_insertar_opcion para obtener el id_opciones
        $opcionRequest = new Request([
            'id_portal' => $request->input('id_portal'),
            'name'      => $request->input('name'),
            'creacion'  => $creacion,
            'tabla'     => 'documentos',
        ]);

        $opcionResponse = $this->buscar_insertar_opcion($opcionRequest);
        $idOpcion       = json_decode($opcionResponse->getContent())->id_opciones;

        // Log para verificar el ID obtenido
        // Log::info('ID de opción obtenido:', ['id_opcion' => $idOpcion]);

        // Preparar la solicitud para la subida del archivo
        $employeeId    = $request->input('employee_id');
        $randomString  = $this->generateRandomString();                        // Generar la cadena aleatoria
        $fileExtension = $request->file('file')->getClientOriginalExtension(); // Obtener la extensión del archivo

        // Crear el nuevo nombre de archivo
        $newFileName = "{$employeeId}_{$randomString}.{$fileExtension}";

        // Preparar la solicitud para la subida del archivo
        $uploadRequest = new Request();
        $uploadRequest->files->set('file', $request->file('file'));
        $uploadRequest->merge([
            'file_name' => $newFileName,
            'carpeta'   => $request->input('carpeta'),
        ]);
        $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

        // Verificar si la subida fue exitosa
        if ($uploadResponse->getStatusCode() !== 200) {
            return response()->json(['error' => 'Error al subir el documento.'], 500);
        }

        // Log para verificar el ID antes de la creación
        //  Log::info('Preparándose para crear DocumentEmpleado con id_opcion:', ['id_opcion' => $idOpcion]);

        // Crear un nuevo registro en la base de datos
        $documentEmpleado = DocumentEmpleado::create([
            'creacion'        => $creacion,
            'edicion'         => $creacion,
            'employee_id'     => $request->input('employee_id'),
            'name'            => $newFileName,
            'id_opcion'       => $idOpcion, // Aquí se usa el ID correcto
            'description'     => $request->input('description'),
            'expiry_date'     => $request->input('expiry_date'),
            'expiry_reminder' => $request->input('expiry_reminder'),
            'status'          => $request->input('status', 1),
        ]);

        // Log para verificar el documento registrado
        Log::info('Documento registrado:', ['document' => $documentEmpleado]);

        // Devolver una respuesta exitosa
        return response()->json([
            'message'  => 'Documento agregado exitosamente.',
            'document' => $documentEmpleado,
        ], 201);
    }
    */

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

        // Validación
        $validator = Validator::make($request->all(), [
            'employee_id'     => 'required|integer',
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string|max:500',
            'expiry_date'     => 'nullable|date',
            'expiry_reminder' => 'nullable|integer',
            'file'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'id_portal'       => 'required|integer',
            'carpeta'         => 'nullable|string|max:255',

        ]);

        if ($validator->fails()) {
            Log::warning('[EXAMEN] ❌ Validación fallida', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        // === [2] Obtener o insertar opción ===
        $opcionRequest = new Request([
            'id_portal' => $request->input('id_portal'),
            'name'      => $request->input('name'),
            'creacion'  => $creacion,
            'tabla'     => 'examenes',
        ]);
        $idOpcion = null;

        $documentOption = ExamOption::where(function ($query) use ($request) {
            $query->where('id_portal', $request->input('id_portal'))
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

        if ($request->hasFile('file') && $request->file('file')->isValid()) {
            try {
                $employeeId    = $request->input('employee_id');
                $randomString  = $this->generateRandomString();
                $fileExtension = $request->file('file')->getClientOriginalExtension();
                $newFileName   = "{$employeeId}_{$randomString}.{$fileExtension}";

                $uploadRequest = new Request();
                $uploadRequest->files->set('file', $request->file('file'));
                $uploadRequest->merge([
                    'file_name' => $newFileName,
                    'carpeta'   => $request->input('carpeta') ?? 'examenes',
                ]);

                $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

                if ($uploadResponse->getStatusCode() !== 200) {
                    Log::error('[EXAMEN] ❌ Error al subir el archivo.', ['response' => $uploadResponse->getContent()]);
                    return response()->json(['error' => 'Error al subir el documento.'], 500);
                }
            } catch (\Exception $e) {
                Log::error('[EXAMEN] ⚠️ Excepción al subir archivo.', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
            }
        } else {
            $newFileName = $request->input('employee_id') . '_sin_examen_' . uniqid();
            Log::info('[CURSO] 🗂 No se recibió archivo. Se asigna nombre genérico', ['name' => $newFileName]);
        }

        // === [4] Crear registro en BD ===
        try {
            $examEmpleado = ExamEmpleado::create([
                'creacion'        => $creacion,
                'edicion'         => $edicion,
                'employee_id'     => $request->input('employee_id'),
                'name'            => $newFileName,
                'nameDocument'    => $nameDocument,
                'id_opcion'       => $idOpcion,
                'description'     => $request->input('description'),
                'expiry_date'     => $request->input('expiry_date'),
                'expiry_reminder' => $request->input('expiry_reminder'),
                'status'          => $request->input('status'),

            ]);
        } catch (\Exception $e) {
            Log::error('[EXAMEN] ❌ Error al guardar en BD.', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Error al guardar el examen.'], 500);
        }

        Log::info('[EXAMEN] ✅ Examen registrado correctamente.', ['exam' => $examEmpleado]);

        return response()->json([
            'message'  => 'Examen agregado exitosamente.',
            'document' => $examEmpleado,
        ], 201);
    }

    public function getDocumentsByEmployeeId($employeeId)
    {
        // Validar el ID del empleado
        if (! is_numeric($employeeId)) {
            return response()->json(['error' => 'ID de empleado no válido.'], 422);
        }
        $status = request()->query('status'); // 👈 Captura el parámetro

        $query = DocumentEmpleado::with('documentOption')->where('employee_id', $employeeId);

        if ($status) {
            $query->where('status', $status); // 👈 Aplica el filtro
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
                'id'              => $documento->id,
                'nameDocument'    => $documento->name,
                'optionName'      => $documento->documentOption ? $documento->documentOption->name : null,
                'description'     => $documento->description,
                'upload_date'     => \Carbon\Carbon::parse($documento->upload_date)->format('Y-m-d'),
                'expiry_date'     => $documento->expiry_date,
                'expiry_reminder' => $documento->expiry_reminder,
                'nameAlterno'     => $documento->nameDocument,
                'status'          => $documento->status,
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
            'request' => $request->all(),
        ]);

        if (count($request->except(['id', '_method'])) === 0) {
            Log::warning('⚠️ No se enviaron datos útiles');
            return response()->json([
                'message' => 'No se enviaron datos, considera eliminar el documento.',
            ], 400);
        }

        $carpeta     = $request->input('carpeta');
        $docAnterior = $request->input('doc_anterior');
        $file        = $request->file('file');

        $mapaCarpetas = [
            '_documentEmpleado' => \App\Models\DocumentEmpleado::class,
            '_cursos'           => \App\Models\CursoEmpleado::class,
            '_examEmpleado'     => \App\Models\ExamEmpleado::class,
        ];

        $carpetaATabla = [
            '_documentEmpleado' => 'documentos',
            '_examEmpleado'     => 'examenes',
            '_cursos'           => 'cursos',
        ];

        $modelClass = $mapaCarpetas[$carpeta] ?? null;

        if (! $modelClass) {
            Log::error("❌ Carpeta no reconocida: [$carpeta]");
            return response()->json(['message' => 'Carpeta no reconocida.'], 400);
        }

        $document = $modelClass::find($id);

        if (! $document) {
            Log::error("❌ Documento no encontrado en modelo [$modelClass] con ID [$id]");
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        // Eliminar archivo anterior y subir nuevo
        if ($file && $docAnterior) {
            $docController = new DocumentController();

            Log::info("📤 Eliminando archivo anterior: $docAnterior");
            $deleteReq = new Request([
                'file_name' => $docAnterior,
                'carpeta'   => $carpeta,
            ]);
            $docController->deleteFile($deleteReq);

            // Si contiene "_sin_", se genera un nuevo nombre
            if (str_contains($docAnterior, '_sin_')) {
                $extension   = $file->getClientOriginalExtension();
                $nuevoNombre = time() . '_' . uniqid() . '.' . $extension;
                Log::info("✏️ Se detectó '_sin_' en el nombre. Nuevo nombre generado: $nuevoNombre");
            } else {
                $nuevoNombre = $docAnterior;
                Log::info("📎 Se conservará el nombre anterior: $nuevoNombre");
            }

            Log::info("📥 Subiendo nuevo archivo: $nuevoNombre");

            $uploadReq = new Request([
                'file_name' => $nuevoNombre,
                'carpeta'   => $carpeta,
            ]);
            $uploadReq->files->set('file', $file);

            $uploadResponse = $docController->upload($uploadReq);

            if ($uploadResponse->getStatusCode() !== 200) {
                Log::error("❌ Falló la carga del archivo nuevo.");
                return $uploadResponse;
            }

            $document->name = $nuevoNombre;
        }

        // Si no hay nuevo archivo pero sí doc anterior
        if (! $file && $docAnterior) {
            Log::info("📎 Se conservará el documento anterior: $docAnterior");
            $document->name = $docAnterior;
        }

        // Procesar nombre del documento (tipo o nombre libre)
        if ($request->filled('name') && isset($carpetaATabla[$carpeta])) {
            $name      = $request->input('name');
            $id_portal = $request->input('id_portal');
            $tabla     = $carpetaATabla[$carpeta];

            $response = $this->buscar_insertar_opcion(new Request([
                'id_portal' => $id_portal,
                'name'      => $name,
                'tabla'     => $tabla,
            ]));

            $data = json_decode($response->getContent(), true);
            Log::info("🚀 Respuesta de buscar_insertar_opcion:", $data);

            if (isset($data['id_opciones'])) {
                $document->id_opcion    = $data['id_opciones'];
                $document->nameDocument = null;
                Log::info("📝 Se asignó id_opcion = {$data['id_opciones']} y se limpió nameDocument");
            } else {
                $document->nameDocument = $name;
                Log::info("📝 No se encontró opción, se asignó nameDocument = $name");
            }
        }

        // Campos adicionales actualizables
        $fields = ['expiry_date', 'expiry_reminder', 'status', 'description'];
        foreach ($fields as $field) {
            if ($request->filled($field)) {
                $valor            = $request->input($field);
                $document->$field = $valor;
                Log::info("📝 Campo actualizado [$field]: $valor");
            }
        }

        $document->save();

        Log::info("✅ Documento actualizado correctamente", ['id' => $id]);

        return response()->json(['message' => 'Documento actualizado correctamente.'], 200);
    }

    public function deleteDocument(Request $request)
    {
        // Reglas dinámicas por tabla
        $rules = [
            'tabla' => 'required|string',
            'id'    => 'required|integer',
        ];

        $request->validate($rules);

        $tabla = $request->tabla;
        $id    = $request->id;

        // Determinar modelo y carpeta
        $tablaModelo = [
            'examenes'   => [ExamEmpleado::class, '_examEmpleado/'],
            'documentos' => [DocumentEmpleado::class, '_documentEmpleado/'],
            'cursos'     => [CursoEmpleado::class, '_cursos/'],
        ];

        if (! isset($tablaModelo[$tabla])) {
            return response()->json(['message' => 'Invalid table specified'], 400);
        }

        [$modelClass, $carpeta] = $tablaModelo[$tabla];

        // Buscar registro
        $document = $modelClass::find($id);
        if (! $document) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        // Construir path base
        $basePath = env('APP_ENV') === 'local'
            ? env('LOCAL_IMAGE_PATH')
            : env('PROD_IMAGE_PATH');

        $fileName = $document->nameDocument ?? null;

        // Intentar eliminar archivo si existe nombre
        if ($fileName) {
            $filePath = $basePath . $carpeta . $fileName;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Eliminar registro de la base de datos
        $document->delete();

        return response()->json(['message' => 'Record deleted successfully'], 200);
    }

    public function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

}
