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

class CrolGrupoNominaUpdateController extends Controller
{
    public function update(
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
            'cliente_id' => ['required', 'integer', 'min:1'],

            'nombre' => ['sometimes', 'string', 'max:255'],

            'periodicidad_pago_id' => [
                'sometimes', 'integer', 'min:1',
            ],
            'tipo_nomina_id' => [
                'sometimes', 'integer', 'min:1',
            ],
            'tipo_regimen_id' => [
                'sometimes', 'integer', 'min:1',
            ],
            'centro_costo_id' => [
                'sometimes', 'integer', 'min:0',
            ],
            'departamento_id' => [
                'sometimes', 'integer', 'min:0',
            ],
            'proyecto_id' => [
                'sometimes', 'integer', 'min:0',
            ],

            'activo' => ['sometimes', 'boolean'],
            'ajuste_por_redondeo' => ['sometimes', 'boolean'],
            'contrato_modalidad' => ['sometimes', 'boolean'],
            'isr_mensualizado' => ['sometimes', 'boolean'],
            'septimo_dia' => ['sometimes', 'boolean'],

            'etiqueta_color' => [
                'sometimes', 'string', 'max:20',
            ],
        ]);

        $payload = collect($validated)
            ->except(['cliente_id'])
            ->all();

        if ($payload === []) {
            return response()->json([
                'status' => false,
                'message' => 'Debes indicar al menos un campo para actualizar.',
            ], 422);
        }

        try {
            /*
             * Resolver cliente y sucursal desde TalentSafe,
             * nunca desde valores enviados por el navegador.
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
                    'La sucursal TalentSafe no existe o no está habilitada.'
                );
            }

            $sucursalNominaId =
                (int) ($cliente->crol_sucursal_nomina_id ?? 0);

            if ($sucursalNominaId <= 0) {
                throw new RuntimeException(
                    'La sucursal TalentSafe no tiene una sucursal de Nómina CROL vinculada.'
                );
            }

            $response = $integrationsApi->crolActualizarGrupoNomina(
                portalId: $portalId,
                clientId: (int) $cliente->id,
                grupoNominaId: $id,
                sucursalNominaId: $sucursalNominaId,
                payload: $payload,
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
                        : 'No fue posible confirmar la actualización del grupo.'
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
