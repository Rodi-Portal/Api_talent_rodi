<?php

namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;

class ChecadorAsignacion extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_asignaciones';

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'id_empleado',
        'id_plantilla_horario',
        'id_plantilla_checada',
        'fecha_inicio',
        'fecha_fin',
        'prioridad',
        'activa',
    ];

    protected $casts = [
        'id_portal'             => 'integer',
        'id_cliente'            => 'integer',
        'id_empleado'           => 'integer',
        'id_plantilla_horario'  => 'integer',
        'id_plantilla_checada'  => 'integer',
        'fecha_inicio'          => 'date',
        'fecha_fin'             => 'date',
        'prioridad'             => 'integer',
        'activa'                => 'boolean',
    ];

    public function horarioPlantilla()
    {
        return $this->belongsTo(
            ChecadorHorarioPlantilla::class,
            'id_plantilla_horario'
        );
    }

    public function plantillaChecada()
    {
        return $this->belongsTo(
            ChecadorChecadaPlantilla::class,
            'id_plantilla_checada'
        );
    }
}