<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\PortalCrolContextService;
use App\Services\Integrations\Crol\TalentSafeIntegrationsApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CrolRegistrationCatalogController extends Controller
{
    public function index(
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
         * Además de obtener el portal, esto valida:
         *
         * - portal existente
         * - status = 1
         * - bloqueado = 0
         * - crol = 1
         */
        $portalContext->obtener($portalId);

        try {
            $response =
                $integrationsApi->crolRegistrationCatalogs();

            if (! $response->successful()) {
                report(new \RuntimeException(
                    'La API de integraciones respondió HTTP ' .
                    $response->status()
                ));

                return response()->json([
                    'status' => false,
                    'message' =>
                        'No fue posible obtener los catálogos de CROL.',
                ], 502);
            }

            $json = $response->json();

            if (
                ! is_array($json) ||
                ($json['status'] ?? false) !== true ||
                ! isset($json['data'])
            ) {
                return response()->json([
                    'status' => false,
                    'message' =>
                        'La API de integraciones devolvió una respuesta inválida.',
                ], 502);
            }

            return response()->json([
                'status' => true,
                'data' => $json['data'],
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