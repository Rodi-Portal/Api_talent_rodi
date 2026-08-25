<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmpleadoMatrizRequisito extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'empleado_matriz_requisitos';

    public $timestamps = false;

    protected $fillable = [
        'id_portal',
        'id_usuario',
        'nombre',
        'descripcion',
        'tipo_destino',
        'requisitos',
        'activo',
        'eliminado',
    ];

    protected $casts = [
        'id_portal'  => 'integer',
        'id_usuario' => 'integer',
        'requisitos' => 'array',
        'activo'     => 'boolean',
        'eliminado'  => 'boolean',
        'creacion'   => 'datetime',
        'edicion'    => 'datetime',
    ];
}