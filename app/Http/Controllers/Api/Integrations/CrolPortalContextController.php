<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\PortalCrolContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrolPortalContextController extends Controller
{
    public function show(
        Request $request,
        PortalCrolContextService $service
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

        abort_if($portalId <= 0, 403, 'El administrador no tiene portal.');

        return response()->json([
            'status' => true,
            'data' => $service->obtener($portalId),
        ]);
    }
}