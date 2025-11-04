<?php
namespace App\Http\Controllers\Empleados;

use App\Exports\CursosExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\ClienteTalent;
use App\Models\CursoEmpleado;
use App\Models\Empleado;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http; // Cambia esto al nombre correcto de tu controlador
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class CursosController extends Controller
{

    public function exportCursosPorCliente($clienteId)
    {

        // Limpiar cachés de manera temporal

        // Llama al método para obtener los datos del cliente
        $cliente = ClienteTalent::with('cursos.empleado')->find($clienteId);

        if (! $cliente) {
            return response()->json(['error' => 'Cliente no encontrado'], 404);
        }

        // Llama al método para obtener los cursos
        $cursos = $cliente->cursos->map(function ($curso) use ($cliente) {
            $estado = $this->getEstadoCurso1($curso->expiry_date);
            return [
                'curso'            => $curso->nameDocument,
                'empleado'         => 'ID: ' . $curso->empleado->id_empleado . ' - ' . $curso->empleado->nombre . ' ' . $curso->empleado->paterno . ' ' . $curso->empleado->materno ?? 'Sin asignar',
                'fecha_expiracion' => $curso->expiry_date,
                'estado'           => $estado,
            ];
        });

        // Genera y devuelve el Excel con el nombre del cliente incluido
        return Excel::download(new CursosExport($cursos, $cliente->nombre), "reporte_cursos_{$clienteId}.xlsx");
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
            // === [1] Validación de datos ===
            $validator = Validator::make($request->all(), [
                'employee_id'     => 'required|integer',
                'name'            => 'required|string|max:255',
                'description'     => 'nullable|string|max:500',
                'expiry_date'     => 'nullable|date',
                'expiry_reminder' => 'nullable|integer',
                'file'            => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
                'id_portal'       => 'required|integer',
                'status'          => 'required|integer',
                'carpeta'         => 'nullable|string|max:255',
                'origen'          => 'required|integer',
                'id_opcion'       => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                Log::warning('[CURSO] ❌ Validación fallida', $validator->errors()->toArray());
                return response()->json($validator->errors(), 422);
            }

            // === [2] Procesar archivo si existe ===
            $newFileName = null;

            if ($request->hasFile('file') && $request->file('file')->isValid()) {
                try {
                    Log::info('[CURSO] 📎 Archivo detectado. Procesando...');

                    $employeeId    = $request->input('employee_id');
                    $origen        = $request->input('origen');
                    $randomString  = $this->generateRandomString();
                    $fileExtension = $request->file('file')->getClientOriginalExtension();
                    $newFileName   = "{$employeeId}_{$randomString}_{$origen}.{$fileExtension}";

                    Log::debug('[CURSO] 📝 Nombre generado para archivo', ['name' => $newFileName]);

                    $uploadRequest = new Request();
                    $uploadRequest->files->set('file', $request->file('file'));
                    $uploadRequest->merge([
                        'file_name' => $newFileName,
                        'carpeta'   => $request->input('carpeta') ?? '_cursos',
                    ]);

                    $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

                    if ($uploadResponse->getStatusCode() !== 200) {
                        Log::error('[CURSO] 🚫 Fallo al subir archivo', ['response' => $uploadResponse->getContent()]);
                        return response()->json(['error' => 'Error al subir el documento.'], 500);
                    }

                    Log::info('[CURSO] ✅ Archivo subido con éxito');
                } catch (\Exception $e) {
                    Log::error('[CURSO] ⚠️ Excepción durante subida de archivo', ['exception' => $e->getMessage()]);
                    return response()->json(['error' => 'Ocurrió un error al subir el archivo.'], 500);
                }
            } else {
                $newFileName = $request->input('employee_id').'_sin_curso_' . uniqid();
                Log::info('[CURSO] 🗂 No se recibió archivo. Se asigna nombre genérico', ['name' => $newFileName]);
            }

            // === [3] Crear registro en la base de datos ===
            try {
                Log::info('[CURSO] 💾 Insertando en base de datos...');
                $cursoEmpleado = CursoEmpleado::create([
                    'employee_id'     => $request->input('employee_id'),
                    'name'            => $newFileName,
                    'nameDocument'    => $request->input('name'),
                    'description'     => $request->input('description'),
                    'expiry_date'     => $request->input('expiry_date'),
                    'expiry_reminder' => $request->input('expiry_reminder'),
                    'origen'          => $request->input('origen'),
                    'id_opcion'       => $request->input('id_opcion'),
                    'status'          => $request->input('status'),
                    'creacion'        => $now,
                    'edicion'         => $now,
                ]);

                Log::info('[CURSO] ✅ Registro guardado', ['id' => $cursoEmpleado->id]);
            } catch (\Exception $e) {
                Log::error('[CURSO] ❌ Error al guardar en base de datos', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Error al guardar el curso.'], 500);
            }

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
        // Validar que se proporcionen los parámetros requeridos
        $request->validate([
            'employee_id' => 'required|integer',
            'origen'      => 'required|integer',
        ]);

        $employeeId = $request->input('employee_id');
        $origen     = $request->input('origen');
        $status     = $request->query('status');

        Log::info('📥 Recibida solicitud para obtener cursos', [
            'employee_id' => $employeeId,
            'origen'      => $origen,
            'status'      => $status,
        ]);

        // Construir la consulta con relaciones
        $query = CursoEmpleado::with('documentOption')
            ->where('employee_id', $employeeId);

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
                'id'              => $curso->id,
                'employee_id'     => $curso->employee_id,
                'nameDocument'    => $curso->name,
                'optionName'      => $curso->documentOption ? $curso->documentOption->name : null,
                'description'     => $curso->description,
                'upload_date'     => $curso->edicion ? \Carbon\Carbon::parse($curso->edicion)->format('Y-m-d') : null,
                'expiry_date'     => $curso->expiry_date,
                'expiry_reminder' => $curso->expiry_reminder,
                'status'          => $curso->status,
                'origen'          => $curso->origen,
                'name'            => $curso->name,
                'nameAlterno'     => $curso->nameDocument,
                'daysRemaining'   => $curso->daysRemaining ?? null,
                'estado'          => $curso->estado ?? '',
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
            $cursosOrigen1 = CursoEmpleado::where('employee_id', $empleado->id)->where('origen', 1)->get();
            $cursosOrigen2 = CursoEmpleado::where('employee_id', $empleado->id)->where('origen', 2)->get();

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
