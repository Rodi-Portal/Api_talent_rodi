<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Checador\ChecadorMetodo;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class ChecadorMetodoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'min:1',
            ],
            'solo_activos' => [
                'sometimes',
                'boolean',
            ],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $validated['id_cliente']]
            );

        $idPortal = (int) $administrator->id_portal;
        $idCliente = (int) $clientIds[0];
        $soloActivos = $request->boolean(
            'solo_activos',
            true
        );

        $query = ChecadorMetodo::query()
            ->where(function ($query) use (
                $idPortal,
                $idCliente
            ) {
                $query
                    ->where(function ($scope) {
                        $scope
                            ->whereNull('id_portal')
                            ->whereNull('id_cliente');
                    })
                    ->orWhere(function ($scope) use (
                        $idPortal,
                        $idCliente
                    ) {
                        $scope
                            ->where('id_portal', $idPortal)
                            ->where(function ($clientScope) use (
                                $idCliente
                            ) {
                                $clientScope
                                    ->whereNull('id_cliente')
                                    ->orWhere(
                                        'id_cliente',
                                        $idCliente
                                    );
                            });
                    });
            });

        if ($soloActivos) {
            $query->where('activo', 1);
        }

        $metodos = $query
            ->orderBy('id')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $metodos,
        ]);
    }

    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}