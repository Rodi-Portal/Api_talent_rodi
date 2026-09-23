<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiCandidatoConProyectoPrevioController extends Controller
{
    public function store(Request $request)
    {
        $date = Carbon::now()
            ->setTimezone('America/Mexico_City')
            ->format('Y-m-d H:i:s');

        $secciones = $request->input('secciones', []);
        $documentos = $request->input('documentos', []);

        $tipoAntidoping = (int) ($request->tipo_antidoping ?? 0);

        $antidoping = null;
        if (
            $request->filled('antidoping')
            && (int) $request->antidoping > 0
        ) {
            $antidoping = (int) $request->antidoping;
        }

        $tipoPsicometrico = null;
        if ($request->filled('tipo_psicometrico')) {
            $tipoPsicometrico = (int) $request->tipo_psicometrico;
        }

        $psicometrico = 0;
        if (
            $request->filled('psicometrico')
            && (int) $request->psicometrico > 0
        ) {
            $psicometrico = (int) $request->psicometrico;
        }

        $payload = [
            'candidato' => [
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
                'subproyecto'     => $request->subproyecto ?? null,
                'pais'            => $request->pais ?? null,
                'privacidad'      => $request->privacidad ?? 0,
            ],

            'sync' => [
                'id_cliente_talent' =>
                    $request->id_cliente_talent ?? null,

                'id_aspirante_talent' =>
                    $request->id_aspirante_talent ?? 0,

                'id_usuario_talent' =>
                    $request->id_usuario ?? null,

                'nombre_cliente_talent' =>
                    $request->nombre_cliente_talent ?? null,

                'id_portal' =>
                    $request->id_portal ?? null,

                'id_puesto_talent' =>
                    $request->id_puesto_talent ?? null,

                'creacion' => $date,
                'edicion'  => $date,
            ],

            'pruebas' => [
                'creacion'          => $date,
                'edicion'           => $date,
                'tipo_antidoping'   => $tipoAntidoping,
                'antidoping'        => $antidoping,
                'medico'            => (int) ($request->medico ?? 0),
                'tipo_psicometrico' => $tipoPsicometrico,
                'psicometrico'      => $psicometrico,
                'id_usuario'        => 1,
                'id_cliente'        => 273,
                'socioeconomico'    => 1,
            ],

            'proyecto' => trim(
                (string) ($secciones['proyecto'] ?? '')
            ),

            'documentos' => is_array($documentos)
                ? $documentos
                : [],
        ];

        try {
            $baseUrl = rtrim(
                (string) config('services.rodi_integration.base_url'),
                '/'
            );

            $integrationKey = trim(
                (string) config('integrations.rodi.document_key')
            );

            if ($baseUrl === '' || $integrationKey === '') {
                throw new \RuntimeException(
                    'Configuración de integración RODI incompleta'
                );
            }

            $response = Http::withHeaders([
                'X-RODI-Integration-Key' => $integrationKey,
                'Accept' => 'application/json',
            ])
                ->timeout(30)
                ->post(
                    $baseUrl .
                    '/candidatos/con-proyecto-previo',
                    $payload
                );

            if (! $response->successful()) {
                Log::error(
                    'API candidatoconprevio · RODI ERROR',
                    [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]
                );

                return response()->json([
                    'codigo' => 0,
                    'msg' =>
                        'No fue posible registrar el candidato',
                ], 500);
            }

            Log::info(
                'API candidatoconprevio · RODI OK',
                [
                    'id_candidato' =>
                        $response->json('data.id_candidato'),
                    'docs_insertados' =>
                        is_array($documentos)
                            ? count($documentos)
                            : 0,
                ]
            );

            return response()->json([
                'codigo' => 1,
                'msg' =>
                    'El candidato se registró correctamente',
            ], 201);
        } catch (\Throwable $e) {
            Log::error(
                'API candidatoconprevio · ERROR',
                [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]
            );

            return response()->json([
                'codigo' => 0,
                'msg' =>
                    'No fue posible registrar el candidato',
            ], 500);
        }
    }
}