<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ApiGetMedicoDetalles extends Controller
{
    public function getDatosMedico($id_medico)
    {
        try {
            $baseUrl = rtrim(
                (string) config('services.rodi_integration.base_url'),
                '/'
            );

            $integrationKey = (string) config(
                'integrations.rodi.document_key'
            );

            if ($baseUrl === '' || $integrationKey === '') {
                return response()->json([
                    'message' => 'Integración con RODI no configurada',
                ], 500);
            }

            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' => $integrationKey,
                ])
                ->timeout(30)
                ->get(
                    $baseUrl .
                    '/medico/' .
                    (int) $id_medico .
                    '/datos'
                );

            if ($response->status() === 404) {
                return response()->json([
                    'message' => 'Medico no encontrado',
                ], 404);
            }

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Error al obtener datos médicos desde RODI',
                ], $response->status());
            }

            $data = $response->json();

            if (
                ! is_array($data)
                || ! ($data['success'] ?? false)
                || ! isset($data['data'])
                || ! is_array($data['data'])
            ) {
                return response()->json([
                    'message' => 'Respuesta inválida de RODI',
                ], 502);
            }

            $datosMedico = array_filter(
                $data['data'],
                function ($value) {
                    return ! is_null($value);
                }
            );

            return response()->json($datosMedico);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No fue posible conectar con RODI',
            ], 502);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener los datos médicos: ' .
                    $e->getMessage(),
            ], 500);
        }
    }
}
