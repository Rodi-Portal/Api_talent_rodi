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

        if (!$cliente) {
            return response()->json(['error' => 'Cliente no encontrado'], 404);
        }

        // Llama al método para obtener los cursos
        $cursos = $cliente->cursos->map(function ($curso) use ($cliente) {
            $estado = $this->getEstadoCurso1($curso->expiry_date);
            return [
                'curso' => $curso->name,
                'empleado' => 'ID: ' . $curso->empleado->id_empleado . ' - ' . $curso->empleado->nombre . ' ' . $curso->empleado->paterno . ' ' . $curso->empleado->materno ?? 'Sin asignar',
                'fecha_expiracion' => $curso->expiry_date,
                'estado' => $estado,
            ];
        });

        // Genera y devuelve el Excel con el nombre del cliente incluido
        return Excel::download(new CursosExport($cursos, $cliente->nombre), "reporte_cursos_{$clienteId}.xlsx");
    }

    private function getEstadoCurso1($expiryDate)
    {
        if (!$expiryDate) {
            return '';
        }

        $fechaExpiracion = Carbon::parse($expiryDate);
        $fechaHoy = Carbon::now();

        if ($fechaExpiracion->isPast()) {
            return 'Expirado';
        }

        return 'Vigente';
    }

    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'expiry_date' => 'required|date',
            'expiry_reminder' => 'nullable|integer',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'creacion' => 'required|string',
            'edicion' => 'required|string',
            'id_portal' => 'required|integer',
            'origen' => 'required|integer',
            'status' => 'required|integer',// Asegúrate de usar este campo según lo necesites
        ]);

        if ($validator->fails()) {
            Log::error('Errores de validación:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        // Log de los datos recibidos
        //Log::info('Datos recibidos en el store: ' . print_r($request->all(), true));
        //dd($request->all());

        $origen = $request->input('origen');
        // Preparar el nombre del archivo para la subida
        $employeeId = $request->input('employee_id');
        $randomString = $this->generateRandomString(); // Generar cadena aleatoria
        $fileExtension = $request->file('file')->getClientOriginalExtension(); // Obtener extensión del archivo
        $newFileName = "{$employeeId}_{$randomString}_{$origen}.{$fileExtension}";

        // Preparar la solicitud para la subida del archivo
        $uploadRequest = new Request();
        $uploadRequest->files->set('file', $request->file('file'));
        $uploadRequest->merge([
            'file_name' => $newFileName,
            'carpeta' => '_cursos', // Cambia esto a tu carpeta deseada
        ]);

        // Llamar a la función de upload
        $uploadResponse = app(DocumentController::class)->upload($uploadRequest); // Asegúrate de cambiar el nombre del controlador

        // Verificar si la subida fue exitosa
        if ($uploadResponse->getStatusCode() !== 200) {
            return response()->json(['error' => 'Error al subir el documento.'], 500);
        }

        // Crear un nuevo registro en la base de datos
        $cursoEmpleado = CursoEmpleado::create([
            'employee_id' => $request->input('employee_id'),
            'name' => $request->input('name'),
            'name_document' => $newFileName,
            'description' => $request->input('description'),
            'expiry_date' => $request->input('expiry_date'),
            'expiry_reminder' => $request->input('expiry_reminder'),
            'origen' => $origen,
            'creacion' => $request->input('creacion'),
            'edicion' => $request->input('edicion'),
            'id_opcion_exams' => $request->input('id_opcion_exams') ?? null,
            'status' => $request->input('status'), // Esto es opcional
        ]);

        // Log para verificar el curso registrado
        Log::info('Curso registrado:', ['curso' => $cursoEmpleado]);

        // Devolver una respuesta exitosa
        return response()->json([
            'message' => 'Curso agregado exitosamente.',
            'curso' => $cursoEmpleado,
        ], 201);
    }

    public function obtenerCursosPorEmpleado(Request $request)
    {
        // Obtener el ID del empleado y el origen del request
        $employeeId = $request->input('employee_id');
        $origen = $request->input('origen');

        // Validar que se proporcionen ambos parámetros
        $request->validate([
            'employee_id' => 'required|integer',
            'origen' => 'required|integer',
        ]);
        if ($origen == 3) {
            $cursos = CursoEmpleado::where('employee_id', $employeeId)
                ->get();
        } else {
            $cursos = CursoEmpleado::where('employee_id', $employeeId)
                ->where('origen', $origen)
                ->get();
        }
        // Obtener cursos del empleado con el origen especificado

        // Retornar los resultados
        return response()->json($cursos);
    }

    public function getEmpleadosConCursos(Request $request)
    {
        $request->validate([
            'id_portal' => 'required|integer',
            'id_cliente' => 'required|integer',
            'status' => 'required|integer',
        ]);

        $id_portal = $request->input('id_portal');
        $id_cliente = $request->input('id_cliente');
        $status = $request->input('status');
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

            $statusOrigen1 = $this->checkDocumentStatus($cursosOrigen1);
            $statusOrigen2 = $this->checkDocumentStatus($cursosOrigen2);

            // Convertir el empleado a un array y agregar los statusDocuments
            $empleadoArray = $empleado->toArray();
            $empleadoArray['statusCursos1'] = $statusOrigen1;
            $empleadoArray['statusCursos2'] = $statusOrigen2;

            $resultados[] = $empleadoArray;
        }
        //Log::info('Resultados de empleados con documentos: ' . print_r($resultados, true));
        //Log::info('Resultados de empleados con documentos:', $resultados);

        return response()->json($resultados); //Log::info('Resultados de empleados con documentos: ' . print_r($resultados, true));

    }

    private function checkDocumentStatus($documentos)
    {
        if ($documentos->isEmpty()) {
            return 'verde'; // Sin documentos, consideramos como verde
        }

        $tieneRojo = false;
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
        $fechaActual = \Carbon\Carbon::parse($fechaActual);
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
        $hoy = \Carbon\Carbon::now();
        if (!$expiryDate) {
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
