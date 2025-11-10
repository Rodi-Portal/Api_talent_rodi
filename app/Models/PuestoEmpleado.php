<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PuestoEmpleado extends Model
{
    protected $table = 'puestos_empleado';
      protected $connection = 'portal_main';
    public $timestamps = false;

    // Si tu app usa una conexión NO default, descomenta:
    // protected $connection = 'portal_main';

    protected $fillable = [
        'id_portal', 'id_cliente', 'nombre', 'status', 'creacion', 'edicion',
    ];
}
