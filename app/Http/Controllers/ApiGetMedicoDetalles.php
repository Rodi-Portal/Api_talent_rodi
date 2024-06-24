<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Medico; // Importa el modelo Medico
use App\Models\Candidato; 
use Illuminate\Support\Facades\DB;

class ApiGetMedicoDetalles extends Controller
{
    public function getDatosMedico($id_medico)
    {
        // Realizar la consulta
       $datosMedico = \DB::table('medico as m')
            ->select(
                'm.*',
                'm.imagen_historia_clinica as imagen',
                \DB::raw("CONCAT(c.nombre, ' ', c.paterno, ' ', c.materno) as candidato"),
                'c.edad',
                'c.genero',
                'c.fecha_nacimiento',
                'c.estado_civil',
               'c.id_grado_estudio'
            )
            ->join('candidato as c', 'm.id_candidato', '=', 'c.id')
            ->where('m.id', $id_medico)
            ->first();

        // Verificar si se encontraron datos
        if ($datosMedico) {
            // Filtrar campos no nulos
            $datosMedico = array_filter((array)$datosMedico, function($value) {
                return !is_null($value);
            });
            return response()->json($datosMedico);
        } else {
            return response()->json(['message' => 'Medico no encontrado'], 404);
        }
      
    }
}
