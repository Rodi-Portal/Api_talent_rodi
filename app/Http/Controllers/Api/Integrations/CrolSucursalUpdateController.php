<?php
namespace App\Http\Controllers\Api\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Integrations\Crol\CrolSucursalLocalSaveService;
use App\Services\Integrations\Crol\CrolSucursalUpdateService;
use App\Services\Integrations\Crol\PortalCrolContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class CrolSucursalUpdateController extends Controller
{
    public function update(
        Request $request,
        CrolSucursalLocalSaveService $localService,
        CrolSucursalUpdateService $service,
        PortalCrolContextService $portalContext,
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
         * Valida también que el portal siga
         * habilitado para la integración CROL.
         */
        $portalContext->obtener($portalId);

        $validated = $request->validate([
            'cliente_id'         => [
                'required',
                'integer',
                'min:1',
            ],

            'nombre'             => [
                'required',
                'string',
                'max:50',
            ],

            'clave'              => [
                'required',
                'string',
                'max:16',
            ],

            'domicilio'          => [
                'required',
                'array',
            ],

            'domicilio.calle'    => [
                'nullable',
                'string',
                'max:255',
            ],

            'domicilio.exterior' => [
                'nullable',
                'string',
                'max:32',
            ],

            'domicilio.interior' => [
                'nullable',
                'string',
                'max:32',
            ],

            'domicilio.colonia'  => [
                'nullable',
                'string',
                'max:255',
            ],

            'domicilio.cp'       => [
                'nullable',
                'string',
                'max:16',
            ],

            'telefono'           => [
                'nullable',
                'string',
                'max:25',
            ],
        ]);

        try {
            $result = DB::connection('portal_main')
                ->transaction(function () use (
                    $portalId,
                    $validated,
                    $localService,
                    $service,
                ) {
                    /*
                     * 1. Guardamos primero los nuevos
                     * datos en TalentSafe.
                     */
                    $localService->guardar(
                        portalId: $portalId,
                        clienteId: (int) $validated['cliente_id'],
                        datos: $validated,
                    );

                    /*
                     * 2. Este servicio vuelve a leer
                     * los datos desde TalentSafe y
                     * los sincroniza con CROL.
                     *
                     * Como estamos dentro de la misma
                     * conexión/transacción, verá los
                     * valores recién modificados.
                     */
                    return $service->actualizar(
                        portalId: $portalId,
                        clienteId: (int) $validated['cliente_id'],
                    );
                });

            return response()->json([
                'status' => true,
                'data'   => $result,
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'status'  => false,
                'message' =>
                $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'No fue posible actualizar la sucursal en CROL.',
            ], 502);
        }
    }
}
