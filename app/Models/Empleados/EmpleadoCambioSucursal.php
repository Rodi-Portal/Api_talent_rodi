<?php

namespace App\Models\Empleados;

use Illuminate\Database\Eloquent\Model;

class EmpleadoCambioSucursal extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'empleado_cambios_sucursal';

    public $timestamps = false;

    protected $fillable = [
        'id_portal',
        'id_empleado',
        'id_cliente_anterior',
        'id_cliente_nuevo',
        'id_usuario',
        'motivo',
        'ip',
        'user_agent',
        'created_at',
    ];
}