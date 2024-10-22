<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Candidato;
use App\Models\CandidatoDocumentoRequerido;
use App\Models\CandidatoPruebas;
use App\Models\CandidatoSeccion;
use App\Models\CandidatoSync;
use App\Models\CatDocumentoRequerimiento;
use App\Models\Empleado;
use App\Models\Visita;
use App\Models\ExamEmpleado;
use App\Models\Doping;
use App\Models\ProyectosHistorial;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EviarEmpleadoRodi extends Controller
{
    // Otras funciones...

    public function registrarCandidato(Request $request)
    {
        // Obtén los datos del request
        //$data = $request->all();
        $id_empleado = $request->id_empleado;
        $frases_permitidas = ['General Nacional', 'Laborales Nacional'];

        // Imprime los datos en el log

        // Traer los datos del empleado
        $empleado = Empleado::with('domicilioEmpleado')->where('id', $id_empleado)->first();
        $domicilio = $empleado->domicilioEmpleado;
        $tipo_formulario = (Str::slug($domicilio->pais, '-', 'es') !== Str::slug('Mexico', '-', 'es')) ? 4 : 3;

        if ($request->project == 0) {
            $tipo_formulario = 0;
        }

        /*  aqui se llena el arreglo de la tabla  candidato */
        DB::beginTransaction();

        try {
            if ($empleado) {
                // Procesar los datos del empleado

                $fechaHoy = $this->getCurrentDateTime();
                $candidato = new Candidato([
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
                    'estado' => $domicilio->estado ?? null,
                    'ciudad' => $domicilio->ciudad ?? null,
                    'colonia' => $domicilio->colonia ?? null,
                    'calle' => $domicilio->calle ?? null,
                    'cp' => $domicilio->cp ?? null,
                    'exterior' => $domicilio->num_ext ?? null, // Verifica aquí
                    'interior' => $domicilio->num_int ?? null, // Verifica aquí
                    'privacidad' => $request->privacidad_usuario ?? 0,
                ]);
                if($request->project > 0){
                    $socioeconomico = 1;
                }else{
                    $socioeconomico = 0;
                }
                $candidato->save();

                /** aqui se llena   el  arreglo para la tabla candidato_sync */
                $candidatoSync = new CandidatoSync([
                    'id_cliente_talent' => $request->id_cliente_talent ?? null,
                    'id_aspirante_talent' => $request->id_aspirante_talent ?? null,
                    'id_empleado_talent'=> $empleado->id ?? null,
                    'nombre_cliente_talent' => $request->nombre_cliente_talent ?? 'ModuloEmpleados',
                    'id_portal' => $request->id_portal,
                    'id_candidato_rodi' => $candidato->id,
                    'id_puesto_talent' => $request->id_puesto_talent ?? null,
                    'creacion' => $fechaHoy,
                    'edicion' => $fechaHoy,
                ]);
                
                $candidatoSync->save();

                // aqui se   genera  el arreglo para  la tabla  candidato_pruebas
                $candidatoPruebas = new CandidatoPruebas([
                    'creacion' => $fechaHoy,
                    'edicion' => $fechaHoy,
                    'tipo_antidoping' => $request->tipo_antidoping ?? 0,
                    'antidoping' => $request->paquete,
                    'medico' => $request->medicalExam ?? 0,
                    'tipo_psicometrico' => $request->psychometric ?? 0,
                    'psicometrico' => $request->psychometric ?? 0,
                    'id_usuario' => 1, // Ajusta este valor según sea necesario
                    'id_candidato' => $candidato->id ?? 0,
                    'id_cliente' => 273,
                    'socioeconomico' => $socioeconomico ?? 0, // O el valor que estés recibiendo
                ]);

                $candidatoPruebas->save();


                $examEmpleado =  new ExamEmpleado([
                  'creacion'=> $fechaHoy,
                  'edicion'=> $fechaHoy,
                  'employee_id'=>$empleado->id,
                  'name'=> $request->name,
                  'id_opcion' => $request->opcion ?? null,
                  'descripcion'=>$request->descripcion,
                  'expiry_date'=>$request->expiry_date,
                  'expiry_reminder'=> $request->expiryReminder,
                  'id_candidato'=>$candidato->id ?? null,


                ]);
                $examEmpleado->save();
            
              
                /*  aqui se llena el arreglo de la tabla  candidato_seccion */
                if ($request->project > 0) {
                    $proyecto = ProyectosHistorial::where('id', $request->project)->first();
                    $candidatoSeccion = new CandidatoSeccion([
                        'creacion' => $fechaHoy, // O usa el método que tengas para obtener la fecha
                        'id_usuario' => $proyecto->id_usuario,
                        'id_usuario_cliente' => $proyecto->id_usuario_cliente,
                        'id_usuario_subcliente' => $proyecto->id_usuario_subcliente,
                        'id_candidato' => $candidato->id, // Asegúrate de pasar este dato en el request
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
                        'lleva_prohibited_parties_list' => $proyecto->lleva_prohibited_parties_list,
                        'lleva_salud' => $proyecto->lleva_salud,
                        'lleva_servicio' => $proyecto->lleva_servicio,
                        'lleva_edad_check' => $proyecto->lleva_edad_check,
                        'lleva_extra_laboral' => $proyecto->lleva_extra_laboral,
                        'lleva_motor_vehicle_records' => $proyecto->lleva_motor_vehicle_records,
                        'lleva_curp' => $proyecto->lleva_curp,
                        'id_seccion_datos_generales' => $proyecto->id_seccion_datos_generales,
                        'id_estudios' => $proyecto->id_estudios,
                        'id_seccion_historial_domicilios' => $proyecto->id_seccion_historial_domicilios,
                        'id_seccion_verificacion_docs' => $proyecto->id_seccion_verificacion_docs,
                        'id_seccion_global_search' => $proyecto->id_seccion_global_search,
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
                        'id_referencia_cliente' => $proyecto->id_referencia_cliente,
                        'id_candidato_empresa' => $proyecto->id_candidato_empresa,
                        'tiempo_empleos' => $proyecto->tiempo_empleos,
                        'tiempo_criminales' => $proyecto->tiempo_criminales,
                        'tiempo_domicilios' => $proyecto->tiempo_domicilios,
                        'tiempo_credito' => $proyecto->tiempo_credito,
                        'cantidad_ref_profesionales' => $proyecto->cantidad_ref_profesionales,
                        'cantidad_ref_personales' => $proyecto->cantidad_ref_personales,
                        'cantidad_ref_vecinales' => $proyecto->cantidad_ref_vecinales,
                        'cantidad_ref_academicas' => $proyecto->cantidad_ref_academicas,
                        'cantidad_ref_clientes' => $proyecto->cantidad_ref_clientes,
                        'tipo_conclusion' => $proyecto->tipo_conclusion,
                        'visita' => $proyecto->visita,
                        'tipo_pdf' => $proyecto->tipo_pdf,
                    ]);

                    $candidatoSeccion->save();

                    $nombre_proyecto = $candidatoSeccion['proyecto'];
                    if (in_array($nombre_proyecto, $frases_permitidas)) {
                        // Crear un registro en la tabla 'visita'
                        $visita = new Visita([
                            'creacion' => $fechaHoy,
                            'edicion' => $fechaHoy,
                            'id_usuario' => 1, // Ajusta este valor según sea necesario
                            'id_candidato' => $candidato->id,
                            // Otros campos pueden quedar en null si no se reciben en el request
                        ]);
                        $visita->save();
                    }

                    $documentosSolicitados = $this->obtenerDocumentosSolicitados($candidatoSeccion, $request, $domicilio->pais);

                    foreach ($documentosSolicitados as $documentoData) {
                        $documentoRequerido = new CandidatoDocumentoRequerido([
                            'id_candidato' => $candidato->id,
                            'id_tipo_documento' => $documentoData['id_tipo_documento'],
                            'nombre_espanol' => $documentoData['nombre_espanol'],
                            'nombre_ingles' => $documentoData['nombre_ingles'],
                            'label_ingles' => $documentoData['label_ingles'],
                            'div_id' => $documentoData['div_id'],
                            'input_id' => $documentoData['input_id'],
                            'multiple' => $documentoData['multiple'],
                            'width' => $documentoData['width'],
                            'height' => $documentoData['height'],
                            'obligatorio' => $documentoData['obligatorio'],
                            'solicitado' => $documentoData['solicitado'],
                        ]);

                       $documentoRequerido->save();
                    }
                    // Guardar en la base de datos
                    //$candidatoSeccion->save();
                /*    Log::info('Datos del documentoRequerido: ' . print_r($documentoRequerido->toArray(), true));
                    Log::info('Datos del visita: ' . print_r($visita->toArray(), true));

                    Log::info('Datos del candidato: ' . print_r($candidato->toArray(), true));
                    Log::info('Datos del candidatoSync: ' . print_r($candidatoSync->toArray(), true));
                    Log::info('Datos del candidatoPruebas: ' . print_r($candidatoPruebas->toArray(), true));
                    Log::info('Datos del candidatoSeccion: ' . print_r($candidatoSeccion->toArray(), true));
                    Log::info('Datos del candidato: ' . print_r($documentosSolicitados, true));
*/
                }

            } else {
              return response()->json(['codigo' => 0, 'msg' => 'Could not register the candidate, please try again later'], 500);

              Log::warning('Employee not found with id_empleado:', ['id_empleado' => $id_empleado]);
            }
            DB::commit();

            return response()->json(['codigo' => 1, 'msg' => 'The candidate was registered successfully'], 201);
          } catch (Exception $e) {
            DB::rollback(); // Revierte la transacción si ocurrió algún error
            Log::error($e->getMessage());
            return response()->json(['codigo' => 0, 'msg' => 'Could not register the candidate, please try again later'], 500);
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
