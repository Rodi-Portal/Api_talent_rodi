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
use Illuminate\Support\Facades\Log;

class ApiCandidatoConProyectoPrevioController extends Controller
{
    public function store(Request $request)
    {
        $date = Carbon::now()->setTimezone('America/Mexico_City');

        $frases_permitidas = ['General Nacional', 'Laborales Nacional'];

        // 🔒 Normalizar arrays (CLAVE para producción)
        $secciones  = $request->input('secciones', []);
        $documentos = $request->input('documentos', []);

        DB::beginTransaction();

        try {

            /* ==========================
             *  CANDIDATO
             * ========================== */
            $candidato = new Candidato([
                'creacion'        => $date,
                'edicion'         => $date,
                'id_usuario'      => 1,
                'fecha_alta'      => $date,
                'tipo_formulario' => $request->tipo_formulario,
                'nombre'          => $request->nombre,
                'paterno'         => $request->paterno,
                'materno'         => $request->materno,
                'correo'          => $request->correo,
                'token'           => $request->token,
                'id_cliente'      => $request->id_cliente,
                'celular'         => $request->celular,
                'subproyecto'     => $request->subproyecto_previo ?? null,
                'pais'            => $request->pais_previo ?? null,
                'privacidad'      => $request->privacidad_usuario ?? 0,
            ]);
            $candidato->save();

            /* ==========================
             *  CANDIDATO SYNC
             * ========================== */
            $candidatoSync = new CandidatoSync([
                'id_cliente_talent'     => $request->id_cliente_talent ?? null,
                'id_aspirante_talent'   => $request->id_aspirante_talent ?? 0,
                'nombre_cliente_talent' => $request->nombre_cliente_talent ?? null,
                'id_portal'             => $request->id_portal ?? null,
                'id_candidato_rodi'     => $candidato->id,
                'id_puesto_talent'      => $request->id_puesto_talent ?? null,
                'creacion'              => $date,
                'edicion'               => $date,
            ]);
            $candidatoSync->save();

            /* ==========================
             *  PRUEBAS
             * ========================== */
            $candidatoPruebas = new CandidatoPruebas([
                'creacion'          => $request->creacion,
                'edicion'           => $request->edicion,
                'tipo_antidoping'   => $request->tipo_antidoping ?? 0,
                'antidoping'        => $request->antidoping ?? 0,
                'medico'            => $request->medico ?? 0,
                'tipo_psicometrico' => $request->tipo_psicometrico ?? 0,
                'psicometrico'      => $request->psicometrico ?? 0,
                'id_usuario'        => 1,
                'id_candidato'      => $candidato->id,
                'id_cliente'        => 273,
                'socioeconomico'    => 1,
            ]);
            $candidatoPruebas->save();

            /* ==========================
             *  SECCIONES
             * ========================== */
            $candidatoSeccion = new CandidatoSeccion([
                'creacion'        => $request->creacion,
                'id_usuario'      => 1,
                'id_candidato'    => $candidato->id,

                'proyecto'        => $secciones['proyecto'] ?? null,
                'secciones'       => $secciones['secciones'] ?? '',

                'lleva_identidad' => $secciones['lleva_identidad'] ?? 0,
                'lleva_empleos'   => $secciones['lleva_empleos'] ?? 0,
                'lleva_criminal'  => $secciones['lleva_criminal'] ?? 0,
                'lleva_estudios'  => $secciones['lleva_estudios'] ?? 0,
                'lleva_domicilios'=> $secciones['lleva_domicilios'] ?? 0,
                'lleva_gaps'      => $secciones['lleva_gaps'] ?? 0,
                'lleva_credito'   => $secciones['lleva_credito'] ?? 0,
                'lleva_sociales'  => $secciones['lleva_sociales'] ?? 0,

                'tiempo_empleos'  => $secciones['tiempo_empleos'] ?? null,
                'tipo_pdf'        => $secciones['tipo_pdf'] ?? null,
            ]);
            $candidatoSeccion->save();

            /* ==========================
             *  VISITA (si aplica)
             * ========================== */
            $nombre_proyecto = $secciones['proyecto'] ?? '';
            if (in_array($nombre_proyecto, $frases_permitidas)) {
                $visita = new Visita([
                    'creacion'     => $date,
                    'edicion'      => $date,
                    'id_usuario'   => 1,
                    'id_candidato' => $candidato->id,
                ]);
                $visita->save();
            }

            /* ==========================
             *  DOCUMENTOS
             * ========================== */
            foreach ($documentos as $doc) {

                if (!isset($doc['id_tipo_documento'])) {
                    continue;
                }

                $documento = new CandidatoDocumentoRequerido([
                    'id_candidato'      => $candidato->id,
                    'id_tipo_documento' => $doc['id_tipo_documento'],
                    'nombre_espanol'    => $doc['nombre_espanol'] ?? null,
                    'nombre_ingles'     => $doc['nombre_ingles'] ?? null,
                    'label_ingles'      => $doc['label_ingles'] ?? null,
                    'div_id'            => $doc['div_id'] ?? null,
                    'input_id'          => $doc['input_id'] ?? null,
                    'multiple'          => $doc['multiple'] ?? 0,
                    'width'             => $doc['width'] ?? null,
                    'height'            => $doc['height'] ?? null,
                    'obligatorio'       => $doc['obligatorio'] ?? 0,
                    'solicitado'        => $doc['solicitado'] ?? 0,
                ]);

                $documento->save();
            }

            DB::commit();

            return response()->json([
                'codigo' => 1,
                'msg'    => 'El candidato se registró correctamente'
            ], 201);

        } catch (\Throwable $e) {

            DB::rollBack();
            Log::error('Error registro candidato', [
                'error' => $e->getMessage(),
                'line'  => $e->getLine()
            ]);

            return response()->json([
                'codigo' => 0,
                'msg'    => $e->getMessage()
            ], 500);
        }
    }
}
