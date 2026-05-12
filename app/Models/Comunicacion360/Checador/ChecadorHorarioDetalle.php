<?php

namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;
use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;

class ChecadorHorarioDetalle extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_horario_detalles';

    protected $fillable = [
        'id_plantilla',
        'dia_semana',
        'labora',
        'hora_entrada',
        'hora_salida',
        'descanso_inicio',
        'descanso_fin',
        'orden',
    ];

    protected $casts = [
        'id_plantilla' => 'integer',
        'dia_semana'   => 'integer',
        'labora'       => 'boolean',
        'orden'        => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    public function plantilla()
    {
        return $this->belongsTo(
            ChecadorHorarioPlantilla::class,
            'id_plantilla'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function getNombreDiaAttribute()
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
        ];

        return $dias[$this->dia_semana] ?? 'N/D';
    }
}