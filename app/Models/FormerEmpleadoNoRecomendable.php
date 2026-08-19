<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormerEmpleadoNoRecomendable extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'former_empleado_no_recomendable';

    public $timestamps = false;

    protected $fillable = [
        'id_empleado',
        'activo',
        'id_usuario',
        'creacion',
        'edicion',
    ];

    protected $casts = [
        'id_empleado' => 'integer',
        'activo' => 'boolean',
        'id_usuario' => 'integer',
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];
}