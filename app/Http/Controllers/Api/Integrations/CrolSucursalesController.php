<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\CrolSucursalesContextService;
use App\Services\Integrations\Crol\PortalCrolContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CrolSucursalesController extends Controller
{
    public function index(
        Request $request,
        PortalCrolContextService $portalContext,
        CrolSucursalesContextService $service,
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
         * Valida:
         * - portal existente
         * - status = 1
         * - bloqueado = 0
         * - crol = 1
         */
        $portalContext->obtener($portalId);

        try {
            $result = $service->obtener(
                $portalId
            );

            return response()->json([
                'status' => true,
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status' => false,
                'message' =>
                    $e->getMessage() !== ''
                        ? $e->getMessage()
                        : 'No fue posible consultar las sucursales.',
            ], 502);
        }
    }
}
