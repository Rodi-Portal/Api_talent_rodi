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

class CrolCertificateController extends Controller
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
         * Valida portal existente, activo,
         * no bloqueado y con CROL habilitado.
         */
        $portalContext->obtener($portalId);

        $validated = $request->validate([
            'cer' => [
                'required',
                'file',
                'extensions:cer',
                'max:2048',
            ],

            'key' => [
                'required',
                'file',
                'extensions:key',
                'max:2048',
            ],

            'key_password' => [
                'required',
                'string',
                'max:255',
            ],

            'active' => [
                'sometimes',
                'boolean',
            ],
        ]);

        try {
            $cerFile = $request->file('cer');
            $keyFile = $request->file('key');

            $cerContents = file_get_contents(
                $cerFile->getRealPath()
            );

            $keyContents = file_get_contents(
                $keyFile->getRealPath()
            );

            if (
                $cerContents === false ||
                $keyContents === false
            ) {
                throw new RuntimeException(
                    'No fue posible leer los archivos del certificado.'
                );
            }

            /*
             * La conversión a Base64 ocurre aquí.
             *
             * Vue solamente envía archivos.
             */
            $cerBase64 = base64_encode(
                $cerContents
            );

            $keyBase64 = base64_encode(
                $keyContents
            );

            $response =
                $integrationsApi->crolCertificate(
                    portalId: $portalId,
                    cerBase64: $cerBase64,
                    keyBase64: $keyBase64,
                    keyPassword:
                        $validated['key_password'],
                    active:
                        (bool) (
                            $validated['active']
                            ?? true
                        ),
                );

            $json = $response->json();

            if (
                ! $response->successful() ||
                ! is_array($json) ||
                ($json['status'] ?? false) !== true
            ) {

                            /*
                 * Los errores 422 controlados por la API de
                 * integraciones corresponden a validaciones
                 * del CER/KEY y deben conservarse como 422
                 * hasta el frontend.
                 */
                if (
                    $response->status() === 422 &&
                    is_array($json) &&
                    ($json['status'] ?? null) === false
                ) {
                    return response()->json([
                        'status' => false,
                        'message' =>
                            is_string(
                                $json['message'] ?? null
                            )
                                ? $json['message']
                                : 'Los archivos del certificado no son válidos.',
                    ], 422);
                }
                report(new RuntimeException(
                    'La API de integraciones rechazó el certificado CROL. HTTP ' .
                    $response->status()
                ));

                return response()->json([
                    'status' => false,
                    'message' =>
                        is_array($json) &&
                        is_string(
                            $json['message'] ?? null
                        )
                            ? $json['message']
                            : 'No fue posible guardar el certificado en CROL.',
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