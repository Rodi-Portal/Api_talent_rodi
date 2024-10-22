<?php

namespace App\Http\Controllers;

use App\Models\Candidato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Añade esta línea

class ApiGetCandidatosByCliente extends Controller
{
    /**
     * Display a listing of the resource filtered by id_cliente_talent.
     *
     * @param int $id_cliente_talent
     * @return \Illuminate\Http\Response
     */
    public function getByClienteTalent($id_cliente_talent)
    {
        // Validar que id_cliente_talent sea un entero
        if (!is_numeric($id_cliente_talent)) {
            return response()->json(['error' => 'Invalid id_cliente_talent'], 400);
        }

        // Realizar la consulta combinada de Candidato y CandidatoSync
        $results = Candidato::leftJoin('candidato_sync AS CSY', 'candidato.id', '=', 'CSY.id_candidato_rodi')
            ->leftJoin('usuario AS US', 'US.id', '=', 'candidato.id_usuario')
            ->leftJoin('candidato_seccion AS CAS', 'CAS.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_bgc AS BGC', 'BGC.id_candidato', '=', 'candidato.id')
            ->leftJoin('candidato_pruebas AS CAP', 'CAP.id_candidato', '=', 'candidato.id')
            ->leftJoin('doping AS DOP', 'DOP.id_candidato', '=', 'candidato.id')
            ->leftJoin('medico AS MED', 'MED.id_candidato', '=', 'candidato.id')
            ->leftJoin('psicometrico AS PSI', 'PSI.id_candidato', '=', 'candidato.id')

            ->where('CSY.id_cliente_talent', $id_cliente_talent)
            ->select(
                'candidato.*',
                'candidato.id AS id',
                DB::raw("CONCAT(COALESCE(candidato.nombre, ''), ' ', COALESCE(candidato.paterno, ''), ' ', COALESCE(candidato.materno, '')) as candidato"),
                'candidato.nombre AS nombre',
                'candidato.paterno AS paterno',
                'candidato.materno AS materno',
                'candidato.celular AS celular',
                'candidato.correo AS correo',
                'candidato.fecha_contestado AS fecha_contestado',
                'candidato.fecha_alta AS fecha_alta',
                'candidato.fecha_documentos AS fecha_documentos',
                'candidato.tiempo_parcial AS tiempo_parcial',
                'candidato.cancelado AS cancelado',

                'CSY.creacion AS creacion',
                'CSY.edicion AS edicion',
                DB::raw("CONCAT(US.nombre, ' ', US.paterno, ' ', COALESCE(US.materno, '')) as usuario"),
                'CAS.tipo_conclusion',
                'CAS.proyecto AS nombre_proyecto',
                'BGC.creacion AS fecha_bgc',

                'CAP.status_doping as doping_hecho',
                'CAP.tipo_antidoping',
                'CAP.medico',
                'CAP.psicometrico',
                'CAP.socioeconomico',
                
                'MED.id AS idMedico',
                'MED.imagen_historia_clinica AS imagen',
                'MED.conclusion',
                'MED.descripcion',
                'MED.archivo_examen_medico',

                'PSI.id as idPsicometrico',
                'PSI.archivo',
           
                'DOP.id as idDoping',
                'DOP.fecha_resultado',
                'DOP.resultado as resultado_doping',
                'DOP.status as statusDoping',

            )
            ->get();
           // Log::info('Datos del candidato: ' . print_r($results->toArray(), true));

        return response()->json($results);

    }

}
