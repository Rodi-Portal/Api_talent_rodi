<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\ClienteInformacionInterna;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ClienteInformacionInternaController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'id_cliente' => ['required', 'integer', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $info = ClienteInformacionInterna::query()
            ->where('id_portal', (int) $administrator->id_portal)
            ->whereIn('id_cliente', $clientIds)
            ->where('eliminado', 0)
            ->with([
                'documentos.asignacionesEmpleados.empleado' => function ($query) {
                    $query->select([
                        'id',
                        'id_portal',
                        'id_cliente',
                        'nombre',
                        'paterno',
                        'materno',
                        'status',
                        'eliminado',
                    ]);
                },
            ])
            ->orderBy('nombre')
            ->get();

        return response()->json($info);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_cliente'  => ['required', 'integer', 'min:1'],
            'nombre'      => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $now = Carbon::now('America/Mexico_City');

        $info = ClienteInformacionInterna::create([
            'id_portal'   => (int) $administrator->id_portal,
            'id_cliente'  => $clientIds[0],
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'creacion'    => $now,
            'edicion'     => $now,
            'eliminado'   => 0,
        ]);

        return response()->json($info, 201);
    }

    public function update(
        Request $request,
        ClienteInformacionInterna $informacion
    ) {
        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $administrator = $this->administrator($request);

        $this->authorizeInformation($administrator, $informacion);

        $informacion->update([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'edicion'     => Carbon::now('America/Mexico_City'),
        ]);

        return response()->json($informacion->fresh());
    }

    public function destroy(
        Request $request,
        ClienteInformacionInterna $informacion
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeInformation($administrator, $informacion);

        $informacion->delete();

        return response()->json(['ok' => true]);
    }

    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }

    private function authorizeInformation(
        AdministradorAuth $administrator,
        ClienteInformacionInterna $informacion
    ): void {
        if (
            (int) $informacion->id_portal !==
            (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'La información interna no pertenece al portal autenticado.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $informacion->id_cliente]
        );
    }
}