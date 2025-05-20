<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de tener esta línea arriba para utilizar Log

use App\Models\ComentarioFormerEmpleado;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use App\Models\Evaluacion;
use App\Models\ExamEmpleado;
use App\Models\MedicalInfo;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmpleadoController extends Controller
{
// obtine empleado con sus  domicilios  y con  su estatus  de documentos
    public function getEmpleadosConDocumentos(Request $request)
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

                // Log de lo que trae el comentario
                //Log::info('Comentario para empleado ' . $empleado->id . ': ', ['comentario' => $comentario]);

                // Obtener los documentos con status = 2
                $documentos = DocumentEmpleado::where('employee_id', $empleado->id)
                              ->where('status', 2)
                              ->get();

                // Log de los documentos obtenidos
                //Log::info('Documentos para empleado ' . $empleado->id . ': ', ['documentos' => $documentos]);

                // Verificar el estado de los documentos
                $statusDocuments = $this->checkDocumentStatus($documentos);

                // Log del estado de los documentos
                // Log::info('Estado de los documentos para empleado ' . $empleado->id . ': ', ['statusDocuments' => $statusDocuments]);

                // Convertir el empleado a un array
                $empleadoArray = $empleado->toArray();

                // Agregar el campo 'fecha_salida' si existe
                $empleadoArray['fecha_salida']    = $comentario ? $comentario->creacion : null;
                $empleadoArray['statusDocuments'] = $statusDocuments;

                // Log del empleado procesado
                // Log::info('Empleado procesado: ', ['empleado' => $empleadoArray]);

                // Agregar al resultado
                $resultados[] = $empleadoArray;
            }

            // Log final de los resultados
            // Log::info('Resultados finales: ', ['resultados' => $resultados]);

        } else {
            foreach ($empleados as $empleado) {
                // Obtener documentos del empleado
                $documentos = DocumentEmpleado::where('employee_id', $empleado->id)
                    ->get();
                $cursos     = CursoEmpleado::where('employee_id', $empleado->id)->get();
                $examenes   = ExamEmpleado::where('employee_id', $empleado->id)->get();
                $medico     = MedicalInfo::where('id_empleado', $empleado->id)->get();
                $campoExtra = EmpleadoCampoExtra::where('id_empleado', $empleado->id)->get();

                $statusExam = $this->checkDocumentStatus($examenes);

                $statusPadecimientos = $this->evaluarPadecimientos($medico);
                $statusDocuments     = $this->checkDocumentStatus($documentos);
                $statusCursos        = $this->checkDocumentStatus($cursos);
                $estadoDocumentos    = $this->obtenerEstado($documentos);
                $estadoExam          = $this->obtenerEstado($examenes);

                // Convertir el empleado a un array y agregar el statusDocuments
                $empleadoArray                    = $empleado->toArray();
                $empleadoArray['campoExtra']      = $campoExtra;
                $empleadoArray['statusMedico']    = $statusPadecimientos;
                $empleadoArray['statusDocuments'] = $statusDocuments;
                $empleadoArray['statusExam']      = $statusExam;
                $empleadoArray['estadoExam']      = $estadoExam;
                $empleadoArray['statusCursos']    = $statusCursos;
                $empleadoArray['estadoDocumento'] = $estadoDocumentos;
                // Agregar al resultado
                $resultados[] = $empleadoArray;
            }
        }

        return response()->json($resultados);
    }

    public function evaluarPadecimientos($medico)
    {
        // Obtener los datos del modelo MedicalInfo

        // Recorrer cada fila (registro)
        foreach ($medico as $registro) {
            // Evaluar los campos 'otros_padecimientos' y 'otros_padecimientos2'
            $campo1 = $registro->otros_padecimientos;
            $campo2 = $registro->otros_padecimientos2;

            // Verificar si los campos tienen un valor distinto a los valores no deseados
            if (
                ! in_array(is_null($campo1) ? null : strtolower($campo1), [null, 'no aplica', 'no', '', 'ninguna', 'ninguno', 'n/a'], true) ||
                ! in_array(is_null($campo2) ? null : strtolower($campo2), [null, 'no aplica', 'no', '', 'ninguna', 'ninguno', 'n/a'], true)
            ) {
                // Si alguno de los dos campos tiene un valor distinto a los permitidos, retorna 1
                return 1;
            }
        }

        // Si todos los registros tienen los valores que no deben, retornar 0
        return 0;
    }
    private function checkDocumentStatus($documentos)
    {
        // Si $documentos es un solo documento, conviene usarlo directamente
        if (! is_array($documentos) && ! $documentos instanceof \Illuminate\Support\Collection) {
            $documentos = [$documentos]; // Convertir a un array para la iteración
        }

        if (empty($documentos)) {
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
            } elseif ($diasDiferencia > $documento->expiry_reminder && $diasDiferencia <= ($documento->expiry_reminder + 5)) {
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

    public function getEmpleadosStatus(Request $request)
    {
        $request->validate([
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',

        ]);
        $status     = $request->input('status') ?? null;
        $id_portal  = $request->input('id_portal');
        $id_cliente = $request->input('id_cliente');
        if ($status != null) {
            $empleados = Empleado::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('status', 2) // Asegúrate de que $id_cliente esté definido
                ->get();
        } else {
            // Obtener todos los empleados
            $empleados = Empleado::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('status', 1) // Asegúrate de que $id_cliente esté definido
                ->get();
        }
        $statusDocuments    = 'verde'; // Asignar un estado inicial
        $statusCursos       = 'verde'; // Asignar un estado inicial
        $statusEvaluaciones = 'verde';
        $estadoDocumentos   = 'verde'; // Asignar un estado inicial
        $estadoCursos       = 'verde'; // Nuevo estado para evaluaciones
        if ($status != null) {
            $documentos = DocumentEmpleado::where('employee_id', $empleado->id)
                ->where('status', 2)
                ->get();

            $statusEmpleadoDocs = $this->checkDocumentStatus($documentos);
            if ($statusEmpleadoDocs === 'rojo') {
                $statusDocuments = 'rojo';
            } elseif ($statusEmpleadoDocs === 'amarillo' && $statusDocuments !== 'rojo') {
                $statusDocuments = 'amarillo';
            }
            $resultado = [
                'statusDocuments' => $statusDocuments,
            ];

        } else {
            foreach ($empleados as $empleado) {
                // Obtener documentos y cursos del empleado
                $documentos = DocumentEmpleado::where('employee_id', $empleado->id)->get();
                $cursos     = CursoEmpleado::where('employee_id', $empleado->id)->get();

                $estadoDocumentos1 = $this->obtenerEstado($documentos);
                $estadoCursos1     = $this->obtenerEstado($cursos);

                // Evaluamos el estado de los documentos y cursos
                if ($estadoDocumentos1 === 'rojo') {
                    $estadoDocumentos = 'rojo';
                } elseif ($estadoDocumentos1 === 'amarillo') {
                    $estadoDocumentos = 'amarillo';
                }

                if ($estadoCursos1 === 'rojo') {
                    $estadoCursos = 'rojo';
                } elseif ($estadoCursos1 === 'amarillo') {
                    $estadoCursos = 'amarillo';
                }

                // Evaluar el estado de documentos y cursos usando la misma función
                $statusEmpleadoDocs   = $this->checkDocumentStatus($documentos);
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
            $evaluaciones = Evaluacion::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente) // Asegúrate de que $id_cliente esté definido
                ->get();
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
                'statusDocuments'    => $statusDocuments,
                'statusCursos'       => $statusCursos,
                'statusEvaluaciones' => $statusEvaluaciones,
                'estadoDocumentos'   => $estadoDocumentos,
                'estadoCursos'       => $estadoCursos,
            ];
        }

        //Log::info('Resultados de estados de documentos, cursos y evaluaciones: ' . print_r($resultado, true));

        return response()->json($resultado);
    }

    public function update(Request $request)
    {

        // Validación
        $validator = Validator::make($request->all(), [
            'id'                         => 'required|integer',
            'edicion'                    => 'required|date',
            'domicilio_empleado.id'      => 'required|integer',
            'domicilio_empleado.pais'    => 'nullable|string|max:255',
            'domicilio_empleado.estado'  => 'nullable|string|max:255',
            'domicilio_empleado.ciudad'  => 'nullable|string|max:255',
            'domicilio_empleado.colonia' => 'nullable|string|max:255',
            'domicilio_empleado.calle'   => 'nullable|string|max:255',
            'domicilio_empleado.cp'      => 'nullable|string|max:25',
            'domicilio_empleado.num_int' => 'nullable|string|max:255',
            'domicilio_empleado.num_ext' => 'nullable|string|max:255',
            // ... otros campos del empleado ...
        ]);

        if ($validator->fails()) {
            //  \Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Actualizar el empleado
            $empleado = Empleado::findOrFail($request->id);
            $empleado->update($request->only([
                'creacion',
                'edicion',
                'nombre',
                'paterno',
                'materno',
                'telefono',
                'correo',
                'departamento',
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
            if ($request->filled('campoExtra')) {
                foreach ($request->campoExtra as $campo) {
                    if (isset($campo['id'])) {
                        // Actualizar campo existente
                        $campoExistente = EmpleadoCampoExtra::where('id_empleado', $empleado->id)
                            ->where('id', $campo['id'])
                            ->first();

                        if ($campoExistente) {
                            $campoExistente->update([
                                'nombre' => $campo['nombre'],
                                'valor'  => $campo['valor'],
                            ]);
                        }
                    } else {
                        // Crear nuevo campo
                        EmpleadoCampoExtra::create([
                            'id_empleado' => $empleado->id,
                            'nombre'      => $campo['nombre'],
                            'valor'       => $campo['valor'],
                        ]);
                    }
                }
            }

            // Log de éxito
            //\Log::info('Empleado y domicilio actualizados correctamente.', ['empleado_id' => $empleado->id, 'domicilio_id' => $domicilio->id]);

            return response()->json(['message' => 'Empleado y domicilio actualizados correctamente.'], 200);

        } catch (ModelNotFoundException $e) {
            // \Log::error('Model not found:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Empleado o domicilio no encontrado.'], 404);
        } catch (Exception $e) {
            //\Log::error('Error al actualizar:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocurrió un error al actualizar los datos.'], 500);
        }
    }

    public function show($id_empleado)
    {
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (! $medicalInfo) {
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
            'creacion'                   => 'required|date',
            'edicion'                    => 'required|date',
            'id_portal'                  => 'required|integer',
            'id_usuario'                 => 'required|integer',
            'id_cliente'                 => 'required|integer',
            'id_empleado'                => 'nullable|integer',
            'correo'                     => 'nullable|email',
            'fecha_nacimiento'           => 'nullable|date',
            'curp'                       => 'nullable|string',
            'rfc'                        => 'nullable|string',
            'nss'                        => 'nullable|string',
            'nombre'                     => 'required|string',
            'paterno'                    => 'required|string',
            'materno'                    => 'nullable|string',
            'departamento'               => 'nullable|string',  
            'puesto'                     => 'nullable|string',
            'telefono'                   => 'nullable|string',

            // Validación para domicilio_empleado
            'domicilio_empleado.calle'   => 'nullable|string',
            'domicilio_empleado.num_ext' => 'nullable|string',
            'domicilio_empleado.num_int' => 'nullable|string',
            'domicilio_empleado.colonia' => 'nullable|string',
            'domicilio_empleado.ciudad'  => 'nullable|string',
            'domicilio_empleado.estado'  => 'nullable|string',
            'domicilio_empleado.pais'    => 'nullable|string',
            'domicilio_empleado.cp'      => 'nullable|string',

            // Validación para campos extra
            'extraFields'                => 'nullable|array',
            'extraFields.*.nombre'       => 'required|string',
            'extraFields.*.valor'        => 'required|string',
        ]);

        $fechaNacimiento = '';
        $fechaCreacion   = '';
        $edad            = null;
        if (isset($validatedData['fecha_nacimiento']) && $validatedData['fecha_nacimiento'] != '') {
            $fechaNacimiento = Carbon::parse($validatedData['fecha_nacimiento']);
            $fechaCreacion   = Carbon::parse($validatedData['creacion']);
            $edad            = $fechaCreacion->diffInYears($fechaNacimiento);
        }

        $existeEmpleado = Empleado::where('nombre', $validatedData['nombre'])
            ->where('paterno', $validatedData['paterno'])
            ->where('id_portal', $validatedData['id_portal'])
            ->where('id_cliente', $validatedData['id_cliente'])
            ->where('eliminado', 0) // Si usas soft-delete lógico
            ->first();

        if ($existeEmpleado) {
            return response()->json([
                'message'            => 'El empleado ya existe en este portal y cliente.',
                'empleado_existente' => $existeEmpleado,
            ], 409);
        }

        // Imprimir los datos en el log
        // Log::info('Datos recibidos para el registro de empleado: ' . print_r($validatedData, true));
        // Log::info('edad: ' . $edad);

        // Crear una transacción
        DB::beginTransaction();

        try {
            // Crear un registro en DomicilioEmpleado
            $domicilioData = [
                'calle'   => $validatedData['domicilio_empleado']['calle'] ?? null,
                'num_ext' => $validatedData['domicilio_empleado']['num_ext'] ?? null,
                'num_int' => $validatedData['domicilio_empleado']['num_int'] ?? null,
                'colonia' => $validatedData['domicilio_empleado']['colonia'] ?? null,
                'ciudad'  => $validatedData['domicilio_empleado']['ciudad'] ?? null,
                'estado'  => $validatedData['domicilio_empleado']['estado'] ?? null,
                'pais'    => $validatedData['domicilio_empleado']['pais'] ?? null,
                'cp'      => $validatedData['domicilio_empleado']['cp'] ?? null,
            ];

                                                                    // Log::info('Insertando en DomicilioEmpleado:', $domicilioData); // Log antes de insertar
            $domicilio = DomicilioEmpleado::create($domicilioData); // Guardar con create

            // Crear un nuevo empleado
            $empleadoData = [
                'creacion'              => $validatedData['creacion'],
                'edicion'               => $validatedData['edicion'],
                'id_portal'             => $validatedData['id_portal'],
                'id_usuario'            => $validatedData['id_usuario'],
                'id_cliente'            => $validatedData['id_cliente'],
                'id_empleado'           => $validatedData['id_empleado'] ?? null,
                'correo'                => $validatedData['correo'] ?? null,
                'curp'                  => $validatedData['curp'] ?? null,
                'nombre'                => $validatedData['nombre'],
                'nss'                   => $validatedData['nss'] ?? null,
                'rfc'                   => $validatedData['rfc'] ?? null,
                'paterno'               => $validatedData['paterno'],
                'materno'               => $validatedData['materno'] ?? null,
                'departamento'          => $validatedData['departamento'] ?? null,
                'puesto'                => $validatedData['puesto'] ?? null,
                'fecha_nacimiento'      => $validatedData['fecha_nacimiento'] ?? null,
                'telefono'              => $validatedData['telefono'] ?? null,
                'id_domicilio_empleado' => $domicilio->id, // Asignar el ID del domicilio creado
                'status'                => 1,
                'eliminado'             => 0,
            ];

                                                         //Log::info('Insertando en Empleado:', $empleadoData); // Log antes de insertar
            $empleado = Empleado::create($empleadoData); // Guardar con create

            // Crear los campos extra relacionados con el empleado, si existen
            if (isset($validatedData['extraFields']) && count($validatedData['extraFields']) > 0) {
                foreach ($validatedData['extraFields'] as $campoExtra) {
                    EmpleadoCampoExtra::create([
                        'id_empleado' => $empleado->id,
                        'nombre'      => $campoExtra['nombre'],
                        'valor'       => $campoExtra['valor'],
                    ]);
                }
            }
            // Crear un registro vacío en MedicalInfo
            $medicalInfoData = [
                'id_empleado' => $empleado->id,
                'creacion'    => $validatedData['creacion'],
                'edicion'     => $validatedData['creacion'],
                'edad'        => $edad ?? null,
            ];

                                                   // Log::info('Insertando en MedicalInfo:', $medicalInfoData); // Log antes de insertar
            MedicalInfo::create($medicalInfoData); // Guardar con create

            // Confirmar la transacción
            DB::commit();

            return response()->json([
                'message' => 'Empleado registrado exitosamente.',
                'data'    => $empleado,
            ], 201);
        } catch (\Exception $e) {
            // Revertir la transacción en caso de error
            DB::rollBack();
            //Log::error('Error durante el registro:', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Error al registrar el empleado.',
                'error'   => $e->getMessage(),
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

    public function deleteEmpleado($id)
    {
        // Intentar encontrar el empleado por su ID
        $empleado = Empleado::find($id);

        // Si no se encuentra el empleado, retornar error
        if (! $empleado) {
            return response()->json([
                'message' => 'Empleado no encontrado.',
            ], 404); // Error 404 si no existe el empleado
        }

        try {
            // Intentar eliminar al empleado
            $empleado->delete();

            // Responder con éxito
            return response()->json([
                'message' => 'Empleado eliminado correctamente.',
            ], 200); // Éxito 200
        } catch (\Exception $e) {
            // Si ocurre un error, capturarlo y responder
            return response()->json([
                'message' => 'Hubo un error al eliminar al empleado.',
                'error'   => $e->getMessage(),
            ], 500); // Error 500 si no se puede eliminar
        }
    }

    // Función para obtener el estado de los documentos
    public function obtenerEstado($items)
    {
        // Recorrer los items (documentos o cursos)
        foreach ($items as $item) {
            // Imprimir el estado de cada documento en el log
            // Log::info('Documento ID: ' . $item->id . ' - Estado: ' . $item->status);

            // Si encontramos un estado 3 (rojo), retornamos rojo
            if ($item->status == 3) {
                // Log::info('Estado del documento ' . $item->id . ' es ROJO');
                return 'rojo';
            }
        }

        // Si no hay estado 3, pero encontramos un estado 2 (amarillo), retornamos amarillo
        foreach ($items as $item) {
            //7Log::info('Documento ID: ' . $item->id . ' - Estado: ' . $item->status);

            if ($item->status == 2) {
                //Log::info('Estado del documento ' . $item->id . ' es AMARILLO');
                return 'amarillo';
            }
        }

        // Si no hay estado 3 ni 2, retornamos verde
        return 'verde';
    }

    public function eliminarCampoExtra($id)
    {
        try {
                                                          // Buscar el campo extra por ID
            $campo = EmpleadoCampoExtra::findOrFail($id); // Asegúrate de tener este modelo

            // Eliminar el campo
            $campo->delete();

            return response()->json([
                'message' => 'Campo extra eliminado correctamente.',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ocurrió un error al eliminar el campo extra.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
