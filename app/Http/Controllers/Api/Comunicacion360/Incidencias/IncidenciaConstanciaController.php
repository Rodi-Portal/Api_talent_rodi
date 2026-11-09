<?php

namespace App\Http\Controllers\Api\Comunicacion360\Incidencias;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Incidencias\IncidenciaConstanciaPreviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IncidenciaConstanciaController extends Controller
{
    public function vistaPrevia(
        Request $request,
        int $id,
        IncidenciaConstanciaPreviewService $service
    ): JsonResponse {
        $admin = $this->admin($request);
        abort_if($id <= 0, 404);

        return response()->json(['ok' => true, 'data' => $service->obtener($id, $admin)]);
    }

    private function admin(Request $request): AdministradorAuth
    {
        $admin = $request->user();
        abort_unless($admin instanceof AdministradorAuth, 403);
        return $admin;
    }
}
