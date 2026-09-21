<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ApiGetArea extends Controller
{
    public function getArea($param)
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
                    'error' => 'Integración con RODI no configurada',
                ], 500);
            }

            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' => $integrationKey,
                ])
                ->timeout(30)
                ->get(
                    $baseUrl . '/areas/consultar',
                    [
                        'param' => $param,
                    ]
                );

            if ($response->status() === 404) {
                return response()->json([
                    'error' => 'No se encontró el área ' . $param,
                ], 404);
            }

            if (! $response->successful()) {
                return response()->json([
                    'error' => 'Error al obtener el área desde RODI',
                ], $response->status());
            }

            $data = $response->json();

            if (
                ! is_array($data)
                || ! ($data['success'] ?? false)
                || ! isset($data['data'])
            ) {
                return response()->json([
                    'error' => 'Respuesta inválida de RODI',
                ], 502);
            }

            return response()->json($data['data']);
        } catch (ConnectionException $e) {
            return response()->json([
                'error' => 'No fue posible conectar con RODI',
            ], 502);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al obtener el área: ' . $e->getMessage(),
            ], 500);
        }
    }
}
