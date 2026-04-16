<?php

namespace App\Models\Comunicacion360;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tareas extends Model
{
    use SoftDeletes;

    protected $table = 'comunicacion360_tareas_catalogo';
    protected $connection = 'portal_main';

    protected $fillable = [
        'id_portal',
        'clave',
        'nombre',
        'descripcion',
        'requiere_evidencia',
        'permite_comentarios',
        'tiempo_estimado_min',
        'activa',
    ];

    protected $casts = [
        'id_portal' => 'integer',
        'requiere_evidencia' => 'boolean',
        'permite_comentarios' => 'boolean',
        'tiempo_estimado_min' => 'integer',
        'activa' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
}