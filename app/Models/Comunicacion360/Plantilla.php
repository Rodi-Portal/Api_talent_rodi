<?php

namespace App\Models\Comunicacion360;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plantilla extends Model
{
    use SoftDeletes;

    protected $table = 'comunicacion360_plantillas';
    protected $connection = 'portal_main';

    protected $fillable = [
        'id_portal',
        'clave',
        'nombre',
        'descripcion',
        'vigencia_inicio',
        'vigencia_fin',
        'prioridad',
        'modo_superposicion',
        'activa',
        'vigencia_dias',
    ];

    protected $casts = [
        'id_portal'          => 'integer',
        'vigencia_inicio'    => 'date',
        'vigencia_fin'       => 'date',
        'prioridad'          => 'integer',
        'activa'             => 'boolean',
        'vigencia_dias'      => 'integer',
        'created_at'         => 'datetime',
        'updated_at'         => 'datetime',
        'deleted_at'         => 'datetime',
    ];

    public function tareas()
    {
        return $this->hasMany(PlantillaTarea::class, 'plantilla_id', 'id')
            ->whereNull('deleted_at')
            ->orderBy('orden');
    }
}