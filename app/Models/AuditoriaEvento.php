<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditoriaEvento extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'auditoria_eventos';

    public $timestamps = false;

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'actor_tipo',
        'actor_id',
        'actor_nombre',
        'modulo',
        'entidad_tipo',
        'entidad_id',
        'accion',
        'resultado',
        'descripcion',
        'datos_anteriores',
        'datos_nuevos',
        'metadatos',
        'request_id',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'id_portal'         => 'integer',
        'id_cliente'        => 'integer',
        'actor_id'          => 'integer',
        'entidad_id'        => 'integer',
        'datos_anteriores'  => 'array',
        'datos_nuevos'      => 'array',
        'metadatos'         => 'array',
        'created_at'        => 'datetime',
    ];
}