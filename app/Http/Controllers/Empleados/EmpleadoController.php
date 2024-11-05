<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\Evaluacion;
use App\Models\MedicalInfo;
use App\Models\ComentarioFormerEmpleado;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmpleadoController extends Controller
{
// obtine empleado con sus  domicilios  y con  su estatus  de documentos
    public function getEmpleadosConDocumentos(Request $request)
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
        $empleados = Empleado::with('domicilioEmpleado')
            ->where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->where('status', $status)
            ->get();

        $resultados = [];

        if ($status == 2) {
            foreach ($empleados as $empleado) {
                // Obtener el campo 'creacion' de ComentarioFormerEmpleado
                $comentario = ComentarioFormerEmpleado::where('id_empleado', $empleado->id)->first(['creacion']);

                // Convertir el empleado a un array
                $empleadoArray = $empleado->toArray();

                // Agregar el campo 'creacion' si existe
                $empleadoArray['fecha_salida'] = $comentario ? $comentario->creacion : null;

                // Agregar al resultado
                $resultados[] = $empleadoArray;
            }
        } else {
            foreach ($empleados as $empleado) {
                // Obtener documentos del empleado
                $documentos = DocumentEmpleado::where('employee_id', $empleado->id)->get();
                $cursos = CursoEmpleado::where('employee_id', $empleado->id)->get();

                $statusDocuments = $this->checkDocumentStatus($documentos);
                $statusCursos = $this->checkDocumentStatus($cursos);

                // Convertir el empleado a un array y agregar el statusDocuments
                $empleadoArray = $empleado->toArray();
                $empleadoArray['statusDocuments'] = $statusDocuments;
                $empleadoArray['statusCursos'] = $statusCursos;

                // Agregar al resultado
                $resultados[] = $empleadoArray;
            }
        }

        return response()->json($resultados);
    }

    private function checkDocumentStatus($documentos)
    {
        // Si $documentos es un solo documento, conviene usarlo directamente
        if (!is_array($documentos) && !$documentos instanceof \Illuminate\Support\Collection) {
            $documentos = [$documentos]; // Convertir a un array para la iteración
        }

        if (empty($documentos)) {
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

    public function getEmpleadosStatus(Request $request)
    {
        $request->validate([
            'id_portal' => 'required|integer',
        ]);

        $id_portal = $request->input('id_portal');
        $id_cliente = $request->input('id_cliente');


        // Obtener todos los empleados
        $empleados = Empleado::where('id_portal', $id_portal)
        ->where('id_cliente', $id_cliente) // Asegúrate de que $id_cliente esté definido
        ->get();

        $statusDocuments = 'verde'; // Asignar un estado inicial
        $statusCursos = 'verde'; // Asignar un estado inicial
        $statusEvaluaciones = 'verde'; // Nuevo estado para evaluaciones

        foreach ($empleados as $empleado) {
            // Obtener documentos y cursos del empleado
            $documentos = DocumentEmpleado::where('employee_id', $empleado->id)->get();
            $cursos = CursoEmpleado::where('employee_id', $empleado->id)->get();

            // Evaluar el estado de documentos y cursos usando la misma función
            $statusEmpleadoDocs = $this->checkDocumentStatus($documentos);
            $statusEmpleadoCursos = $this->checkDocumentStatus($cursos);

            // Actualizar el estado general para documentos
            if ($statusEmpleadoDocs === 'rojo') {
                $statusDocuments = 'rojo';
            } elseif ($statusEmpleadoDocs === 'amarillo' && $statusDocuments !== 'rojo') {
                $statusDocuments = 'amarillo';
            }

            // Actualizar el estado general para cursos
            if ($statusEmpleadoCursos === 'rojo') {
                $statusCursos = 'rojo';
            } elseif ($statusEmpleadoCursos === 'amarillo' && $statusCursos !== 'rojo') {
                $statusCursos = 'amarillo';
            }
        }

        // Obtener evaluaciones para el id_portal
        $evaluaciones = Evaluacion::where('id_portal', $id_portal)->get();
        foreach ($evaluaciones as $evaluacion) {
            $statusEvaluacionesPortal = $this->checkDocumentStatus($evaluacion);
            // Lógica para evaluar el estado de las evaluaciones
            if ($statusEvaluacionesPortal === 'rojo') {
                $statusEvaluaciones = 'rojo';
            } elseif ($statusEvaluacionesPortal === 'amarillo' && $statusEvaluaciones !== 'rojo') {
                $statusEvaluaciones = 'amarillo';
            }

        }

        $resultado = [
            'statusDocuments' => $statusDocuments,
            'statusCursos' => $statusCursos,
            'statusEvaluaciones' => $statusEvaluaciones,
        ];

        //  Log::info('Resultados de estados de documentos, cursos y evaluaciones: ' . print_r($resultado, true));

        return response()->json($resultado);
    }

    public function update(Request $request)
    {

        // Validación
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'edicion' => 'required|date',
            'domicilio_empleado.id' => 'required|integer',
            'domicilio_empleado.pais' => 'nullable|string|max:255',
            'domicilio_empleado.estado' => 'nullable|string|max:255',
            'domicilio_empleado.ciudad' => 'nullable|string|max:255',
            'domicilio_empleado.colonia' => 'nullable|string|max:255',
            'domicilio_empleado.calle' => 'nullable|string|max:255',
            'domicilio_empleado.cp' => 'nullable|integer',
            'domicilio_empleado.num_int' => 'nullable|string|max:255',
            'domicilio_empleado.num_ext' => 'nullable|string|max:255',
            // ... otros campos del empleado ...
        ]);

        if ($validator->fails()) {
            \Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Actualizar el empleado
            $empleado = Empleado::findOrFail($request->id);
            $empleado->update($request->only([
                'edicion',
                'nombre',
                'paterno',
                'materno',
                'telefono',
                'correo',
                'puesto',
                'rfc',
                'nss',
                'id_empleado',
                'curp',
                'foto',
                'fecha_nacimiento',
                'status',
                'eliminado',
            ]));

            // Actualizar el domicilio
            $domicilio = DomicilioEmpleado::findOrFail($request->domicilio_empleado['id']);
            $domicilio->update($request->domicilio_empleado);

            // Log de éxito
            //\Log::info('Empleado y domicilio actualizados correctamente.', ['empleado_id' => $empleado->id, 'domicilio_id' => $domicilio->id]);

            return response()->json(['message' => 'Empleado y domicilio actualizados correctamente.'], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Model not found:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Empleado o domicilio no encontrado.'], 404);
        } catch (Exception $e) {
            \Log::error('Error al actualizar:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocurrió un error al actualizar los datos.'], 500);
        }
    }

    public function show($id_empleado)
    {
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (!$medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }

        return response()->json($medicalInfo, 200);
    }

    public function getDocumentos($id_empleado)
    {
        $documentos = DocumentEmpleado::where('employee_id', $id_empleado)->get();

        if ($documentos->isEmpty()) {
            return response()->json(['message' => 'No se encontraron documentos.'], 404);
        }

        $status = $this->checkDocumentStatus($documentos);

        return response()->json([
            'status' => $status,
        ], 200);
    }

    // MEtodo  para  guardar  un empleado  desde  el modulo  employe

    public function store(Request $request)
    {
        // Validar los campos requeridos
        $validatedData = $request->validate([
            'creacion' => 'required|date',
            'edicion' => 'required|date',
            'id_portal' => 'required|integer',
            'id_usuario' => 'required|integer',
            'id_cliente' => 'required|integer',
            'id_empleado' => 'required|integer',
            'correo' => 'required|email',
            'fecha_nacimiento' => 'nullable|date',
            'curp' => 'required|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'nombre' => 'required|string',
            'paterno' => 'required|string',
            'materno' => 'nullable|string',
            'puesto' => 'nullable|string',
            'telefono' => 'required|string',

            // Validación para domicilio_empleado
            'domicilio_empleado.calle' => 'nullable|string',
            'domicilio_empleado.num_ext' => 'nullable|string',
            'domicilio_empleado.num_int' => 'nullable|string',
            'domicilio_empleado.colonia' => 'nullable|string',
            'domicilio_empleado.ciudad' => 'nullable|string',
            'domicilio_empleado.estado' => 'nullable|string',
            'domicilio_empleado.pais' => 'nullable|string',
            'domicilio_empleado.cp' => 'nullable|string',
        ]);

        $fechaNacimiento = Carbon::parse($validatedData['fecha_nacimiento']);
        $fechaCreacion = Carbon::parse($validatedData['creacion']);
        $edad = $fechaCreacion->diffInYears($fechaNacimiento);
        // Imprimir los datos en el log
        // Log::info('Datos recibidos para el registro de empleado: ' . print_r($validatedData, true));
        // Log::info('edad: ' . $edad);

        // Crear una transacción
        DB::beginTransaction();

        try {
            // Crear un registro en DomicilioEmpleado
            $domicilioData = [
                'calle' => $validatedData['domicilio_empleado']['calle'] ?? null,
                'num_ext' => $validatedData['domicilio_empleado']['num_ext'] ?? null,
                'num_int' => $validatedData['domicilio_empleado']['num_int'] ?? null,
                'colonia' => $validatedData['domicilio_empleado']['colonia'] ?? null,
                'ciudad' => $validatedData['domicilio_empleado']['ciudad'] ?? null,
                'estado' => $validatedData['domicilio_empleado']['estado'] ?? null,
                'pais' => $validatedData['domicilio_empleado']['pais'] ?? null,
                'cp' => $validatedData['domicilio_empleado']['cp'] ?? null,
            ];

            Log::info('Insertando en DomicilioEmpleado:', $domicilioData); // Log antes de insertar
            $domicilio = DomicilioEmpleado::create($domicilioData); // Guardar con create

            // Crear un nuevo empleado
            $empleadoData = [
                'creacion' => $validatedData['creacion'],
                'edicion' => $validatedData['edicion'],
                'id_portal' => $validatedData['id_portal'],
                'id_usuario' => $validatedData['id_usuario'],
                'id_cliente' => $validatedData['id_cliente'],
                'id_empleado' => $validatedData['id_empleado'],
                'correo' => $validatedData['correo'],
                'curp' => $validatedData['curp'],
                'nombre' => $validatedData['nombre'],
                'nss' => $validatedData['nss'],
                'rfc' => $validatedData['rfc'],
                'paterno' => $validatedData['paterno'],
                'materno' => $validatedData['materno'] ?? null,
                'puesto' => $validatedData['puesto'] ?? null,
                'fecha_nacimiento' => $validatedData['fecha_nacimiento'] ?? null,
                'telefono' => $validatedData['telefono'],
                'id_domicilio_empleado' => $domicilio->id, // Asignar el ID del domicilio creado
                'status' => 1,
                'eliminado' => 0,
            ];

            Log::info('Insertando en Empleado:', $empleadoData); // Log antes de insertar
            $empleado = Empleado::create($empleadoData); // Guardar con create

            // Crear un registro vacío en MedicalInfo
            $medicalInfoData = [
                'id_empleado' => $empleado->id,
                'creacion' => $validatedData['creacion'],
                'edicion' => $validatedData['creacion'],
                'edad' => $edad,
            ];

            Log::info('Insertando en MedicalInfo:', $medicalInfoData); // Log antes de insertar
            MedicalInfo::create($medicalInfoData); // Guardar con create

            // Confirmar la transacción
            DB::commit();

            return response()->json([
                'message' => 'Empleado registrado exitosamente.',
                'data' => $empleado,
            ], 201);
        } catch (\Exception $e) {
            // Revertir la transacción en caso de error
            DB::rollBack();
            Log::error('Error durante el registro:', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Error al registrar el empleado.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function checkEmail(Request $request)
    {
        // Validar que se reciba el correo en la solicitud
        $request->validate([
            'correo' => 'required|email',
        ]);

        // Verificar si el correo ya existe
        $exists = Empleado::where('correo', $request->correo)->exists();

        // Retornar respuesta
        return response()->json(['exists' => $exists]);
    }
}
