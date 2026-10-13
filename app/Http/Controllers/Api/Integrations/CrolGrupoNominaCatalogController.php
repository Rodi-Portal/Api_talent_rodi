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

class CrolGrupoNominaCatalogController extends Controller
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
         * Valida que el portal:
         *
         * - exista
         * - esté activo
         * - no esté bloqueado
         * - tenga CROL habilitado
         */
        $portalContext->obtener($portalId);

        try {
            $response =
                $integrationsApi
                    ->crolGrupoNominaCatalogos(
                        $portalId
                    );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {
                $message = is_array($json)
                    ? ($json['message'] ?? null)
                    : null;

                throw new RuntimeException(
                    is_string($message) &&
                    trim($message) !== ''
                        ? trim($message)
                        : 'No fue posible obtener los catálogos de grupos de nómina.'
                );
            }

            return response()->json([
                'status' => true,
                'data' => $json['data'] ?? [],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' =>
                    'No fue posible obtener los catálogos de grupos de nómina.',
            ], 502);
        }
    }
}
