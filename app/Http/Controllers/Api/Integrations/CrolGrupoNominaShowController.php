<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\PortalCrolContextService;
use App\Services\Integrations\Crol\TalentSafeIntegrationsApiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class CrolGrupoNominaShowController extends Controller
{
    public function show(
        Request $request,
        int $id,
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

        $portalContext->obtener($portalId);

        $validated = $request->validate([
            'cliente_id' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        try {
            /*
             * Resolver el cliente y la sucursal Nómina CROL
             * desde la base de datos del portal autenticado.
             */
            $cliente = DB::connection('portal_main')
                ->table('cliente')
                ->where('id', (int) $validated['cliente_id'])
                ->where('id_portal', $portalId)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->first([
                    'id',
                    'crol_sucursal_nomina_id',
                ]);

            if (! $cliente) {
                throw new RuntimeException(
                    'El cliente TalentSafe no existe o no está habilitado.'
                );
            }

            $sucursalNominaId =
                (int) ($cliente->crol_sucursal_nomina_id ?? 0);

            if ($sucursalNominaId <= 0) {
                throw new RuntimeException(
                    'El cliente no tiene una sucursal de Nómina CROL vinculada.'
                );
            }

            $response = $integrationsApi->crolObtenerGrupoNomina(
                portalId: $portalId,
                clientId: (int) $cliente->id,
                grupoNominaId: $id,
                sucursalNominaId: $sucursalNominaId,
            );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {
                throw new RuntimeException(
                    is_array($json) &&
                    is_string($json['message'] ?? null)
                        ? $json['message']
                        : 'No fue posible consultar el grupo de nómina.'
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
                'message' => $e->getMessage(),
            ], 502);
        }
    }
}
