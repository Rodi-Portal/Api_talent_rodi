<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class ApiClientesController extends Controller
{
    public function VerificarCliente(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string',
            'clave' => 'required|string',
        ]);

        $baseUrl = rtrim(
            (string) config('services.rodi_integration.base_url'),
            '/'
        );

        $integrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if ($baseUrl === '' || $integrationKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Integración con RODI no configurada',
            ], 500);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' => $integrationKey,
                ])
                ->timeout(30)
                ->get(
                    $baseUrl . '/clientes/verificar',
                    [
                        'nombre' => $request->input('nombre'),
                        'clave' => $request->input('clave'),
                    ]
                );
        } catch (ConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible conectar con RODI',
            ], 502);
        }

        if (! $response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'No fue posible verificar el cliente en RODI',
            ], $response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            return response()->json([
                'success' => false,
                'message' => 'Respuesta inválida de RODI',
            ], 502);
        }

        if (! ($data['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $data['message'] ?? 'Cliente no encontrado',
            ]);
        }

        return response()->json([
            'success' => true,
            'client_id' => (int) $data['client_id'],
        ]);
    }
}
