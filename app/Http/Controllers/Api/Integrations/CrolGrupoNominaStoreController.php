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

class CrolGrupoNominaStoreController extends Controller
{
    public function store(
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
         * Valida:
         * - portal existente
         * - status = 1
         * - bloqueado = 0
         * - crol = 1
         */
        $portalContext->obtener($portalId);

        $validated = $request->validate([
            'cliente_id'           => [
                'required',
                'integer',
                'min:1',
            ],

            'nombre'               => [
                'required',
                'string',
                'max:255',
            ],

            'periodicidad_pago_id' => [
                'required',
                'integer',
                'min:1',
            ],

            'tipo_nomina_id'       => [
                'required',
                'integer',
                'min:1',
            ],

            'tipo_regimen_id'      => [
                'required',
                'integer',
                'min:1',
            ],
            'centro_costo_id'      => [
                'nullable',
                'integer',
                'min:0',
            ],
        ]);

        try {
            /*
             * La sucursal CROL Nómina NO viene del navegador.
             *
             * La resolvemos desde la sucursal TalentSafe
             * perteneciente al portal autenticado.
             */
            $cliente = DB::connection('portal_main')
                ->table('cliente')
                ->where(
                    'id',
                    (int) $validated['cliente_id']
                )
                ->where('id_portal', $portalId)
                ->where('status', 1)
                ->where('eliminado', 0)
                ->first([
                    'id',
                    'nombre',
                    'crol_sucursal_nomina_id',
                ]);

            if ($cliente === null) {
                throw new RuntimeException(
                    'La sucursal TalentSafe no existe o no está habilitada.'
                );
            }

            $sucursalNominaId =
            $cliente->crol_sucursal_nomina_id !== null
                ? (int) $cliente->crol_sucursal_nomina_id
                : 0;

            if ($sucursalNominaId <= 0) {
                throw new RuntimeException(
                    'La sucursal TalentSafe no tiene una sucursal de Nómina CROL vinculada.'
                );
            }

            $response = $integrationsApi->crolCrearGrupoNomina(
                portalId: $portalId,
                clientId: (int) $cliente->id,
                payload: [
                    'nombre'               =>
                    trim((string) $validated['nombre']),

                    'sucursal_id'          =>
                    $sucursalNominaId,

                    'periodicidad_pago_id' =>
                    (int) $validated['periodicidad_pago_id'],

                    'tipo_nomina_id'       =>
                    (int) $validated['tipo_nomina_id'],

                    'tipo_regimen_id'      =>
                    (int) $validated['tipo_regimen_id'],

                    'centro_costo_id'      =>
                    (int) ($validated['centro_costo_id'] ?? 0),
                ],
            );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {
                $message =
                is_array($json)
                    ? ($json['message'] ?? null)
                    : null;

                throw new RuntimeException(
                    is_string($message) &&
                    trim($message) !== ''
                        ? trim($message)
                        : 'No fue posible registrar el grupo de nómina en CROL.'
                );
            }

            return response()->json([
                'status' => true,
                'data'   => $json['data'] ?? [],
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status'  => false,
                'message' =>
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'No fue posible registrar el grupo de nómina.',
            ], 502);
        }
    }
}
