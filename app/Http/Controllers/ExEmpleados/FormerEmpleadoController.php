<?php
namespace App\Http\Controllers\ExEmpleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Candidato;
use App\Models\CandidatoPruebas;
use App\Models\ComentarioFormerEmpleado;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Models\Medico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class FormerEmpleadoController extends Controller
{

    // Crear un nuevo comentario
    public function storeComentarioFormer(Request $request)
    {
        // 🔹 LOG ENTRADA
        Log::info('ComentarioFormer: request recibido', [
            'id_empleado' => $request->id_empleado,
            'id_usuario'  => $request->id_usuario ?? null,
            'origen'      => $request->origen,
        ]);

        $validator = Validator::make($request->all(), [
            'creacion'    => 'required|date',
            'id_empleado' => 'required|integer',
            'id_usuario'  => 'nullable|integer',
            'titulo'      => 'required|string|max:255',
            'comentario'  => 'required|string',
            'origen'      => 'required|integer',
            'status'      => 'sometimes|integer|in:1,2',
        ]);

        if ($validator->fails()) {
            Log::warning('ComentarioFormer: validación fallida', [
                'errors' => $validator->errors()->toArray(),
            ]);

            return response()->json($validator->errors(), 422);
        }

        try {

            // 🔹 ACTUALIZACIÓN EMPLEADO
            if ($request->has('status')) {
                $empleado = Empleado::find($request->id_empleado);

                if ($empleado) {

                    Log::info('ComentarioFormer: actualizando empleado', [
                        'id_empleado' => $empleado->id,
                        'status_old'  => $empleado->status,
                        'status_new'  => $request->status,
                    ]);

                    $empleado->edicion     = $request->creacion;
                    $empleado->status      = $request->status;
                    $empleado->fecha_final = $request->creacion;

                    $empleado->save();

                } else {

                    Log::warning('ComentarioFormer: empleado no encontrado', [
                        'id_empleado' => $request->id_empleado,
                    ]);
                }
            }

            // 🔹 CREACIÓN COMENTARIO
            $comentario = ComentarioFormerEmpleado::create($request->all());

            Log::info('ComentarioFormer: comentario creado', [
                'comentario_id' => $comentario->id,
                'id_empleado'   => $comentario->id_empleado,
                'id_usuario'    => $comentario->id_usuario ?? null,
            ]);

            return response()->json($comentario, 201);

        } catch (\Throwable $e) {

            Log::error('ComentarioFormer: error inesperado', [
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
            ]);

            return response()->json([
                'error' => 'Error interno',
            ], 500);
        }
    }
    public function exportar_excel_rotacion()
    {
        $portal = $this->session->userdata('idPortal');
        $this->load->database();

        $sucursal     = $this->input->post('sucursal');
        $fecha_inicio = $this->input->post('fecha_inicio');
        $fecha_fin    = $this->input->post('fecha_fin');
        $puesto       = $this->input->post('puesto');
        $departamento = $this->input->post('departamento');

        if (empty($fecha_inicio) || empty($fecha_fin)) {
            show_error('Debes indicar fecha_inicio y fecha_fin');
            return;
        }

        $inicio = $fecha_inicio . ' 00:00:00';
        $fin    = $fecha_fin . ' 23:59:59';

        /*
    |--------------------------------------------------------------------------
    | 1. Plantilla inicial
    |--------------------------------------------------------------------------
    | Empleados que ingresaron antes del inicio del periodo
    | y que no habían salido antes de esa fecha.
    */
        $this->db->from('empleados');
        $this->db->where('empleados.eliminado', 0);

        if ($sucursal) {
            $this->db->where('empleados.id_cliente', $sucursal);
        } else {
            $this->db->where('empleados.id_portal', $portal);
        }

        $this->db->where("IFNULL(empleados.fecha_ingreso, empleados.creacion) <", $inicio);

        $this->db->group_start();
        $this->db->where('empleados.fecha_salida IS NULL', null, false);
        $this->db->or_where('empleados.fecha_salida >=', $inicio);
        $this->db->group_end();

        if ($puesto) {
            $this->db->where('empleados.puesto', $puesto);
        }

        if ($departamento) {
            $this->db->where('empleados.departamento', $departamento);
        }

        $plantilla_inicial = $this->db->count_all_results();

        /*
    |--------------------------------------------------------------------------
    | 2. Ingresos del periodo
    |--------------------------------------------------------------------------
    */
        $this->db->from('empleados');
        $this->db->where('empleados.eliminado', 0);

        if ($sucursal) {
            $this->db->where('empleados.id_cliente', $sucursal);
        } else {
            $this->db->where('empleados.id_portal', $portal);
        }

        $this->db->where("IFNULL(empleados.fecha_ingreso, empleados.creacion) >=", $inicio);
        $this->db->where("IFNULL(empleados.fecha_ingreso, empleados.creacion) <=", $fin);

        if ($puesto) {
            $this->db->where('empleados.puesto', $puesto);
        }

        if ($departamento) {
            $this->db->where('empleados.departamento', $departamento);
        }

        $ingresos = $this->db->count_all_results();

        /*
    |--------------------------------------------------------------------------
    | 3. Bajas del periodo
    |--------------------------------------------------------------------------
    */
        $this->db->from('empleados');
        $this->db->where('empleados.eliminado', 0);
        $this->db->where('empleados.fecha_salida IS NOT NULL', null, false);
        $this->db->where('empleados.fecha_salida >=', $inicio);
        $this->db->where('empleados.fecha_salida <=', $fin);

        if ($sucursal) {
            $this->db->where('empleados.id_cliente', $sucursal);
        } else {
            $this->db->where('empleados.id_portal', $portal);
        }

        if ($puesto) {
            $this->db->where('empleados.puesto', $puesto);
        }

        if ($departamento) {
            $this->db->where('empleados.departamento', $departamento);
        }

        $bajas = $this->db->count_all_results();

        /*
    |--------------------------------------------------------------------------
    | 4. Plantilla final
    |--------------------------------------------------------------------------
    | Empleados que ingresaron hasta la fecha fin
    | y que no habían salido antes o en esa fecha.
    */
        $this->db->from('empleados');
        $this->db->where('empleados.eliminado', 0);

        if ($sucursal) {
            $this->db->where('empleados.id_cliente', $sucursal);
        } else {
            $this->db->where('empleados.id_portal', $portal);
        }

        $this->db->where("IFNULL(empleados.fecha_ingreso, empleados.creacion) <=", $fin);

        $this->db->group_start();
        $this->db->where('empleados.fecha_salida IS NULL', null, false);
        $this->db->or_where('empleados.fecha_salida >', $fin);
        $this->db->group_end();

        if ($puesto) {
            $this->db->where('empleados.puesto', $puesto);
        }

        if ($departamento) {
            $this->db->where('empleados.departamento', $departamento);
        }

        $plantilla_final = $this->db->count_all_results();

        /*
    |--------------------------------------------------------------------------
    | 5. Cálculos
    |--------------------------------------------------------------------------
    */
        $promedio_plantilla = ($plantilla_inicial + $plantilla_final) / 2;
        $rotacion           = $promedio_plantilla > 0
            ? round(($bajas / $promedio_plantilla) * 100, 2)
            : 0;

        /*
    |--------------------------------------------------------------------------
    | 6. Datos finales
    |--------------------------------------------------------------------------
    */
        $finalData = [[
            'Fecha_Inicio'        => $fecha_inicio,
            'Fecha_Fin'           => $fecha_fin,
            'Plantilla_Inicial'   => $plantilla_inicial,
            'Ingresos'            => $ingresos,
            'Bajas'               => $bajas,
            'Plantilla_Final'     => $plantilla_final,
            'Promedio_Plantilla'  => $promedio_plantilla,
            'Rotacion_Porcentaje' => $rotacion . '%',
        ]];

        /*
    |--------------------------------------------------------------------------
    | 7. Generar Excel
    |--------------------------------------------------------------------------
    */
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rotacion');

        $headers = array_keys($finalData[0]);
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray($finalData, null, 'A2');

        $headerStyle = [
            'font'      => [
                'bold'  => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size'  => 12,
            ],
            'fill'      => [
                'fillType'   => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78'],
            ],
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ];

        $bodyStyle = [
            'alignment' => [
                'horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER,
                'vertical'   => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER,
                'wrapText'   => true,
            ],
        ];

        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray($headerStyle);
        $sheet->getStyle("A2:{$lastColumn}2")->applyFromArray($bodyStyle);

        for ($col = 1; $col <= count($headers); $col++) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }

        $filename = 'reporte_rotacion_' . date('Ymd_His') . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment;filename=\"$filename\"");
        header('Cache-Control: max-age=0');

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }

    public function updateFechaSalida(Request $request)
    {
        $request->validate([
            'id_empleado'  => 'required|integer',
            'fecha_salida' => 'required|date',
            'id_usuario'   => 'nullable|integer',
        ]);

        $empleado = Empleado::find($request->id_empleado);

        if (! $empleado) {

            Log::warning('Empleado no encontrado al actualizar fecha_salida', [
                'id_empleado' => $request->id_empleado,
                'id_usuario'  => $request->id_usuario ?? null,
                'ip'          => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        $valorAnterior = $empleado->fecha_salida;
        $valorNuevo    = $request->fecha_salida;

        // 🔥 Log antes del cambio
        Log::info('Intento de actualización de fecha_salida', [
            'id_empleado'    => $empleado->id,
            'id_usuario'     => $request->id_usuario ?? null,
            'valor_anterior' => $valorAnterior,
            'valor_nuevo'    => $valorNuevo,
            'ip'             => $request->ip(),
        ]);

        if ($valorAnterior != $valorNuevo) {

            $empleado->fecha_salida = $valorNuevo;
            $empleado->id_usuario   = $request->id_usuario ?? null;
            $empleado->save();

            // 🔥 Log después del cambio
            Log::info('Fecha_salida actualizada correctamente', [
                'id_empleado'    => $empleado->id,
                'id_usuario'     => $request->id_usuario ?? null,
                'valor_anterior' => $valorAnterior,
                'valor_nuevo'    => $valorNuevo,
                'ip'             => $request->ip(),
            ]);

        } else {

            Log::notice('No hubo cambio en fecha_salida', [
                'id_empleado' => $empleado->id,
                'id_usuario'  => $request->id_usuario ?? null,
                'fecha'       => $valorNuevo,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Fecha de salida actualizada',
            'data'    => [
                'fecha_salida' => $empleado->fecha_salida,
            ],
        ]);
    }
    public function getDocumentosYCursos($id_empleado)
    {
        // Validar que el empleado existe
        $empleado = Empleado::find($id_empleado);
        if (! $empleado) {
            return response()->json(['error' => 'Empleado no encontrado'], 404);
        }
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
        // Log de los datos recibidos
        //Log::info('Datos recibidos en el store: ' . print_r($request->all(), true));
        // dd($request->all());

        // Preparar el nombre del archivo para la subida
        $origen        = 1;
        $employeeId    = $request->input('employee_id');
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
            'employee_id'     => $request->input('id_empleado'),
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

    public function getConclusionsByEmployeeId($id_empleado)
    {
        // Obtener los comentarios del empleado por su ID
        $comentarios = ComentarioFormerEmpleado::where('id_empleado', $id_empleado)->get();

        // Verificar si se encontraron comentarios
        if ($comentarios->isEmpty()) {
            return response()->json(['message' => 'No conclusions found for this employee.'], 404);
        }

        return response()->json($comentarios, 200);
    }

    public function deleteComentario($id)
    {
        // Buscar el comentario por ID
        $comentario = ComentarioFormerEmpleado::find($id);

        if (! $comentario) {
            return response()->json(['error' => 'Comentario no encontrado'], 404);
        }

        // Eliminar el comentario
        $comentario->delete();

        return response()->json(['message' => 'Comentario eliminado exitosamente'], 200);
    }

}
