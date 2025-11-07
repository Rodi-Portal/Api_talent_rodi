<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Departamento extends Model
{
    protected $table = 'departamentos';
    protected $connection = 'portal_main';

    public $timestamps = true;
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';

    protected $fillable = ['id_portal','id_cliente','nombre_departamento'];

    // ✅ (opcional útil)
    protected $casts = [
        'id_portal' => 'integer',
        'id_cliente'=> 'integer',
    ];

    // ✅ (opcional) relación inversa, si luego te sirve listar empleados por dep:
    public function empleados()
    {
        return $this->hasMany(\App\Models\Empleado::class, 'id_departamento');
    }
}