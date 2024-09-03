<?php

namespace App\Http\Controllers;

use App\Models\Candidato;
use App\Models\CandidatoDocumentoRequerido;
use App\Models\CandidatoPruebas;
use App\Models\CandidatoSeccion;
use App\Models\CandidatoSync;
use App\Models\Visita;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiCandidatoConProyectoPrevioController extends Controller
{
    public function store(Request $request)
    {
        // Obtener la fecha y hora actual en la zona horaria predeterminada del servidor
        $date = Carbon::now()->setTimezone('America/Mexico_City'); 

        // Frases de proyecto a comprobar
   
        $frases_permitidas = ['General Nacional', 'Laborales Nacional'];

        DB::beginTransaction();

        try {
            // Crear un registro en la tabla 'candidato'
            $candidato = new Candidato([
                'creacion' => $request->creacion,
                'edicion' => $request->edicion,
                'id_usuario' => 1,
                'fecha_alta' => $date,
                'tipo_formulario' => $request->tipo_formulario,
                'nombre' => $request->nombre,
                'paterno' => $request->paterno,
                'materno' => $request->materno,
                'correo' => $request->correo,
                'token' => $request->token,
                'id_cliente' => $request->id_cliente,
                'celular' => $request->celular,
                'subproyecto' => $request->subproyecto_previo,
                'pais' => $request->pais_previo,
                'privacidad' => $request->privacidad_usuario ?? 0,
            ]);

            $candidato->save();

            // Crear un registro en la tabla 'candidato_sync'
            $candidatoSync = new CandidatoSync([
                'id_cliente_talent' => $request->id_cliente_talent,
                'id_aspirante_talent' => $request->id_aspirante_talent ?? 0,
                'nombre_cliente_talent' => $request->nombre_cliente_talent,
                'id_portal' => $request->id_portal,
                'id_candidato_rodi' => $candidato->id,
                'id_puesto_talent' => $request->id_puesto_talent,
                'creacion' => $request->creacion,
                'edicion' => $request->edicion,
            ]);

            $candidatoSync->save();

            // Crear un registro en la tabla 'candidato_pruebas'
            $candidatoPruebas = new CandidatoPruebas([
                'creacion' => $request->creacion,
                'edicion' => $request->edicion,
                'tipo_antidoping' => $request->tipo_antidoping,
                'antidoping' => $request->antidoping,
                'medico' => $request->medico,
                'tipo_psicometrico' => $request->tipo_psicometrico,
                'psicometrico' => $request->psicometrico,
                'id_usuario' => 1, // Ajusta este valor según sea necesario
                'id_candidato' => $candidato->id,
                'id_cliente' => 273,
                'socioeconomico' => 1, // O el valor que estés recibiendo
            ]);

            $candidatoPruebas->save();

            $candidatoSeccion = new CandidatoSeccion([
                'creacion' => $request->creacion,
                'id_usuario' => 1,
                'id_candidato' => $candidato->id,
                'proyecto' => $request->secciones['proyecto'],
                'secciones' => $request->secciones['secciones'],
                'lleva_identidad' => $request->secciones['lleva_identidad'],
                'lleva_empleos' => $request->secciones['lleva_empleos'],
                'lleva_criminal' => $request->secciones['lleva_criminal'],
                'lleva_estudios' => $request->secciones['lleva_estudios'],
                'lleva_domicilios' => $request->secciones['lleva_domicilios'],
                'lleva_gaps' => $request->secciones['lleva_gaps'],
                'lleva_credito' => $request->secciones['lleva_credito'],
                'lleva_sociales' => $request->secciones['lleva_sociales'],
                'lleva_no_mencionados' => $request->secciones['lleva_no_mencionados'],
                'lleva_investigacion' => $request->secciones['lleva_investigacion'],
                'lleva_familiares' => $request->secciones['lleva_familiares'],
                'lleva_egresos' => $request->secciones['lleva_egresos'],
                'lleva_vivienda' => $request->secciones['lleva_vivienda'],
                'lleva_prohibited_parties_list' => $request->secciones['lleva_prohibited_parties_list'],
                'lleva_salud' => $request->secciones['lleva_salud'],
                'lleva_servicio' => $request->secciones['lleva_servicio'],
                'lleva_edad_check' => $request->secciones['lleva_edad_check'],
                'lleva_extra_laboral' => $request->secciones['lleva_extra_laboral'],
                'lleva_motor_vehicle_records' => $request->secciones['lleva_motor_vehicle_records'],
                'lleva_curp' => $request->secciones['lleva_curp'],
                'id_seccion_datos_generales' => $request->secciones['id_seccion_datos_generales'],
                'id_estudios' => $request->secciones['id_estudios'],
                'id_seccion_historial_domicilios' => $request->secciones['id_seccion_historial_domicilios'] ?? null,
                'id_seccion_verificacion_docs' => $request->secciones['id_seccion_verificacion_docs'] ?? null,
                'id_seccion_global_search' => $request->secciones['id_seccion_global_search'] ?? null,
                'id_seccion_social' => $request->secciones['id_seccion_social'],
                'id_finanzas' => $request->secciones['id_finanzas'] ?? null,
                'id_ref_personales' => $request->secciones['id_ref_personales'] ?? null,
                'id_ref_profesional' => $request->secciones['id_ref_profesional'] ?? null,
                'id_ref_vecinal' => $request->secciones['id_ref_vecinal'] ?? null,
                'id_ref_academica' => $request->secciones['id_ref_academica'] ?? null,
                'id_empleos' => $request->secciones['id_empleos'] ?? null,
                'id_vivienda' => $request->secciones['id_vivienda'] ?? null,
                'id_salud' => $request->secciones['id_salud'] ?? null,
                'id_servicio' => $request->secciones['id_servicio'] ?? null,
                'id_investigacion' => $request->secciones['id_investigacion'] ?? null,
                'id_extra_laboral' => $request->secciones['id_extra_laboral'] ?? null,
                'id_no_mencionados' => $request->secciones['id_no_mencionados'] ?? null,
                'id_referencia_cliente' => $request->secciones['id_referencia_cliente'] ?? null,
                'id_candidato_empresa' => $request->secciones['id_candidato_empresa'] ?? null,
                'tiempo_empleos' => $request->secciones['tiempo_empleos'],
                'tiempo_criminales' => $request->secciones['tiempo_criminales'] ?? null,
                'tiempo_domicilios' => $request->secciones['tiempo_domicilios'] ?? null,
                'tiempo_credito' => $request->secciones['tiempo_credito'] ?? null,
                'cantidad_ref_profesionales' => $request->secciones['cantidad_ref_profesionales'],
                'cantidad_ref_personales' => $request->secciones['cantidad_ref_personales'],
                'cantidad_ref_vecinales' => $request->secciones['cantidad_ref_vecinales'],
                'cantidad_ref_academicas' => $request->secciones['cantidad_ref_academicas'] ?? null,
                'cantidad_ref_clientes' => $request->secciones['cantidad_ref_clientes'] ?? null,
                'tipo_conclusion' => $request->secciones['tipo_conclusion'],
                'visita' => $request->secciones['visita'] ?? null,
                'tipo_pdf' => $request->secciones['tipo_pdf'],
            ]);

            $candidatoSeccion->save();

            $nombre_proyecto = $request->secciones['proyecto'];
            if (in_array($nombre_proyecto, $frases_permitidas)) {
                // Crear un registro en la tabla 'visita'
                $visita = new Visita([
                    'creacion' => $date,
                    'edicion' => $date,
                    'id_usuario' => 1, // Ajusta este valor según sea necesario
                    'id_candidato' => $candidato->id,
                    // Otros campos pueden quedar en null si no se reciben en el request
                ]);
                $visita->save();
            }
            // Continuar con los siguientes pasos de inserción

            foreach ($request->documentos as $documentoData) {
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

            DB::commit();

            return response()->json(['codigo' => 1, 'msg' => 'El candidato se registro correctamente'], 201);
        } catch (\Exception $e) {
            DB::rollback(); // Revierte la transacción si ocurrió algún error
            \Log::error($e->getMessage());
            return response()->json(['codigo' => 0, 'msg' => 'No se pudo Registrar el Candidato intentalo de nuevo mas  tarde '], 500);
        }
    }
}
