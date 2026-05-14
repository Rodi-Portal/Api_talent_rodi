<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Checador\ChecadaValidationService;

class Chentroller extends Controller
{
    public function store(Request $request)
    {
        $data = $request->all();

        $validator = app(ChecadaValidationService::class);

        $resultado = $validator->validar($data);

        if (!$resultado['ok']) {
            return response()->json($resultado, 422);
        }

        $id = DB::table('checadas')->insertGetId([
            'id_portal' => $data['id_portal'],
            'id_cliente' => $data['id_cliente'],
            'id_empleado' => $data['id_empleado'],

            'fecha' => date('Y-m-d', strtotime($data['check_time'])),
            'check_time' => $data['check_time'],

            'tipo' => $data['tipo'] ?? 'in',
            'clase' => $data['clase'] ?? 'work',

            'dispositivo' => $data['dispositivo'] ?? null,
            'origen' => $data['origen'] ?? 'geoloc',

            'observacion' => $resultado['motivo'] ?? null,

            'hash' => sha1(
                $data['id_empleado'] .
                $data['check_time'] .
                microtime(true)
            ),

            // NUEVOS CAMPOS
            'id_asignacion' => $resultado['id_asignacion'],
            'id_ubicacion' => $resultado['id_ubicacion'] ?? null,
            'metodo_validacion' => implode(',', $resultado['metodos_requeridos'] ?? []),
            'estatus_validacion' => $resultado['estatus_validacion'],
            'distancia_metros' => $resultado['distancia_metros'] ?? null,
            'precision_metros' => $resultado['precision_metros'] ?? null,
            'latitud' => $resultado['latitud'] ?? null,
            'longitud' => $resultado['longitud'] ?? null,
        ]);

        // 👇 Aquí puedes guardar evidencia (foto, QR, etc.)

        return response()->json([
            'ok' => true,
            'id' => $id,
            'mensaje' => 'Checada registrada correctamente',
            'estatus' => $resultado['estatus_validacion'],
        ]);
    }
}