<?php
namespace App\Http\Controllers\ExEmpleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Auth\AdministradorAuth;
use App\Models\Candidato;
use App\Models\CandidatoPruebas;
use App\Models\ComentarioFormerEmpleado;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Models\FormerEmpleadoNoRecomendable;
use App\Models\Medico;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FormerEmpleadoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user('sanctum');

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
    private function authorizedEmployee(
        Request $request,
        int $employeeId
    ): array {
        $administrator = $this->administrator($request);

        $employee = Empleado::query()
            ->whereKey($employeeId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        return [$administrator, $employee];
    }
    // Crear un nuevo comentario
    public function storeComentarioFormer(Request $request)
    {
        $activoNoRecomendable =
        $request->boolean('no_recomendable');

        $rules = [
            'creacion'               => [
                'required',
                'date',
            ],
            'id_empleado'            => [
                'required',
                'integer',
                'min:1',
            ],
            'titulo'                 => [
                'required',
                'string',
                'max:255',
            ],
            'comentario'             => [
                'required',
                'string',
            ],
            'origen'                 => [
                'required',
                'integer',
            ],
            'status'                 => [
                'sometimes',
                'integer',
                'in:1,2',
            ],
            'fecha_salida_reingreso' => [
                'nullable',
                'date',
            ],
            'no_recomendable'        => [
                'sometimes',
                'boolean',
            ],
            'motivo_no_recomendable' => [
                'nullable',
                'string',
                'max:5000',
            ],
        ];

        if (
            (int) $request->input('status') === 2 &&
            $request->has('no_recomendable') &&
            $activoNoRecomendable
        ) {
            $rules['motivo_no_recomendable'] = [
                'required',
                'string',
                'max:5000',
            ];
        }

        $data = $request->validate($rules);

        [$administrator, $empleado] =
        $this->authorizedEmployee(
            $request,
            (int) $data['id_empleado']
        );

        $resultado = DB::connection('portal_main')
            ->transaction(function () use (
                $data,
                $request,
                $administrator,
                $empleado,
                $activoNoRecomendable
            ) {
                if (array_key_exists('status', $data)) {
                    $empleado->status =
                    (int) $data['status'];

                    $empleado->edicion =
                        $data['creacion'];

                    $empleado->id_usuario =
                    (int) $administrator->id;

                    if ((int) $data['origen'] === 1) {
                        if (
                            (int) $data['status'] === 1 &&
                            ! empty(
                                $data['fecha_salida_reingreso']
                            )
                        ) {
                            $empleado->fecha_ingreso =
                                $data['fecha_salida_reingreso'];
                        }

                        if ((int) $data['status'] === 2) {
                            $empleado->fecha_salida =
                                $data['creacion'];
                        }
                    }

                    $empleado->save();
                }

                $comentario =
                ComentarioFormerEmpleado::create([
                    'creacion'               => $data['creacion'],
                    'id_empleado'            =>
                    (int) $empleado->id,
                    'id_usuario'             =>
                    (int) $administrator->id,
                    'titulo'                 => $data['titulo'],
                    'comentario'             =>
                    trim($data['comentario']),
                    'origen'                 => (int) $data['origen'],
                    'fecha_salida_reingreso' =>
                    $data['fecha_salida_reingreso'] ?? null,
                ]);

                $estadoNoRecomendable = null;

                if (
                    (int) ($data['status'] ?? 0) === 2 &&
                    $request->has('no_recomendable')
                ) {
                    $registro =
                    FormerEmpleadoNoRecomendable::query()
                        ->firstOrNew([
                            'id_empleado' =>
                            (int) $empleado->id,
                        ]);

                    $estabaActivo =
                    $registro->exists &&
                    (bool) $registro->activo;

                    $registro->activo =
                        $activoNoRecomendable;

                    $registro->id_usuario =
                    (int) $administrator->id;

                    $registro->save();

                    $estadoNoRecomendable =
                    (bool) $registro->activo;

                    if ($activoNoRecomendable) {
                        ComentarioFormerEmpleado::create([
                            'creacion'               =>
                            $data['creacion'],
                            'id_empleado'            =>
                            (int) $empleado->id,
                            'id_usuario'             =>
                            (int) $administrator->id,
                            'titulo'                 =>
                            'Marcado como no recomendable',
                            'comentario'             => trim(
                                $data[
                                    'motivo_no_recomendable'
                                ]
                            ),
                            'origen'                 => 2,
                            'fecha_salida_reingreso' =>
                            $data['creacion'],
                        ]);
                    } elseif ($estabaActivo) {
                        ComentarioFormerEmpleado::create([
                            'creacion'               =>
                            $data['creacion'],
                            'id_empleado'            =>
                            (int) $empleado->id,
                            'id_usuario'             =>
                            (int) $administrator->id,
                            'titulo'                 =>
                            'Marca de no recomendable desactivada',
                            'comentario'             =>
                            'Se retiró la marca de no recomendable.',
                            'origen'                 => 2,
                            'fecha_salida_reingreso' =>
                            $data['creacion'],
                        ]);
                    }
                }

                return [
                    'comentario'      => $comentario,
                    'empleado'        => [
                        'id'     => (int) $empleado->id,
                        'status' =>
                        (int) $empleado->status,
                    ],
                    'no_recomendable' =>
                    $estadoNoRecomendable,
                ];
            });

        Log::info(
            'Comentario Former guardado',
            [
                'id_empleado'     => (int) $empleado->id,
                'id_usuario'      =>
                (int) $administrator->id,
                'origen'          => (int) $data['origen'],
                'status'          => $data['status'] ?? null,
                'no_recomendable' =>
                $resultado['no_recomendable'],
            ]
        );

        return response()->json([
            'success' => true,
            'data'    => $resultado,
        ], 201);
    }

    public function updateFechaSalida(Request $request)
    {
        $data = $request->validate([
            'id_empleado'  => [
                'required',
                'integer',
                'min:1',
            ],
            'fecha_salida' => [
                'required',
                'date',
            ],
        ]);

        [$administrator, $empleado] =
        $this->authorizedEmployee(
            $request,
            (int) $data['id_empleado']
        );

        $valorAnterior = $empleado->fecha_salida
            ? $empleado->fecha_salida->format('Y-m-d')
            : null;

        $valorNuevo = $data['fecha_salida'];

        Log::info(
            'Intento de actualización de fecha_salida',
            [
                'id_empleado'    => (int) $empleado->id,
                'id_usuario'     =>
                (int) $administrator->id,
                'valor_anterior' => $valorAnterior,
                'valor_nuevo'    => $valorNuevo,
                'ip'             => $request->ip(),
            ]
        );

        if ($valorAnterior !== $valorNuevo) {
            $empleado->fecha_salida = $valorNuevo;
            $empleado->id_usuario   =
            (int) $administrator->id;
            $empleado->save();
        }

        return response()->json([
            'success' => true,
            'message' =>
            'Fecha de salida actualizada.',
            'data'    => [
                'fecha_salida' => $valorNuevo,
            ],
        ]);
    }
    public function getDocumentosYCursos(
        Request $request,
        int $id_empleado
    ) {
        // Validar que el empleado existe
        [, $empleado] = $this->authorizedEmployee(
            $request,
            $id_empleado
        );

        $id_empleado = (int) $empleado->id;
        // Obtener el parámetro status si existe
        $status = request()->query('status');

        // Construir la consulta
        $query = DocumentEmpleado::with('documentOption')->where('employee_id', $id_empleado);

        // Aplicar filtro por status si se proporciona
        if ($status) {
            $query->where('status', $status);
        }

        // Ejecutar la consulta y mapear resultados
        $documentos = $query->get()->map(function ($documento) {
            return [
                'id'           => $documento->id,
                'creacion'     => $documento->creacion,
                'description'  => $documento->description,
                'name'         => $documento->name,
                'nameDocument' => $documento->documentOption ? $documento->documentOption->name : $documento->nameDocument,
                'carpeta'      => '_documentEmpleado/',
                'tipo'         => 'Document',
            ];
        });

        // Obtener cursos del empleado
        $cursos = CursoEmpleado::where('employee_id', $id_empleado)
            ->get()
            ->map(function ($curso) {
                return [
                    'id'            => $curso->id,
                    'creacion'      => $curso->creacion,
                    'description'   => $curso->description,
                    'name'          => $curso->name,
                    'name_document' => $curso->name_document ?? 'Sin Nombre',
                    'carpeta'       => '_cursos/',
                    'tipo'          => 'Course or Training',
                ];
            });

        // Obtener exámenes del empleado
        $examenes = ExamEmpleado::with('examOption')
            ->where('employee_id', $id_empleado)
            ->get()
            ->map(function ($examen) {
                return [
                    'id'           => $examen->id,
                    'creacion'     => $examen->creacion,
                    'description'  => $examen->description,
                    'archivo'      => $examen->name,
                    'name'         => $examen->examOption ? $examen->examOption->name : $examen->name,
                    'nameDocument' => $examen->examOption ? $examen->examOption->name : $examen->name,
                    'carpeta'      => '_examEmpleado/',
                    'id_candidato' => $examen->id_candidato,
                ];
            });

        // Comprobar si hay exámenes con id_candidato
        $idCandidatos = $examenes->pluck('id_candidato')->unique()->filter();

        if ($idCandidatos->isNotEmpty()) {
            // Consultar CandidatoPruebas y Candidato para obtener los campos deseados
            $candidatosPruebas = CandidatoPruebas::whereIn('id_candidato', $idCandidatos)->get();
            $candidatos        = Candidato::with('medico', 'doping')->whereIn('id', $idCandidatos)->get();

            // Mapear los exámenes para incluir los nuevos campos
            $examenesConOpciones = $examenes->map(function ($examen) use ($candidatosPruebas, $candidatos) {
                // Obtener el candidato correspondiente
                $candidatoPrueba = $candidatosPruebas->firstWhere('id_candidato', $examen['id_candidato']);
                $candidato       = $candidatos->firstWhere('id', $examen['id_candidato']);
                $medico          = $candidatoPrueba->medico ?? null;
                $doping          = $candidatoPrueba->tipo_antidoping ?? null;

                                                             // Definir icono de resultado según status_bgc
                $icono_resultado = 'icono_resultado_espera'; // Valor por defecto
                if (isset($candidato->status_bgc)) {
                    switch ($candidato->status_bgc) {
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
                    }
                }

                return [
                    'id'              => $examen['id'],
                    'name'            => $examen['name'],
                    'description'     => $examen['description'],
                    'creacion'        => $examen['creacion'],
                    'id_candidato'    => $examen['id_candidato'],
                    'archivo'         => $examen['archivo'], // Aquí se mantiene el archivo original
                    'socioeconomico'  => $candidatoPrueba->socioeconomico ?? null,
                    'medico'          => $medico,
                    'doping'          => $doping,
                    'liberado'        => $candidato->liberado ?? null,
                    'status_bgc'      => $candidato->status_bgc ?? null,
                    'icono_resultado' => $icono_resultado,
                    'carpeta'         => '_examEmpleado/',
                    'tipo'            => 'BGV or Test',

                ];
            });

            // Actualizar la colección de exámenes con la nueva información
            $examenes = $examenesConOpciones;
        }

        // Formatear los resultados
        $resultados = [
            'documentos' => $documentos,
            'cursos'     => $cursos,
            'examenes'   => $examenes,
        ];

        return response()->json($resultados, 200);
    }

    public function storeDocumentos(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'id_empleado'  => 'required|integer',
            'nameDocument' => 'required|string|max:255',
            'descripcion'  => 'nullable|string|max:500',
            'file'         => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'creacion'     => 'required|date',
            'edicion'      => 'required|date',
        ]);

        if ($validator->fails()) {
            Log::error('Errores de validación:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        [, $empleado] = $this->authorizedEmployee(
            $request,
            (int) $request->input('id_empleado')
        );

        // Log de los datos recibidos
        //Log::info('Datos recibidos en el store: ' . print_r($request->all(), true));
        // dd($request->all());

        // Preparar el nombre del archivo para la subida
        $origen        = 1;
        $employeeId    = (int) $empleado->id;
        $randomString  = $this->generateRandomString();                        // Generar cadena aleatoria
        $fileExtension = $request->file('file')->getClientOriginalExtension(); // Obtener extensión del archivo
        $newFileName   = "{$employeeId}_{$randomString}_{$origen}.{$fileExtension}";

        // Preparar la solicitud para la subida del archivo
        $uploadRequest = new Request();
        $uploadRequest->files->set('file', $request->file('file'));
        $uploadRequest->merge([
            'file_name' => $newFileName,
            'carpeta'   => '_documentEmpleado', // Cambia esto a tu carpeta deseada
        ]);

                                                                                  // Llamar a la función de upload
        $uploadResponse = app(DocumentController::class)->upload($uploadRequest); // Asegúrate de cambiar el nombre del controlador

        // Verificar si la subida fue exitosa
        if ($uploadResponse->getStatusCode() !== 200) {
            return response()->json(['error' => 'Error al subir el documento.'], 500);
        }

        // Crear un nuevo registro en la base de datos
        $cursoEmpleado = DocumentEmpleado::create([
            'employee_id'     => $employeeId,
            'name'            => $newFileName,
            'nameDocument'    => $request->input('nameDocument'),
            'description'     => $request->input('descripcion'),
            'creacion'        => $request->input('creacion'),
            'edicion'         => $request->input('edicion'),
            'id_opcion_exams' => $request->input('id_opcion_exams') ?? null,
            'status'          => 2, // Esto es opcional
        ]);

        // Log para verificar el curso registrado
        Log::info('Curso registrado:', ['curso' => $cursoEmpleado]);

        // Devolver una respuesta exitosa
        return response()->json([
            'message' => 'Curso agregado exitosamente.',
            'curso'   => $cursoEmpleado,
        ], 201);
    }

    private function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", ceil($length / 10))), 1, $length);
    }

    public function getConclusionsByEmployeeId(
        Request $request,
        int $id_empleado
    ) {
        [, $empleado] = $this->authorizedEmployee(
            $request,
            $id_empleado
        );

        $comentarios =
        ComentarioFormerEmpleado::query()
            ->where(
                'id_empleado',
                (int) $empleado->id
            )
            ->orderByDesc('id')
            ->get();

        return response()->json($comentarios);
    }
    public function deleteComentario(
        Request $request,
        int $id
    ) {
        $comentario =
        ComentarioFormerEmpleado::findOrFail($id);

        $this->authorizedEmployee(
            $request,
            (int) $comentario->id_empleado
        );

        if (! empty($comentario->fecha_salida_reingreso)) {
            return response()->json([
                'success' => false,
                'message' =>
                'Este comentario forma parte del historial y no puede eliminarse.',
            ], 409);
        }

        $comentario->delete();

        return response()->json([
            'success' => true,
            'message' =>
            'Comentario eliminado exitosamente.',
        ]);
    }
    public function updateNoRecomendable(
        Request $request,
        int $idEmpleado
    ) {
        $activo = $request->boolean('activo');

        $rules = [
            'activo' => ['required', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:5000'],
        ];

        if ($activo) {
            $rules['motivo'] = [
                'required',
                'string',
                'max:5000',
            ];
        }

        $data = $request->validate($rules);

        $administrator = $this->administrator($request);

        $empleado = Empleado::query()
            ->whereKey($idEmpleado)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $empleado->id_cliente]
        );

        $connection = DB::connection('portal_main');

        $resultado = $connection->transaction(
            function () use (
                $data,
                $activo,
                $administrator,
                $empleado
            ) {
                $registro =
                FormerEmpleadoNoRecomendable::query()
                    ->updateOrCreate(
                        [
                            'id_empleado' => (int) $empleado->id,
                        ],
                        [
                            'activo'     => $activo,
                            'id_usuario' =>
                            (int) $administrator->id,
                        ]
                    );

                $hoy = now('America/Mexico_City')
                    ->toDateString();

                if ($activo) {
                    ComentarioFormerEmpleado::create([
                        'creacion'               => $hoy,
                        'id_empleado'            => (int) $empleado->id,
                        'id_usuario'             =>
                        (int) $administrator->id,
                        'titulo'                 =>
                        'Marcado como no recomendable',
                        'comentario'             => trim($data['motivo']),
                        'origen'                 => 2,
                        'fecha_salida_reingreso' =>
                        $empleado->fecha_salida
                            ? $empleado->fecha_salida
                            ->format('Y-m-d')
                            : $hoy,
                    ]);
                } else {
                    ComentarioFormerEmpleado::create([
                        'creacion'               => $hoy,
                        'id_empleado'            => (int) $empleado->id,
                        'id_usuario'             =>
                        (int) $administrator->id,
                        'titulo'                 =>
                        'Marca de no recomendable desactivada',
                        'comentario'             =>
                        'Se retiró la marca de no recomendable.',
                        'origen'                 => 2,
                        'fecha_salida_reingreso' =>
                        $empleado->fecha_salida
                            ? $empleado->fecha_salida
                            ->format('Y-m-d')
                            : $hoy,
                    ]);
                }

                return [
                    'id_empleado'     => (int) $empleado->id,
                    'no_recomendable' =>
                    (bool) $registro->activo,
                    'edicion'         => optional($registro->edicion)
                        ->toISOString(),
                ];
            }
        );

        return response()->json([
            'success' => true,
            'message' => $activo
                ? 'El exempleado fue marcado como no recomendable.'
                : 'La marca de no recomendable fue desactivada.',
            'data'    => $resultado,
        ]);
    }
}
