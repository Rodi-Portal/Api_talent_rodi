<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\PortalCrolContextService;
use App\Services\Integrations\Crol\TalentSafeIntegrationsApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class CrolIntegrationStateController extends Controller
{
    public function show(
        Request $request,
        PortalCrolContextService $portalContext,
        TalentSafeIntegrationsApiClient $integrationsApi,
    ): JsonResponse {
        $administrador = $request->user();

        abort_unless(
            $administrador instanceof AdministradorAuth,
            403,
            'Se requiere una sesión administrativa.'
        );

        abort_if(
            (int) $administrador->status !== 1 ||
            (int) $administrador->eliminado !== 0,
            403,
            'El administrador no está habilitado.'
        );

        $portalId = (int) $administrador->id_portal;

        abort_if(
            $portalId <= 0,
            403,
            'El administrador no tiene portal.'
        );

        /*
         * También valida:
         * - portal existente
         * - status = 1
         * - bloqueado = 0
         * - crol = 1
         */
        $portalContext->obtener($portalId);

        try {
            $response =
                $integrationsApi->crolState(
                    $portalId
                );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {
                report(new RuntimeException(
                    'La API de integraciones rechazó la consulta de estado CROL. HTTP ' .
                    $response->status()
                ));

                return response()->json([
                    'status' => false,
                    'message' =>
                        is_array($json) &&
                        is_string($json['message'] ?? null)
                            ? $json['message']
                            : 'No fue posible consultar el estado de CROL.',
                ], 502);
            }

            return response()->json([
                'status' => true,
                'data' => $json['data'] ?? null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' =>
                    'No fue posible comunicarse con la API de integraciones.',
            ], 502);
        }
    }
}