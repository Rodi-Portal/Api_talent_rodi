<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ApiGetDopingDetalles extends Controller
{
    public function getDatosDoping($id_doping)
    {
        try {
            $response = $this->consultarRodi(
                $id_doping,
                'datos'
            );

            if ($response instanceof \Illuminate\Http\JsonResponse) {
                return $response;
            }

            $data = $response->json();

            if (
                ! is_array($data)
                || ! array_key_exists('data', $data)
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
                'error' => 'Error al obtener los datos de doping: ' .
                    $e->getMessage(),
            ], 500);
        }
    }

    public function getDopingDetalles($id_doping)
    {
        try {
            $response = $this->consultarRodi(
                $id_doping,
                'detalles'
            );

            if ($response instanceof \Illuminate\Http\JsonResponse) {
                if ($response->getStatusCode() === 404) {
                    return response()->json([
                        'error' => 'No se encontraron detalles de doping para el ID especificado',
                    ], 404);
                }

                return $response;
            }

            $data = $response->json();

            if (
                ! is_array($data)
                || ! isset($data['data'])
                || ! is_array($data['data'])
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
                'error' => 'Error al obtener los detalles de doping: ' .
                    $e->getMessage(),
            ], 500);
        }
    }

    private function consultarRodi($idDoping, $tipo)
    {
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
                $baseUrl .
                '/doping/' .
                (int) $idDoping .
                '/' .
                $tipo
            );

        if (! $response->successful()) {
            return response()->json([
                'error' => 'Error al consultar doping en RODI',
            ], $response->status());
        }

        return $response;
    }
}
