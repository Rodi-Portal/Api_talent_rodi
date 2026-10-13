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

class CrolOrganizationController extends Controller
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
         * Valida que el portal:
         * - exista
         * - esté activo
         * - no esté bloqueado
         * - tenga CROL habilitado
         */
        $portalContext->obtener($portalId);

        $payload = $request->validate([
            'organizacion.nombre' =>
                ['required', 'string', 'max:150'],

            'facturacion.razon_social' =>
                ['required', 'string', 'max:255'],

            'facturacion.rfc' => [
                'required',
                'string',
                'regex:/^[A-ZÑ&]{3,4}\d{6}[A-Z0-9]{3}$/i',
            ],

            'facturacion.regimen.id' =>
                ['required', 'integer', 'min:1'],

            'facturacion.regimen.tipo' =>
                ['nullable', 'integer'],

            'facturacion.regimen.nombre' =>
                ['required', 'string'],

            'facturacion.forma_pago.id' =>
                ['required', 'integer', 'min:1'],

            'facturacion.forma_pago.clave_sat' =>
                ['nullable', 'string'],

            'facturacion.forma_pago.nombre' =>
                ['required', 'string'],

            'facturacion.metodo_pago.id' =>
                ['required', 'integer', 'min:1'],

            'facturacion.metodo_pago.nombre' =>
                ['required', 'string'],

            'facturacion.uso_cfdi.clave' =>
                ['required', 'string'],

            'facturacion.uso_cfdi.descripcion' =>
                ['nullable', 'string'],

            'domicilio.pais' =>
                ['nullable', 'string'],

            'domicilio.estado' =>
                ['nullable', 'string'],

            'domicilio.ciudad' =>
                ['nullable', 'string'],

            'domicilio.colonia' =>
                ['required', 'string'],

            'domicilio.calle' =>
                ['required', 'string'],

            'domicilio.exterior' =>
                ['required', 'string'],

            'domicilio.interior' =>
                ['nullable', 'string'],

            'domicilio.cp' =>
                ['required', 'string'],

            'contacto.nombre' =>
                ['required', 'string'],

            'contacto.paterno' =>
                ['required', 'string'],

            'contacto.materno' =>
                ['nullable', 'string'],

            'contacto.email' =>
                ['required', 'email'],

            'contacto.telefono' =>
                ['required', 'string'],
        ]);

        try {
            $response = $integrationsApi->crolOrganization(
                portalId: $portalId,
                payload: $payload,
            );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {
                report(new RuntimeException(
                    'La API de integraciones rechazó la operación CROL. HTTP ' .
                    $response->status()
                ));

                return response()->json([
                    'status' => false,
                    'message' =>
                        is_array($json) &&
                        is_string($json['message'] ?? null)
                            ? $json['message']
                            : 'No fue posible guardar la organización en CROL.',
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
