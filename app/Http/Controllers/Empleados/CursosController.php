<?php
namespace App\Http\Controllers\Empleados;

use App\Exports\CursosExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Auth\AdministradorAuth;
use App\Models\ClienteTalent;
use App\Models\CursoEmpleado;
use App\Models\Empleado;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminEmployeeScopeService;
use App\Services\Auth\PermissionService;
use App\Services\Documents\EmployeeDocumentPathService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http; // Cambia esto al nombre correcto de tu controlador
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class CursosController extends Controller
{
    public function __construct(
        private AdminEmployeeScopeService $employeeScope,
        private PermissionService $permissions,
        private EmployeeDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}

    public function exportCursosPorCliente($clienteId)
    {

        // Limpiar cachés de manera temporal

        // Llama al método para obtener los datos del cliente
        $cliente = ClienteTalent::with(['cursos.empleado', 'cursos.documentOption'])->find($clienteId);

        if (! $cliente) {
            return response()->json(['error' => 'Cliente no encontrado'], 404);
        }

        // Llama al método para obtener los cursos
        $cursos = $cliente->cursos->map(function ($curso) {
            // Nombre del curso (prioridad)
            $cursoNombre = $curso->documentOption?->name ?? $curso->nameDocument ?? ($curso->name ? pathinfo($curso->name, PATHINFO_FILENAME) : null);

            // Empleado (null-safe)
            $empId     = $curso->empleado?->id_empleado;
            $empNombre = trim(implode(' ', array_filter([
                $curso->empleado?->nombre,
                $curso->empleado?->paterno,
                $curso->empleado?->materno,
            ])));
            $empleadoStr = $empId ? "ID: {$empId} - {$empNombre}" : 'Sin asignar';

            // Fecha para el export (tu Excel espera 'fecha_expiracion')
            $fecha = $curso->expiry_date;

            // Estado (si tu helper espera fecha)
            $estado = $this->getEstadoCurso1($fecha);

            return [
                'curso'            => is_string($cursoNombre) ? trim($cursoNombre) : $cursoNombre,
                'empleado'         => $empleadoStr,
                'fecha_expiracion' => $fecha,
                'estado'           => $estado,
            ];
        })->values();

// Descargar (mejor pasar array plano)
        return Excel::download(new CursosExport($cursos->all(), $cliente->nombre), "reporte_cursos_{$clienteId}.xlsx");
    }

    private function getEstadoCurso1($expiryDate)
    {
        if (! $expiryDate) {
            return '';
        }

        $fechaExpiracion = Carbon::parse($expiryDate);
        $fechaHoy        = Carbon::now();

        if ($fechaExpiracion->isPast()) {
            return 'Expirado';
        }

        return 'Vigente';
    }

    public function store(Request $request)
    {
        try {
            $now = Carbon::now('America/Mexico_City');

            Log::info('[CURSO] ⏱ Iniciando proceso de registro', [
                'request' => $request->all(),
            ]);
            if ($request->has('file') && $request->input('file') === 'null') {
                $request->request->remove('file');
                Log::debug('[CURSO] 🧼 Campo file venía como "null" (string), eliminado antes de validar.');
            }
            // === [1] Validación y autorización ===
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
                    'mimes:pdf,jpg,jpeg,png',
                    'max:5120',
                ],
                'status'                   => ['required', 'integer'],
                'carpeta'                  => [
                    'nullable',
                    'string',
                    'max:255',
                ],
                'origen'                   => [
                    'required',
                    'integer',
                    'in:1,2',
                ],
                'id_opcion'                => ['nullable', 'integer'],
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
                    '[CURSO] ❌ Validación fallida',
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
            $origen     = (int) $request->input('origen');

            $permission = $origen === 1
                ? 'empleados.cursos.agregar_interno'
                : 'empleados.cursos.agregar_externo';

            if (! $this->permissions->canAdminGlobal(
                (int) $administrator->id,
                (int) $administrator->id_rol,
                $permission
            )) {
                return response()->json([
                    'status'     => false,
                    'code'       => 'PERMISSION_DENIED',
                    'message'    => 'No tienes permiso para agregar este tipo de curso.',
                    'permission' => $permission,
                ], 403);
            }

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

            // === [2] Procesar archivo si existe ===
            $newFileName     = null;
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
            if ($archivoRecibido) {
                try {
                    Log::info('[CURSO] 📎 Archivo detectado. Procesando...');

                    $randomString  = $this->generateRandomString();
                    $fileExtension = $request->file('file')->getClientOriginalExtension();
                    $newFileName   = "{$employeeId}_{$randomString}_{$origen}.{$fileExtension}";

                    Log::debug('[CURSO] 📝 Nombre generado para archivo', ['name' => $newFileName]);

                    $uploadRequest = new Request();
                    $uploadRequest->files->set('file', $request->file('file'));
                    $uploadFolder = $this->documentPaths->uploadFolder(
                        '_cursos',
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
                        Log::error('[CURSO] 🚫 Fallo al subir archivo', ['response' => $uploadResponse->getContent()]);
                        return response()->json(['error' => 'Error al subir el documento.'], 500);
                    }
                    $newFileName = $this->documentPaths->storedPath(
                        '_cursos',
                        $employee,
                        $newFileName
                    );
                    Log::info('[CURSO] ✅ Archivo subido con éxito');
                } catch (\Exception $e) {
                    Log::error('[CURSO] ⚠️ Excepción durante subida de archivo', ['exception' => $e->getMessage()]);
                    return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
                }
            } else {
                $newFileName = $employeeId . '_sin_curso_' . uniqid();
                Log::info('[CURSO] 🗂 No se recibió archivo. Se asigna nombre genérico', ['name' => $newFileName]);
            }

            // === [3] Crear registro en la base de datos ===
            try {
                Log::info('[CURSO] 💾 Insertando en base de datos...');
                $cursoEmpleado = CursoEmpleado::create([
                    'employee_id'              => $employeeId,
                    'name'                     => $newFileName,
                    'nameDocument'             => $request->input('name'),
                    'description'              => $request->input('description'),
                    'expiry_date'              => $request->input('expiry_date'),
                    'expiry_reminder'          => $expiryReminder,
                    'origen'                   => $origen,
                    'id_opcion'                => $request->input('id_opcion'),
                    'status'                   => $request->input('status'),
                    'share_scope'              => $shareScope,
                    'collaborator_can_replace' => $collaboratorCanReplace,
                    'creacion'                 => $now,
                    'edicion'                  => $now,
                ]);

                Log::info('[CURSO] ✅ Registro guardado', ['id' => $cursoEmpleado->id]);
            } catch (\Exception $e) {
                Log::error('[CURSO] ❌ Error al guardar en base de datos', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Error al guardar el curso.'], 500);
            }
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
                'origen',
                'status',
                'share_scope',
                'collaborator_can_replace',
                'creacion',
                'edicion',
            ];

            $this->auditoria->registrar([
                'id_portal'        => (int) $administrator->id_portal,
                'id_cliente'       => (int) $employee->id_cliente,

                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     => $actorNombre,

                'modulo'           => 'empleados',
                'entidad_tipo'     => 'curso',
                'entidad_id'       => (int) $cursoEmpleado->id,

                'accion'           => 'crear',
                'resultado'        => 'exitoso',
                'descripcion'      => 'Se creó un curso para el empleado.',

                'datos_anteriores' => null,
                'datos_nuevos'     => $cursoEmpleado->only($auditFields),

                'metadatos'        => [
                    'employee_id'              => $employeeId,
                    'categoria_almacenamiento' => '_cursos',
                    'archivo_recibido'         => $archivoRecibido,
                    'archivo_guardado'         => (string) $cursoEmpleado->name,
                    'nombre_original'          => $archivoOriginal,
                    'mime_type'                => $archivoMime,
                    'tamano_bytes'             => $archivoTamano,
                ],
            ], $request);
            return response()->json([
                'message' => 'Curso agregado exitosamente.',
                'curso'   => $cursoEmpleado,
            ], 201);

        } catch (\Exception $e) {
            Log::critical('[CURSO] 💥 Error inesperado', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Error inesperado al procesar la solicitud.'], 500);
        }
    }

    public function obtenerCursosPorEmpleado(Request $request)
    {
        $request->validate([
            'employee_id' => [
                'required',
                'integer',
                'min:1',
            ],
            'origen'      => [
                'required',
                'integer',
                'in:1,2,3',
            ],
            'status'      => [
                'nullable',
                'integer',
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

        $employee = $this->employeeScope->authorizeEmployee(
            $administrator,
            (int) $request->input('employee_id')
        );

        $employeeId = (int) $employee->id;
        $origen     = (int) $request->input('origen');
        $status     = $request->query('status');

        Log::info('📥 Recibida solicitud para obtener cursos', [
            'employee_id' => $employeeId,
            'origen'      => $origen,
            'status'      => $status,
        ]);

        // Construir la consulta con relaciones
        $query = CursoEmpleado::with('documentOption')
            ->where('employee_id', $employeeId)
            ->where('status', '!=', 999);

        // Aplicar filtro por origen, excepto si es 3 (todos)
        if ($origen != 3) {
            // Log::debug('🧭 Aplicando filtro por origen', ['origen' => $origen]);
            $query->where('origen', $origen);
        } else {
            // Log::debug('🧭 Mostrando todos los orígenes (origen == 3)');
        }

        // Aplicar filtro por status si se envía
        if (! is_null($status)) {
            Log::debug('🔎 Aplicando filtro por status', ['status' => $status]);
            $query->where('status', $status);
        }

        // Ejecutar consulta
        $cursos = $query->get();

        //Log::info('📄 Cursos encontrados:', ['total' => $cursos->count()]);

        // Si no hay resultados
        if ($cursos->isEmpty()) {
            Log::warning('⚠️ No se encontraron cursos para los criterios proporcionados.');
            return response()->json(['message' => 'No se encontraron cursos para el empleado.'], 404);
        }

        // Transformar datos
        $cursosTransformados = $cursos->map(function ($curso) {
            return [
                'id'                       => $curso->id,
                'employee_id'              => $curso->employee_id,
                'nameDocument'             => $curso->name,
                'optionName'               => $curso->documentOption ? $curso->documentOption->name : null,
                'description'              => $curso->description,
                'upload_date'              => $curso->edicion ? \Carbon\Carbon::parse($curso->edicion)->format('Y-m-d') : null,
                'expiry_date'              => $curso->expiry_date,
                'expiry_reminder'          => $curso->expiry_reminder,
                'status'                   => $curso->status,
                'origen'                   => $curso->origen,
                'name'                     => $curso->name,
                'nameAlterno'              => $curso->nameDocument,
                'daysRemaining'            => $curso->daysRemaining ?? null,
                'estado'                   => $curso->estado ?? '',
                'share_scope'              => (int) (
                    $curso->share_scope ?? 0
                ),
                'collaborator_can_replace' => (bool) (
                    $curso->collaborator_can_replace ?? false
                ),
            ];
        });

        // Log::info('✅ Cursos procesados correctamente.', ['ejemplo' => $cursosTransformados->first()]);

        return response()->json(['documentos' => $cursosTransformados], 200);
    }

    public function getEmpleadosConCursos(Request $request)
    {
        $request->validate([
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',
            'status'     => 'required|integer',
        ]);

        $id_portal  = $request->input('id_portal');
        $id_cliente = $request->input('id_cliente');
        $status     = $request->input('status');
        // Obtener todos los empleados con sus domicilios
        $empleados = Empleado::where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->where('status', $status)

            ->get();

        $resultados = [];

        foreach ($empleados as $empleado) {
            // Obtener documentos del empleado filtrando por origen
            $cursosOrigen1 = CursoEmpleado::where('employee_id', $empleado->id)
                ->where('origen', 1)
                ->where('status', '!=', 999)
                ->get();

            $cursosOrigen2 = CursoEmpleado::where('employee_id', $empleado->id)
                ->where('origen', 2)
                ->where('status', '!=', 999)
                ->get();

            // Determinar estado final
            $estadoFinal  = $this->determinarEstado($cursosOrigen1);
            $estadoFinal2 = $this->determinarEstado($cursosOrigen2);

            $statusOrigen1 = $this->checkDocumentStatus($cursosOrigen1);
            $statusOrigen2 = $this->checkDocumentStatus($cursosOrigen2);

            // Convertir el empleado a un array y agregar los statusDocuments
            $empleadoArray                  = $empleado->toArray();
            $empleadoArray['statusCursos1'] = $statusOrigen1;
            $empleadoArray['statusCursos2'] = $statusOrigen2;
            $empleadoArray['estadoCursos1'] = $estadoFinal;
            $empleadoArray['estadoCursos2'] = $estadoFinal2;
            $resultados[]                   = $empleadoArray;
        }
        //Log::info('Resultados de empleados con documentos: ' . print_r($resultados, true));
        //Log::info('Resultados de empleados con documentos:', $resultados);

        return response()->json($resultados); //Log::info('Resultados de empleados con documentos: ' . print_r($resultados, true));

    }
    private function determinarEstado($cursos)
    {
        $tieneRojo     = false;
        $tieneAmarillo = false;

        foreach ($cursos as $curso) {
            if ($curso->status == 3) {
                return 'rojo'; // Si hay al menos un rojo, el resultado es rojo
            }
            if ($curso->status == 2) {
                $tieneAmarillo = true; // Si hay amarillo, pero no rojo, será amarillo
            }
        }

        if ($tieneAmarillo) {
            return 'amarillo';
        }

        return 'verde'; // Si no hay ni rojo ni amarillo, es verde
    }

    private function checkDocumentStatus($documentos)
    {
        if ($documentos->isEmpty()) {
            return 'verde'; // Sin documentos, consideramos como verde
        }

        $tieneRojo     = false;
        $tieneAmarillo = false;

        foreach ($documentos as $documento) {
            // Calcular diferencia de días con respecto a la fecha actual
            $diasDiferencia = $this->calcularDiferenciaDias(now(), $documento->expiry_date);

            // Comprobamos el estado del documento
            if ($documento->expiry_reminder == 0) {
                continue; // No se requiere cálculo, se considera verde
            } elseif ($diasDiferencia <= $documento->expiry_reminder || $diasDiferencia < 0) {
                // Vencido o exactamente al límite
                $tieneRojo = true;
                break; // Prioridad alta, salimos del bucle
            } elseif ($diasDiferencia > $documento->expiry_reminder && $diasDiferencia <= ($documento->expiry_reminder + 7)) {
                // Se requiere atención, se considera amarillo
                $tieneAmarillo = true;
            }
        }

        // Determinamos el estado basado en las prioridades
        if ($tieneRojo) {
            return 'rojo';
        }

        if ($tieneAmarillo) {
            return 'amarillo';
        }

        return 'verde'; // Si no hay documentos en rojo o amarillo
    }

    private function calcularDiferenciaDias($fechaActual, $fechaExpiracion)
    {
        $fechaActual     = \Carbon\Carbon::parse($fechaActual);
        $fechaExpiracion = \Carbon\Carbon::parse($fechaExpiracion);

        // Calculamos la diferencia de días
        $diferenciaDias = $fechaExpiracion->diffInDays($fechaActual);

        // Ajustamos la diferencia para que sea negativa si la fecha de expiración ya ha pasado
        return $fechaExpiracion < $fechaActual ? -$diferenciaDias : $diferenciaDias;
    }

    // Método auxiliar para determinar el estado del curso
    private function getEstadoCurso($fechaExpiracion)
    {
        $fechaExpiracion = \Carbon\Carbon::parse($fechaExpiracion);
        $hoy             = \Carbon\Carbon::now();
        if (! $expiryDate) {
            return '';
        }
        if ($fechaExpiracion->isPast()) {
            return 'Expirado';
        } elseif ($fechaExpiracion->diffInDays($hoy) <= 5) {
            return 'Por expirar';
        } else {
            return 'Vigente';
        }
    }

    private function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", ceil($length / 10))), 1, $length);
    }
}
