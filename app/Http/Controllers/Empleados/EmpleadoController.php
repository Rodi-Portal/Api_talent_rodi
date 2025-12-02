<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de tener esta línea arriba para utilizar Log

use App\Models\CursoEmpleado;
use App\Models\Departamento;
use App\Models\DocumentEmpleado;
use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use App\Models\Evaluacion;
use App\Models\ExamEmpleado;
use App\Models\MedicalInfo; // <<<<<< necesario
use App\Models\PuestoEmpleado;
use Carbon\Carbon;
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
        $empleados = Empleado::with([
            'domicilioEmpleado',
            'camposExtra',
            'depto',     // 👍 cargar nombre del departamento
            'puestoRel', // 👍 cargar nombre del puesto
        ])
            ->where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->where('status', $status)
            ->get();
        $resultados = [];

        if ($status == 2) {

            foreach ($empleados as $empleado) {
                                                                                      // Obtener el campo 'creacion' de ComentarioFormerEmpleado
                $comentario = \App\Models\ComentarioFormerEmpleado::on('portal_main') // fuerza la conexión del modelo
                    ->where('id_empleado', $empleado->id)
                    ->whereNotNull('fecha_salida_reingreso')
                    ->whereRaw("TRIM(fecha_salida_reingreso) <> ''")
                    ->where('fecha_salida_reingreso', '!=', '0000-00-00')
                // si lo que te interesa es la última FECHA de salida/reingreso, ordena por esa columna:
                    ->orderByDesc('fecha_salida_reingreso')
                // alternativamente, si quieres el último registro creado usa ->orderByDesc('id') o ->latest('creacion')
                    ->first(['id', 'creacion', 'titulo', 'comentario', 'fecha_salida_reingreso']);

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
                $empleadoArray                 = $empleado->toArray();
                $empleadoArray['departamento'] = $empleado->depto->nombre ?? null;
                $empleadoArray['puesto']       = $empleado->puestoRel->nombre ?? null;
                            unset($empleadoArray['id_departamento'], $empleadoArray['id_puesto']);

                if (isset($empleadoArray['domicilio_empleado']) && is_array($empleadoArray['domicilio_empleado'])) {
                    $camposPermitidos = ['pais', 'estado', 'ciudad', 'colonia', 'calle', 'cp', 'num_int', 'num_ext'];

                    foreach ($empleadoArray['domicilio_empleado'] as $campo => $valor) {
                        if (in_array($campo, $camposPermitidos)) {
                            $empleadoArray[$campo] = $valor;
                        }
                    }
                }
                // Agregar el campo 'fecha_salida' si existe
                $empleadoArray['fecha_salida']    = $comentario ? $comentario->fecha_salida_reingreso : null;
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
                $empleadoArray = $empleado->toArray();

                if (isset($empleadoArray['domicilio_empleado']) && is_array($empleadoArray['domicilio_empleado'])) {
                    $camposPermitidos = ['pais', 'estado', 'ciudad', 'colonia', 'calle', 'cp', 'num_int', 'num_ext'];

                    foreach ($empleadoArray['domicilio_empleado'] as $campo => $valor) {
                        if (in_array($campo, $camposPermitidos)) {
                            $empleadoArray[$campo] = $valor;
                        }
                    }
                }

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

        $controller = $this;

        usort($resultados, function ($a, $b) use ($controller) {
            $prioridadA = $controller->obtenerPrioridad($a);
            $prioridadB = $controller->obtenerPrioridad($b);
            return $prioridadA <=> $prioridadB;
        });
        // Log::info("Resultados ordenados:\n" . json_encode($resultados, JSON_PRETTY_PRINT));

        // O también puedes loguear las columnas si quieres
        // Log::info("Columnas únicas:\n" . json_encode($this->extraerColumnasUnicas($resultados), JSON_PRETTY_PRINT));

        return response()->json([
            'empleados' => $resultados,
            'columnas'  => $this->extraerColumnasUnicas($resultados),
        ]);
    }
    private function extraerColumnasUnicas(array $empleados): array
    {
        $columnas = [];
        $excluir  = [
            'foto', 'statusMedico', 'statusDocuments', 'statusExam',
            'estadoExam', 'statusCursos', 'estadoDocumento',
            'id_domicilio_empleado', 'creacion', 'edicion',
            'id_portal', 'id_cliente', 'id_usuario', 'id', 'Id', 'campoExtra', 'eliminado', 'status', 'paterno', 'materno', 'nombre', 'id_bolsa','id_departamento','id_puesto',
        ];

        foreach ($empleados as $empleado) {
            foreach ($empleado as $clave => $valor) {
                // Campos directos
                if (! in_array($clave, $columnas) && ! in_array($clave, $excluir) && ! is_array($valor)) {
                    $columnas[] = $clave;
                }

                // Campos personalizados desde campoExtra
                if ($clave === 'campoExtra' && is_iterable($valor)) {
                    foreach ($valor as $campo) {
                        if (isset($campo['nombre']) && ! in_array($campo['nombre'], $columnas)) {
                            $columnas[] = $campo['nombre'];
                        }
                    }
                }

                // Relación: domicilio_empleado (aplanado al nivel superior)
                if ($clave === 'domicilio_empleado' && is_array($valor)) {
                    foreach ($valor as $subclave => $subvalor) {
                        if (! in_array($subclave, $excluir) && ! in_array($subclave, $columnas)) {
                            $columnas[] = $subclave; // 👈 sin prefijo
                        }
                    }
                }

            }
        }

        return $columnas;
    }
    public function obtenerPrioridad(array $empleado): int
    {
        $statusDocuments = $empleado['statusDocuments'] ?? 'verde';
        $statusExam      = $empleado['statusExam'] ?? 'verde';

        if ($statusDocuments === 'rojo') {
            return 1;
        }

        if ($statusDocuments === 'amarillo') {
            return 2;
        }

        if ($statusDocuments === 'verde') {
            if ($statusExam === 'rojo') {
                return 3;
            }

            if ($statusExam === 'amarillo') {
                return 4;
            }
        }

        return 5; // todo verde o sin datos
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
    /*
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
    }*/
    public function getEmpleadosStatus(Request $request)
    {
        $request->validate([
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',
        ]);

        $status     = $request->input('status'); // puede venir null
        $id_portal  = (int) $request->input('id_portal');
        $id_cliente = (int) $request->input('id_cliente');

        $isEx = ((string) $status === '2'); // ex-empleados

        // Carga de empleados según regla dada:
        $empleados = Empleado::where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->where('status', $isEx ? 2 : 1)
            ->get();

        // Defaults
        $statusDocuments    = 'verde';
        $statusCursos       = 'verde';
        $statusEvaluaciones = 'verde';
        $estadoDocumentos   = 'verde';
        $estadoCursos       = 'verde';

        // Si no hay empleados, regresa estados “verde”
        if ($empleados->isEmpty()) {
            return response()->json(
                $isEx
                    ? ['statusDocuments' => $statusDocuments]
                    : [
                    'statusDocuments'    => $statusDocuments,
                    'statusCursos'       => $statusCursos,
                    'statusEvaluaciones' => $statusEvaluaciones,
                    'estadoDocumentos'   => $estadoDocumentos,
                    'estadoCursos'       => $estadoCursos,
                ]
            );
        }

        if ($isEx) {
            // === EX-EMPLEADOS ===
            // Regla: solo documentos “de salida” (status=2). Si hay vencidos, marcar en rojo/amarillo según checkDocumentStatus
            foreach ($empleados as $empleado) {
                $documentosSalida = DocumentEmpleado::where('employee_id', $empleado->id)
                    ->where('status', 2) // documentos de salida
                                     // ->whereDate('fecha_vencimiento', '<', now()) // <-- si tienes un campo de vencimiento, descomenta/ajusta
                    ->get();

                $estado = $this->checkDocumentStatus($documentosSalida); // debe devolver rojo/amarillo/verde

                if ($estado === 'rojo') {
                    $statusDocuments = 'rojo';
                    break; // peor caso, podemos cortar
                } elseif ($estado === 'amarillo' && $statusDocuments !== 'rojo') {
                    $statusDocuments = 'amarillo';
                }
            }

            return response()->json([
                'statusDocuments' => $statusDocuments,
            ]);
        }

        // === EMPLEADOS ACTIVOS (status=1) ===
        foreach ($empleados as $empleado) {
            $documentos = DocumentEmpleado::where('employee_id', $empleado->id)->get();
            $cursos     = CursoEmpleado::where('employee_id', $empleado->id)->get();

            // Estados “visibles” por módulo
            $eDocs  = $this->obtenerEstado($documentos);
            $eCurso = $this->obtenerEstado($cursos);

            if ($eDocs === 'rojo') {
                $estadoDocumentos = 'rojo';
            } elseif ($eDocs === 'amarillo' && $estadoDocumentos !== 'rojo') {
                $estadoDocumentos = 'amarillo';
            }

            if ($eCurso === 'rojo') {
                $estadoCursos = 'rojo';
            } elseif ($eCurso === 'amarillo' && $estadoCursos !== 'rojo') {
                $estadoCursos = 'amarillo';
            }

            // Estados agregados “status*”
            $sDocs  = $this->checkDocumentStatus($documentos);
            $sCurso = $this->checkDocumentStatus($cursos);

            if ($sDocs === 'rojo') {
                $statusDocuments = 'rojo';
            } elseif ($sDocs === 'amarillo' && $statusDocuments !== 'rojo') {
                $statusDocuments = 'amarillo';
            }

            if ($sCurso === 'rojo') {
                $statusCursos = 'rojo';
            } elseif ($sCurso === 'amarillo' && $statusCursos !== 'rojo') {
                $statusCursos = 'amarillo';
            }
        }

        // Evaluaciones a nivel portal/cliente (los ex no llegan aquí)
        $evaluaciones = Evaluacion::where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->get();

        $sEval = $this->checkDocumentStatus($evaluaciones);
        if ($sEval === 'rojo') {
            $statusEvaluaciones = 'rojo';
        } elseif ($sEval === 'amarillo' && $statusEvaluaciones !== 'rojo') {
            $statusEvaluaciones = 'amarillo';
        }

        return response()->json([
            'statusDocuments'    => $statusDocuments,
            'statusCursos'       => $statusCursos,
            'statusEvaluaciones' => $statusEvaluaciones,
            'estadoDocumentos'   => $estadoDocumentos,
            'estadoCursos'       => $estadoCursos,
        ]);
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

    // Método para guardar un empleado desde el módulo employee
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

            // Campos para catálogos (opcionales)
            'id_portal'                  => 'nullable|integer',
            'id_cliente'                 => 'nullable|integer',
            'id_departamento'            => 'nullable|integer|min:1',
            'departamento'               => 'nullable|string|max:120',
            'id_puesto'                  => 'nullable|integer|min:1',
            'puesto'                     => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            return DB::transaction(function () use ($request) {

                // === Empleado base
                $empleado = Empleado::findOrFail($request->id);

                // Ámbito para catálogos: request o valores actuales del empleado
                $portal  = (int) ($request->input('id_portal', $empleado->id_portal ?? 0));
                $cliente = (int) ($request->input('id_cliente', $empleado->id_cliente ?? 0));

                // Normaliza textos de request (evita strings de solo espacios)
                $depTxtReq    = trim((string) ($request->input('departamento') ?? ''));
                $puestoTxtReq = trim((string) ($request->input('puesto') ?? ''));

                // === Resolver DEPARTAMENTO
                if ($request->filled('id_departamento')) {
                    $empleado->id_departamento = (int) $request->input('id_departamento');
                    if ($d = Departamento::find($empleado->id_departamento)) {
                        $empleado->departamento = $d->nombre; // sync legacy opcional
                    }
                } elseif ($depTxtReq !== '') {
                    $dep = Departamento::firstOrCreate(
                        ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => $depTxtReq],
                        ['status' => 1]
                    );
                    $empleado->id_departamento = $dep->id;
                    $empleado->departamento    = $dep->nombre; // legacy
                } elseif (empty($empleado->id_departamento) && trim((string) $empleado->departamento) !== '') {
                    // Fallback: usa el texto legacy ya guardado en BD
                    $depLegacy = trim((string) $empleado->departamento);
                    $dep       = Departamento::firstOrCreate(
                        ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => $depLegacy],
                        ['status' => 1]
                    );
                    $empleado->id_departamento = $dep->id;
                    // $empleado->departamento ya tiene el texto legacy
                }

                // === Resolver PUESTO
                if ($request->filled('id_puesto')) {
                    $empleado->id_puesto = (int) $request->input('id_puesto');
                    if ($p = PuestoEmpleado::find($empleado->id_puesto)) {
                        $empleado->puesto = $p->nombre; // legacy
                    }
                } elseif ($puestoTxtReq !== '') {
                    $p = PuestoEmpleado::firstOrCreate(
                        ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => $puestoTxtReq],
                        ['status' => 1]
                    );
                    $empleado->id_puesto = $p->id;
                    $empleado->puesto    = $p->nombre; // legacy
                } elseif (empty($empleado->id_puesto) && trim((string) $empleado->puesto) !== '') {
                    // Fallback: usa el texto legacy ya guardado en BD
                    $puestoLegacy = trim((string) $empleado->puesto);
                    $p            = PuestoEmpleado::firstOrCreate(
                        ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => $puestoLegacy],
                        ['status' => 1]
                    );
                    $empleado->id_puesto = $p->id;
                    // $empleado->puesto ya tiene el texto legacy
                }

                // === Actualizar datos simples del empleado
                $empleado->fill($request->only([
                    'creacion', 'edicion', 'nombre', 'paterno', 'materno', 'telefono', 'correo',
                    'rfc', 'nss', 'id_empleado', 'curp', 'foto', 'fecha_nacimiento', 'fecha_ingreso',
                    'status', 'eliminado',
                    // 'departamento','puesto' // ya sincronizados arriba
                ]));

                $empleado->save();

                // === Domicilio
                $domicilio = DomicilioEmpleado::findOrFail($request->input('domicilio_empleado.id'));
                $domicilio->update($request->input('domicilio_empleado'));

                // === Campos extra
                if ($request->filled('campoExtra') && is_array($request->campoExtra)) {
                    foreach ($request->campoExtra as $campo) {
                        if (! empty($campo['id'])) {
                            $campoExistente = EmpleadoCampoExtra::where('id_empleado', $empleado->id)
                                ->where('id', $campo['id'])
                                ->first();
                            if ($campoExistente) {
                                $campoExistente->update([
                                    'nombre' => $campo['nombre'] ?? '',
                                    'valor'  => $campo['valor'] ?? '',
                                ]);
                            }
                        } else {
                            EmpleadoCampoExtra::create([
                                'id_empleado' => $empleado->id,
                                'nombre'      => $campo['nombre'] ?? '',
                                'valor'       => $campo['valor'] ?? '',
                            ]);
                        }
                    }
                }

                // === Respuesta con nombres resueltos
                $departamentoNombre = optional(Departamento::find($empleado->id_departamento))->nombre;
                $puestoNombre       = optional(PuestoEmpleado::find($empleado->id_puesto))->nombre;

                return response()->json([
                    'message' => 'Empleado y domicilio actualizados correctamente.',
                    'data'    => array_merge($empleado->toArray(), [
                        'departamento_nombre' => $departamentoNombre,
                        'puesto_nombre'       => $puestoNombre,
                    ]),
                ], 200);
            });

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Empleado o domicilio no encontrado.'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Ocurrió un error al actualizar los datos.'], 500);
        }
    }

    public function store(Request $request)
    {
        // Validación
        $validated = $request->validate([
            'creacion'                   => 'required|date',
            'edicion'                    => 'required|date',
            'id_portal'                  => 'required|integer',
            'id_usuario'                 => 'required|integer',
            'id_cliente'                 => 'required|integer',
            'id_empleado'                => 'nullable|integer',
            'correo'                     => 'nullable|email',
            'fecha_nacimiento'           => 'nullable|date',
            'fecha_ingreso'              => 'nullable|date',
            'curp'                       => 'nullable|string',
            'rfc'                        => 'nullable|string',
            'nss'                        => 'nullable|string',
            'nombre'                     => 'required|string',
            'paterno'                    => 'required|string',
            'materno'                    => 'nullable|string',
            'departamento'               => 'nullable|string|max:120',
            'id_departamento'            => 'nullable|integer|min:1',
            'puesto'                     => 'nullable|string|max:120',
            'id_puesto'                  => 'nullable|integer|min:1',
            'telefono'                   => 'nullable|string',

            'domicilio_empleado.calle'   => 'nullable|string',
            'domicilio_empleado.num_ext' => 'nullable|string',
            'domicilio_empleado.num_int' => 'nullable|string',
            'domicilio_empleado.colonia' => 'nullable|string',
            'domicilio_empleado.ciudad'  => 'nullable|string',
            'domicilio_empleado.estado'  => 'nullable|string',
            'domicilio_empleado.pais'    => 'nullable|string',
            'domicilio_empleado.cp'      => 'nullable|string',

            'extraFields'                => 'nullable|array',
            'extraFields.*.nombre'       => 'required|string',
            'extraFields.*.valor'        => 'required|string',
        ]);

        // Edad (si viene fecha_nacimiento)
        $edad = null;
        if (! empty($validated['fecha_nacimiento'])) {
            $fechaNac = Carbon::parse($validated['fecha_nacimiento']);
            $fechaCre = Carbon::parse($validated['creacion']);
            $edad     = $fechaCre->diffInYears($fechaNac);
        }

        // Duplicidad básica por (portal, cliente, nombre, paterno)
        $existeEmpleado = Empleado::where('nombre', $validated['nombre'])
            ->where('paterno', $validated['paterno'])
            ->where('id_portal', $validated['id_portal'])
            ->where('id_cliente', $validated['id_cliente'])
            ->where('eliminado', 0)
            ->first();

        if ($existeEmpleado) {
            return response()->json([
                'message'            => 'El empleado ya existe en este portal y cliente.',
                'empleado_existente' => $existeEmpleado,
            ], 409);
        }

        return DB::transaction(function () use ($validated, $edad) {

            // 1) Domicilio
            $domicilio = DomicilioEmpleado::create([
                'calle'   => $validated['domicilio_empleado']['calle'] ?? null,
                'num_ext' => $validated['domicilio_empleado']['num_ext'] ?? null,
                'num_int' => $validated['domicilio_empleado']['num_int'] ?? null,
                'colonia' => $validated['domicilio_empleado']['colonia'] ?? null,
                'ciudad'  => $validated['domicilio_empleado']['ciudad'] ?? null,
                'estado'  => $validated['domicilio_empleado']['estado'] ?? null,
                'pais'    => $validated['domicilio_empleado']['pais'] ?? null,
                'cp'      => $validated['domicilio_empleado']['cp'] ?? null,
            ]);

            // 2) Resolver catálogos (prioriza id_*)
            $portal  = (int) $validated['id_portal'];
            $cliente = (int) $validated['id_cliente'];

            $idDepartamento = null;
            if (! empty($validated['id_departamento'])) {
                $idDepartamento = (int) $validated['id_departamento'];
            } elseif (! empty($validated['departamento'])) {
                $dep = Departamento::firstOrCreate(
                    ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => trim($validated['departamento'])],
                    ['status' => 1]
                );
                $idDepartamento = $dep->id;
            }

            $idPuesto = null;
            if (! empty($validated['id_puesto'])) {
                $idPuesto = (int) $validated['id_puesto'];
            } elseif (! empty($validated['puesto'])) {
                $p = PuestoEmpleado::firstOrCreate(
                    ['id_portal' => $portal, 'id_cliente' => $cliente, 'nombre' => trim($validated['puesto'])],
                    ['status' => 1]
                );
                $idPuesto = $p->id;
            }

            // 3) Empleado
            $empleado = Empleado::create([
                'creacion'              => $validated['creacion'],
                'edicion'               => $validated['edicion'],
                'id_portal'             => $portal,
                'id_usuario'            => $validated['id_usuario'],
                'id_cliente'            => $cliente,
                'id_empleado'           => $validated['id_empleado'] ?? null,
                'correo'                => $validated['correo'] ?? null,
                'curp'                  => $validated['curp'] ?? null,
                'nombre'                => $validated['nombre'],
                'nss'                   => $validated['nss'] ?? null,
                'rfc'                   => $validated['rfc'] ?? null,
                'paterno'               => $validated['paterno'],
                'materno'               => $validated['materno'] ?? null,
                // sincronizamos legacy si se resolvió el catálogo
                'departamento'          => $idDepartamento ? (Departamento::find($idDepartamento)->nombre ?? ($validated['departamento'] ?? null)) : ($validated['departamento'] ?? null),
                'puesto'                => $idPuesto ? (PuestoEmpleado::find($idPuesto)->nombre ?? ($validated['puesto'] ?? null)) : ($validated['puesto'] ?? null),
                'id_departamento'       => $idDepartamento,
                'id_puesto'             => $idPuesto,
                'fecha_ingreso'         => $validated['fecha_ingreso'] ?? null,
                'fecha_nacimiento'      => $validated['fecha_nacimiento'] ?? null,
                'telefono'              => $validated['telefono'] ?? null,
                'id_domicilio_empleado' => $domicilio->id,
                'status'                => 1,
                'eliminado'             => 0,
            ]);

            // 4) Campos extra
            if (! empty($validated['extraFields'])) {
                foreach ($validated['extraFields'] as $campoExtra) {
                    EmpleadoCampoExtra::create([
                        'id_empleado' => $empleado->id,
                        'nombre'      => $campoExtra['nombre'],
                        'valor'       => $campoExtra['valor'],
                    ]);
                }
            }

            // 5) Medical info
            MedicalInfo::create([
                'id_empleado' => $empleado->id,
                'creacion'    => $validated['creacion'],
                'edicion'     => $validated['creacion'],
                'edad'        => $edad,
            ]);

            // Respuesta con nombres
            $departamentoNombre = optional(Departamento::find($empleado->id_departamento))->nombre;
            $puestoNombre       = optional(PuestoEmpleado::find($empleado->id_puesto))->nombre;

            return response()->json([
                'message' => 'Empleado registrado exitosamente.',
                'data'    => array_merge($empleado->toArray(), [
                    'departamento_nombre' => $departamentoNombre,
                    'puesto_nombre'       => $puestoNombre,
                ]),
            ], 201);
        });
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

    public function obtenerColaboradoresParaCalendario(Request $request)
    {
        $request->validate([
            'id_cliente' => 'required|integer',
        ]);

        $idCliente = $request->input('id_cliente');

        $empleados = Empleado::where('id_cliente', $idCliente)
            ->where('eliminado', 0)
            ->where('status', 1)
            ->get(['id', 'nombre', 'paterno', 'materno', 'correo', 'puesto', 'departamento', 'id_empleado']);

        $colaboradores = $empleados->map(function ($empleado) {
            $nombreCompleto = trim("{$empleado->nombre} {$empleado->paterno} {$empleado->materno}");

            return [
                'nombre_completo' => $nombreCompleto,
                'id'              => $empleado->id ?? '',
                'id_empleado'     => $empleado->id_empleado ?? '',
                'correo'          => $empleado->correo ?? '',
                'puesto'          => $empleado->puesto ?? '',
                'departamento'    => $empleado->departamento ?? '',
            ];
        });

        return response()->json([
            'success'       => true,
            'colaboradores' => $colaboradores,
        ]);
    }

}
