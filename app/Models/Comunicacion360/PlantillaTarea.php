<?php

namespace App\Models\Comunicacion360;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlantillaTarea extends Model
{
    use SoftDeletes;

    protected $table = 'comunicacion360_plantilla_tareas';
    protected $connection = 'portal_main';

    protected $fillable = [
        'id_portal',
        'plantilla_id',
        'tarea_catalogo_id',
        'orden',
        'clave_snapshot',
        'nombre_snapshot',
        'descripcion_snapshot',
        'requiere_evidencia',
        'permite_comentarios',
        'tiempo_estimado_min',
        'activa',
    ];

    protected $casts = [
        'id_portal'            => 'integer',
        'plantilla_id'         => 'integer',
        'tarea_catalogo_id'    => 'integer',
        'orden'                => 'integer',
        'requiere_evidencia'   => 'boolean',
        'permite_comentarios'  => 'boolean',
        'tiempo_estimado_min'  => 'integer',
        'activa'               => 'boolean',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
        'deleted_at'           => 'datetime',
    ];

    public function plantilla()
    {
        return $this->belongsTo(Plantilla::class, 'plantilla_id', 'id');
    }

    public function tareaCatalogo()
    {
        return $this->belongsTo(Tareas::class, 'tarea_catalogo_id', 'id');
    }
}