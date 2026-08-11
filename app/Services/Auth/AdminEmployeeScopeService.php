<?php

namespace App\Services\Auth;

use App\Models\Auth\AdministradorAuth;
use App\Models\Empleado;
use Illuminate\Auth\Access\AuthorizationException;

class AdminEmployeeScopeService
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    /**
     * Valida que el empleado pertenezca al portal del administrador
     * autenticado y a una sucursal permitida para ese usuario.
     *
     * La identificación se realiza exclusivamente mediante empleados.id.
     *
     * @throws AuthorizationException
     */
    public function authorizeEmployee(
        AdministradorAuth $administrator,
        int $employeeId
    ): Empleado {
        if ($employeeId <= 0) {
            throw new AuthorizationException(
                'El empleado solicitado no es válido.'
            );
        }

        $employee = Empleado::query()
            ->whereKey($employeeId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $employee) {
            throw new AuthorizationException(
                'El empleado no pertenece al portal autorizado.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        return $employee;
    }
}
