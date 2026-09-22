<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiCandidatoSinEseController extends Controller
{
    public function store(Request $request)
    {
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

        $baseUrl = rtrim(
            (string) config('services.rodi_integration.base_url'),
            '/'
        );

        $integrationKey = trim(
            (string) config('integrations.rodi.document_key')
        );

        if ($baseUrl === '' || $integrationKey === '') {
            Log::error(
                'Integración RODI no configurada para registrar candidato sin ESE'
            );

            return response()->json([
                'codigo' => 0,
                'msg' => 'No se pudo Registrar el Candidato intentalo de nuevo mas tarde ',
            ], 503);
        }

        try {
            $response = Http::withHeaders([
                'X-RODI-Integration-Key' => $integrationKey,
            ])
                ->acceptJson()
                ->timeout(30)
                ->post(
                    $baseUrl . '/candidatos/sin-ese',
                    $request->all()
                );
        } catch (\Throwable $e) {
            Log::error(
                'Error de conexión con RODI al registrar candidato sin ESE',
                [
                    'message' => $e->getMessage(),
                ]
            );

            return response()->json([
                'codigo' => 0,
                'msg' => 'No se pudo Registrar el Candidato intentalo de nuevo mas tarde ',
            ], 502);
        }

        $body = $response->json();

        if (
            ! $response->successful()
            || ! is_array($body)
            || ($body['success'] ?? false) !== true
        ) {
            Log::error(
                'RODI rechazó registro de candidato sin ESE',
                [
                    'status' => $response->status(),
                    'body' => $body,
                ]
            );

            return response()->json([
                'codigo' => 0,
                'msg' => 'No se pudo Registrar el Candidato intentalo de nuevo mas tarde ',
            ], 500);
        }

        return response()->json([
            'codigo' => 1,
            'msg' => 'Datos guardados correctamente',
        ], 201);
    }
}
