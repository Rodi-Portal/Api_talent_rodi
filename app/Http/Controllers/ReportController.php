<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportController extends Controller
{
    public function getReport($id_candidato)
    {
        try {
            $idCandidato = (int) $id_candidato;

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
                ->timeout(60)
                ->get(
                    $baseUrl .
                    '/candidatos/' .
                    $idCandidato .
                    '/reporte'
                );

            if (! $response->successful()) {
                Log::error(
                    'ReportController · RODI reporte ERROR',
                    [
                        'id_candidato' => $idCandidato,
                        'status' => $response->status(),
                    ]
                );

                throw new \RuntimeException(
                    'No fue posible consultar reporte RODI'
                );
            }

            $data = $response->json('data');

            if (! is_array($data)) {
                throw new \RuntimeException(
                    'Respuesta inválida de integración RODI'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Compatibilidad con contrato histórico Laravel
            |--------------------------------------------------------------------------
            */

            unset($data['info']['subcliente']);

            if (
                isset($data['sociales']) &&
                is_array($data['sociales']) &&
                ! array_is_list($data['sociales'])
            ) {
                $data['sociales'] = [$data['sociales']];
            }

            if (
                isset($data['nom']) &&
                is_array($data['nom']) &&
                ! array_is_list($data['nom'])
            ) {
                $data['nom'] = [$data['nom']];
            }

            if (
                isset($data['familia']) &&
                is_array($data['familia'])
            ) {
                foreach ($data['familia'] as &$familiar) {
                    if (is_array($familiar)) {
                        unset(
                            $familiar['nombre2'],
                            $familiar['escolaridad2']
                        );
                    }
                }

                unset($familiar);
            }

            $toLaravelDate = static function ($value) {
                if ($value === null || $value === '') {
                    return $value;
                }

                return Carbon::createFromFormat(
                    'Y-m-d H:i:s',
                    $value,
                    'America/Mexico_City'
                )
                    ->utc()
                    ->format('Y-m-d\TH:i:s.000000\Z');
            };

            if (
                isset($data['refPersonal']) &&
                is_array($data['refPersonal'])
            ) {
                foreach ($data['refPersonal'] as &$referencia) {
                    foreach (['creacion', 'edicion'] as $campo) {
                        if (! empty($referencia[$campo])) {
                            $referencia[$campo] =
                                $toLaravelDate(
                                    $referencia[$campo]
                                );
                        }
                    }
                }

                unset($referencia);
            }

            if (! empty($data['finalizado']['creacion'])) {
                $data['finalizado']['creacion'] =
                    $toLaravelDate(
                        $data['finalizado']['creacion']
                    );
            }

            foreach (['vivienda', 'legal'] as $bloque) {
                foreach (['creacion', 'edicion'] as $campo) {
                    if (! empty($data[$bloque][$campo])) {
                        $data[$bloque][$campo] =
                            $toLaravelDate(
                                $data[$bloque][$campo]
                            );
                    }
                }
            }

            return response()->json($data);

        } catch (\Throwable $e) {
            Log::error(
                'Error al obtener los datos',
                [
                    'id_candidato' => (int) $id_candidato,
                    'error' => $e->getMessage(),
                ]
            );

            return response()->json(
                ['error' => 'Error al obtener los datos'],
                500
            );
        }
    }
}
