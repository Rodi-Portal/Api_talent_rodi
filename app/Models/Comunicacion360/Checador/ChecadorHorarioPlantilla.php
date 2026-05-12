<?php
namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;
use App\Models\Comunicacion360\Checador\ChecadorHorarioDetalle;
class ChecadorHorarioPlantilla extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_horario_plantillas';

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'nombre',
        'descripcion',
        'timezone',
        'tolerancia_entrada_min',
        'tolerancia_salida_min',
        'permite_descanso',
        'activo',
    ];

    protected $casts = [
        'permite_descanso'       => 'boolean',
        'activo'                 => 'boolean',
        'tolerancia_entrada_min' => 'integer',
        'tolerancia_salida_min'  => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relaciones
    |--------------------------------------------------------------------------
    */

    public function detalles()
    {
        return $this->hasMany(
            ChecadorHorarioDetalle::class,
            'id_plantilla'
        )
            ->orderBy('dia_semana')
            ->orderBy('orden');
    }

    public function plantillasChecada()
    {
        return $this->belongsToMany(
            ChecadorChecadaPlantilla::class,
            'checador_checada_plantilla_horarios',
            'id_horario_plantilla',
            'id_plantilla'
        )
            ->withPivot([
                'obligatorio',
                'activo',
            ])
            ->wherePivot('activo', 1)
            ->withTimestamps();
    }
}
