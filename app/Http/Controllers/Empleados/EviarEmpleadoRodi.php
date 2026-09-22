<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\CatDocumentoRequerimiento;
use App\Models\Empleado;
use App\Models\ClienteTalent;
use App\Models\ExamEmpleado;
use App\Models\ProyectosHistorial;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EviarEmpleadoRodi extends Controller
{
    // Otras funciones...

    public function registrarCandidato(Request $request)
    {
        $id_empleado = $request->id_empleado;
        $frases_permitidas = [
            'General Nacional',
            'Laborales Nacional',
        ];

        $empleado = Empleado::with('domicilioEmpleado')
            ->where('id', $id_empleado)
            ->first();

        if (! $empleado) {
            Log::warning(
                'Employee not found with id_empleado:',
                ['id_empleado' => $id_empleado]
            );

            return response()->json([
                'codigo' => 0,
                'msg' => 'Could not register the candidate, please try again later',
            ], 500);
        }

        $domicilio = $empleado->domicilioEmpleado;

        $tipo_formulario = (
            Str::slug($domicilio->pais, '-', 'es')
            !== Str::slug('Mexico', '-', 'es')
        ) ? 4 : 3;

        if ($request->project == 0) {
            $tipo_formulario = 0;
        }

        $nombre = ClienteTalent::where(
            'id',
            $request->id_cliente_talent
        )->pluck('nombre')->first();

        $fechaHoy = $this->getCurrentDateTime();

        $socioeconomico = $request->project > 0 ? 1 : 0;

        $candidato = [
            'creacion' => $fechaHoy,
            'edicion' => $fechaHoy,
            'tipo_formulario' => $tipo_formulario,
            'id_usuario' => 1,
            'fecha_alta' => $fechaHoy,
            'nombre' => $empleado->nombre,
            'paterno' => $empleado->paterno,
            'materno' => $empleado->materno,
            'correo' => $empleado->correo,
            'id_cliente' => 273,
            'celular' => $empleado->telefono,
            'subproyecto' => $request->subproyecto,
            'pais' => $domicilio->pais ?? null,
            'colonia' => $domicilio->colonia ?? null,
            'calle' => $domicilio->calle ?? null,
            'cp' => $domicilio->cp ?? null,
            'exterior' => $domicilio->num_ext ?? null,
            'interior' => $domicilio->num_int ?? null,
            'privacidad' => $request->privacidad_usuario ?? 0,
        ];

        $sync = [
            'id_cliente_talent' => $request->id_cliente_talent ?? null,
            'id_aspirante_talent' => $request->id_aspirante_talent ?? null,
            'id_empleado_talent' => $empleado->id ?? null,
            'nombre_cliente_talent' =>
                $request->nombre_cliente_talent ?? $nombre,
            'id_portal' => $request->id_portal,
            'id_puesto_talent' => $request->id_puesto_talent ?? null,
            'creacion' => $fechaHoy,
            'edicion' => $fechaHoy,
        ];

        $pruebas = [
            'creacion' => $fechaHoy,
            'edicion' => $fechaHoy,
            'tipo_antidoping' => $request->tipo_antidoping ?? 0,
            'antidoping' => $request->paquete ?? 0,
            'medico' => $request->medicalExam ?? 0,
            'tipo_psicometrico' => $request->psychometric ?? 0,
            'psicometrico' => $request->psychometric ?? 0,
            'id_usuario' => 1,
            'id_cliente' => 273,
            'socioeconomico' => $socioeconomico,
        ];

        $seccion = null;
        $visita = null;
        $documentosSolicitados = [];

        try {
            if ($request->project > 0) {
                $proyecto = ProyectosHistorial::where(
                    'id',
                    $request->project
                )->first();

                if (! $proyecto) {
                    throw new \RuntimeException(
                        'Proyecto no encontrado'
                    );
                }

                $seccion = [
                    'creacion' => $fechaHoy,
                    'id_usuario' => $proyecto->id_usuario,
                    'id_usuario_cliente' => $proyecto->id_usuario_cliente,
                    'id_usuario_subcliente' => $proyecto->id_usuario_subcliente,
                    'proyecto' => $proyecto->proyecto,
                    'secciones' => $proyecto->secciones,
                    'lleva_identidad' => $proyecto->lleva_identidad,
                    'lleva_empleos' => $proyecto->lleva_empleos,
                    'lleva_criminal' => $proyecto->lleva_criminal,
                    'lleva_estudios' => $proyecto->lleva_estudios,
                    'lleva_domicilios' => $proyecto->lleva_domicilios,
                    'lleva_gaps' => $proyecto->lleva_gaps,
                    'lleva_credito' => $proyecto->lleva_credito,
                    'lleva_sociales' => $proyecto->lleva_sociales,
                    'lleva_no_mencionados' => $proyecto->lleva_no_mencionados,
                    'lleva_investigacion' => $proyecto->lleva_investigacion,
                    'lleva_familiares' => $proyecto->lleva_familiares,
                    'lleva_egresos' => $proyecto->lleva_egresos,
                    'lleva_vivienda' => $proyecto->lleva_vivienda,
                    'lleva_prohibited_parties_list' =>
                        $proyecto->lleva_prohibited_parties_list,
                    'lleva_salud' => $proyecto->lleva_salud,
                    'lleva_servicio' => $proyecto->lleva_servicio,
                    'lleva_edad_check' => $proyecto->lleva_edad_check,
                    'lleva_extra_laboral' => $proyecto->lleva_extra_laboral,
                    'lleva_motor_vehicle_records' =>
                        $proyecto->lleva_motor_vehicle_records,
                    'lleva_curp' => $proyecto->lleva_curp,
                    'id_seccion_datos_generales' =>
                        $proyecto->id_seccion_datos_generales,
                    'id_estudios' => $proyecto->id_estudios,
                    'id_seccion_historial_domicilios' =>
                        $proyecto->id_seccion_historial_domicilios,
                    'id_seccion_verificacion_docs' =>
                        $proyecto->id_seccion_verificacion_docs,
                    'id_seccion_global_search' =>
                        $proyecto->id_seccion_global_search,
                    'id_seccion_social' => $proyecto->id_seccion_social,
                    'id_finanzas' => $proyecto->id_finanzas,
                    'id_ref_personales' => $proyecto->id_ref_personales,
                    'id_ref_profesional' => $proyecto->id_ref_profesional,
                    'id_ref_vecinal' => $proyecto->id_ref_vecinal,
                    'id_ref_academica' => $proyecto->id_ref_academica,
                    'id_empleos' => $proyecto->id_empleos,
                    'id_vivienda' => $proyecto->id_vivienda,
                    'id_salud' => $proyecto->id_salud,
                    'id_servicio' => $proyecto->id_servicio,
                    'id_investigacion' => $proyecto->id_investigacion,
                    'id_extra_laboral' => $proyecto->id_extra_laboral,
                    'id_no_mencionados' => $proyecto->id_no_mencionados,
                    'id_referencia_cliente' =>
                        $proyecto->id_referencia_cliente,
                    'id_candidato_empresa' =>
                        $proyecto->id_candidato_empresa,
                    'tiempo_empleos' => $proyecto->tiempo_empleos,
                    'tiempo_criminales' => $proyecto->tiempo_criminales,
                    'tiempo_domicilios' => $proyecto->tiempo_domicilios,
                    'tiempo_credito' => $proyecto->tiempo_credito,
                    'cantidad_ref_profesionales' =>
                        $proyecto->cantidad_ref_profesionales,
                    'cantidad_ref_personales' =>
                        $proyecto->cantidad_ref_personales,
                    'cantidad_ref_vecinales' =>
                        $proyecto->cantidad_ref_vecinales,
                    'cantidad_ref_academicas' =>
                        $proyecto->cantidad_ref_academicas,
                    'cantidad_ref_clientes' =>
                        $proyecto->cantidad_ref_clientes,
                    'tipo_conclusion' => $proyecto->tipo_conclusion,
                    'visita' => $proyecto->visita,
                    'tipo_pdf' => $proyecto->tipo_pdf,
                ];

                if (in_array($proyecto->proyecto, $frases_permitidas)) {
                    $visita = [
                        'creacion' => $fechaHoy,
                        'edicion' => $fechaHoy,
                        'id_usuario' => 1,
                    ];
                }

                $documentosSolicitados =
                    $this->obtenerDocumentosSolicitados(
                        $proyecto,
                        $request,
                        $domicilio->pais
                    );
            }

            $baseUrl = rtrim(
                (string) config('services.rodi_integration.base_url'),
                '/'
            );

            $integrationKey = trim(
                (string) config('integrations.rodi.document_key')
            );

            if ($baseUrl === '' || $integrationKey === '') {
                throw new \RuntimeException(
                    'Integración RODI no configurada'
                );
            }

            $response = Http::withHeaders([
                'X-RODI-Integration-Key' => $integrationKey,
            ])
                ->acceptJson()
                ->timeout(30)
                ->post(
                    $baseUrl . '/empleados/candidato',
                    [
                        'candidato' => $candidato,
                        'sync' => $sync,
                        'pruebas' => $pruebas,
                        'seccion' => $seccion,
                        'visita' => $visita,
                        'documentos_requeridos' =>
                            $documentosSolicitados,
                    ]
                );

            $body = $response->json();

            if (
                ! $response->successful()
                || ! is_array($body)
                || ($body['success'] ?? false) !== true
                || empty($body['data']['id_candidato'])
            ) {
                Log::error(
                    'RODI rechazó registro de empleado como candidato',
                    [
                        'status' => $response->status(),
                        'body' => $body,
                        'id_empleado' => $id_empleado,
                    ]
                );

                return response()->json([
                    'codigo' => 0,
                    'msg' =>
                        'Could not register the candidate, please try again later',
                ], 500);
            }

            $idCandidato = (int) $body['data']['id_candidato'];

            $examEmpleado = new ExamEmpleado([
                'creacion' => $fechaHoy,
                'edicion' => $fechaHoy,
                'employee_id' => $empleado->id,
                'name' => $request->name,
                'id_opcion' => $request->opcion ?? null,
                'descripcion' => $request->descripcion,
                'expiry_date' => $request->expiry_date,
                'expiry_reminder' => $request->expiryReminder,
                'id_candidato' => $idCandidato,
            ]);

            $examEmpleado->save();

            return response()->json([
                'codigo' => 1,
                'msg' => 'The candidate was registered successfully',
            ], 201);
        } catch (\Throwable $e) {
            Log::error(
                'Error registrando empleado como candidato RODI',
                [
                    'id_empleado' => $id_empleado,
                    'message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'codigo' => 0,
                'msg' =>
                    'Could not register the candidate, please try again later',
            ], 500);
        }
    }

    // obtener  el fecha y hora
    public function getCurrentDateTime()
    {
        // Obtiene la fecha y hora actuales en la zona horaria de México
        $currentDateTime = Carbon::now('America/Mexico_City');

        // Formatea la fecha y hora
        return $currentDateTime->format('Y-m-d H:i:s');
    }

    public function obtenerDocumentosSolicitados($seccion, $request, $pais)
    {
        $documentosSolicitados = [];

        // Agregar documentos según las condiciones de la sección
        if ($seccion->lleva_empleos == 1) {
            array_push($documentosSolicitados, 9);
        }
        if ($seccion->lleva_estudios == 1) {
            array_push($documentosSolicitados, 7);
        }
        if ($seccion->lleva_criminal == 1) {
            array_push($documentosSolicitados, 12);
        }
        if ($seccion->lleva_domicilios == 1) {
            array_push($documentosSolicitados, 2);
        }
        if ($seccion->lleva_credito == 1) {
            array_push($documentosSolicitados, 28);
        }
        if ($seccion->lleva_prohibited_parties_list == 1) {
            array_push($documentosSolicitados, 30);
        }
        if ($seccion->lleva_motor_vehicle_records == 1) {
            array_push($documentosSolicitados, 44);
        }
        if ($request->input('migracion') == 1) {
            array_push($documentosSolicitados, 20);
        }
        if ($request->input('curp') == 1) {
            array_push($documentosSolicitados, 5);
        }

        // Documentos obligatorios y opcionales
        array_push($documentosSolicitados, 3); // ID
        array_push($documentosSolicitados, 14); // Pasaporte
        if ($pais == 'México' || empty($pais)) {
            array_push($documentosSolicitados, 45); // Constancia fiscal
        }

        // Documentos extras
        $cant_extras = $request->input('extras');
        if (!empty($cant_extras)) {
            foreach ($cant_extras as $extra) {
                if (!in_array($extra, $documentosSolicitados)) {
                    array_push($documentosSolicitados, $extra);
                }
            }
        }

        // Inicializa el arreglo de documentos requeridos
        $docs_requeridos = [];

        foreach ($documentosSolicitados as $idDocumento) {
            $row = CatDocumentoRequerimiento::where('id', $idDocumento)->first();
            if (!$row) {
                continue; // Asegúrate de que el documento existe
            }

            $solicitado = $row->solicitado;

            // Verifica si se cumple alguna condición específica para modificar $solicitado
            if ($idDocumento == 12 && $seccion->lleva_criminal == 1 && $pais != 'México' && $pais != '') {
                $solicitado = 1;
            }

            // Construye un arreglo con los datos del documento actual
            $documento = [
                'id_tipo_documento' => $row->id_tipo_documento,
                'nombre_espanol' => $row->nombre_espanol,
                'nombre_ingles' => $row->nombre_ingles,
                'label_ingles' => $row->label_ingles,
                'div_id' => $row->div_id,
                'input_id' => $row->input_id,
                'multiple' => $row->multiple,
                'width' => $row->width,
                'height' => $row->height,
                'obligatorio' => $row->obligatorio,
                'solicitado' => $solicitado,
            ];

            // Agrega el documento actual al arreglo de documentos requeridos
            $docs_requeridos[] = $documento;
        }

        return $docs_requeridos;
    }

}
