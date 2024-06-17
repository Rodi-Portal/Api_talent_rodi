<?php

namespace App\Http\Controllers;

use App\Models\Candidato;

use App\Models\CandidatoSync;

use App\Models\CandidatoPruebas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiCandidatoSinEseController extends Controller
{
    public function store(Request $request)
    {
        // Validación de los datos recibidos
        $request->validate([
            'creacion' => 'required|date',
            'edicion' => 'required|date',
            'tipo_usuario' => 'required|integer',
            'id_usuario' => 'required|integer',
            'fecha_alta' => 'required|date',
            'tipo_formulario' => 'required|integer',
            'nombre' => 'required|string|max:255',
            'paterno' => 'required|string|max:255',
            'materno' => 'nullable|string|max:255',
            'correo' => 'required|email|max:255',
            'id_cliente' => 'required|integer',
            'celular' => 'required|string|max:20',
            'subproyecto' => 'nullable|string|max:255',
            'pais' => 'required|string|max:255',
            'privacidad' => 'required|integer',

            'medico' => 'required|integer',

            'id_cliente_talent' => 'required|integer',
            'nombre_cliente_talent' => 'required|string|max:255',

            'id_usuario_talent' => 'required|integer',

            'token' => 'nullable|string|max:255',

            'tipo_antidoping' => 'required|integer',
            'antidoping' => 'required|integer',

            'psicometrico' => 'required|integer',
        ]);

        $tipo_usuario = '';
        $tipo_usuario_talent = '';

        // Evaluar la condición basada en el valor de usuario
        switch ($request->tipo_usuario) {
            case 1:

                $tipo_usuario_talent = 'id_usuario_talent';
                break;
            case 2:

                $tipo_usuario_talent = 'id_usuario_cliente_talent';
                break;

            default:
                break;
        }

        try {
            DB::beginTransaction(); // Inicia la transacción
            $candidato = new Candidato([
                'creacion' => $request->creacion,
                'edicion' => $request->edicion,
                'tipo_formulario' => $request->tipo_formulario,
                'id_usuario' => $request->id_usuario,
                'fecha_alta' => $request->creacion,

                'nombre' => $request->nombre,
                'paterno' => $request->paterno,
                'materno' => $request->materno,
                'correo' => $request->correo,
                'id_cliente' => $request->id_cliente,
                'celular' => $request->celular,
                'subproyecto' => $request->subproyecto,
                'pais' => $request->pais_previo,
                'privacidad' => $request->privacidad_usuario ?? 0,
            ]);
            // Crear un nuevo candidato en la tabla 'candidato'

            $candidato->save();

            

            // Crear un registro en la tabla 'candidato_sync'
            $candidatoSync = new CandidatoSync([
            'id_cliente_talent' => $request->id_cliente_talent,
             $tipo_usuario_talent => $request->id_usuario_talent,
            'id_aspirante_talent' => $request->id_aspirante_talent ?? 0,
            'nombre_cliente_talent' => $request->nombre_cliente_talent,
            'id_portal' => $request->id_portal,
            'id_candidato_rodi' => $candidato->id,
            'id_puesto_talent' => $request->id_puesto_talent,
            'creacion' => $request->creacion,
            'edicion' => $request->edicion,
            ]);

            $candidatoSync->save();

            // Crear un registro en la tabla 'candidato_sync'

          //  Crear un registro en la tabla 'candidato_pruebas'
            $candidatoPruebas = new CandidatoPruebas();
            $candidatoPruebas->creacion = $request->creacion;
            $candidatoPruebas->edicion = $request->edicion;
            $candidatoPruebas->tipo_antidoping = $request->tipo_antidoping;
            $candidatoPruebas->antidoping = $request->antidoping;
            $candidatoPruebas->medico = $request->medico;
            $candidatoPruebas->id_usuario = 1; // Ajusta este valor según sea necesario
            $candidatoPruebas->id_candidato = $candidato->id;
            $candidatoPruebas->id_cliente = $request->id_cliente;
            $candidatoPruebas->socioeconomico = 0;
            $candidatoPruebas->save(); 

            DB::commit(); // Confirma la transacción si todo ha sido exitoso

            return response()->json(['codigo' => 1, 'msg' => 'Datos guardados correctamente'], 201);
        } catch (\Exception $e) {
            DB::rollback(); // Revierte la transacción si ocurrió algún error
            \Log::error($e->getMessage());
            return response()->json(['codigo' => 0, 'msg' => 'No se pudo Registrar el Candidato intentalo de nuevo mas tarde '], 500);
        }
    }
}
