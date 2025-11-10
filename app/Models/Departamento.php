<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    protected $table = 'departamentos';
    protected $connection = 'portal_main';

     public $timestamps = false; // usamos columnas creacion/edicion manuales

    // Si tu app usa una conexión NO default, descomenta la siguiente línea:
    // protected $connection = 'portal_main';

    protected $fillable = [
        'id_portal', 'id_cliente', 'nombre', 'status', 'creacion', 'edicion',
    ];


    // ✅ (opcional) relación inversa, si luego te sirve listar empleados por dep:
    public function empleados()
    {
        return $this->hasMany(\App\Models\Empleado::class, 'id_departamento');
    }
}