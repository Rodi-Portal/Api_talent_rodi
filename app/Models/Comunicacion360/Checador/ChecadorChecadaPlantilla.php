<?php
namespace App\Models\Comunicacion360\Checador;

use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;
use App\Models\Comunicacion360\Checador\ChecadorMetodo;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use Illuminate\Database\Eloquent\Model;

class ChecadorChecadaPlantilla extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_checada_plantillas';

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'nombre',
        'descripcion',
        'regla_fuera_ubicacion',
        'requiere_ubicacion',
        'requiere_dispositivo',
        'permite_offline',
        'permite_manual_admin',
        'activo',
    ];

    protected $casts = [
        'id_portal'            => 'integer',
        'id_cliente'           => 'integer',
        'requiere_ubicacion'   => 'boolean',
        'requiere_dispositivo' => 'boolean',
        'permite_offline'      => 'boolean',
        'permite_manual_admin' => 'boolean',
        'activo'               => 'boolean',
    ];

    public function metodos()
    {
        return $this->belongsToMany(
            ChecadorMetodo::class,
            'checador_checada_plantilla_metodos',
            'id_plantilla',
            'id_metodo'
        )
            ->withPivot([
                'obligatorio',
                'orden',
                'activo',
            ])
            ->wherePivot('activo', 1)
            ->withTimestamps()
            ->orderBy('checador_checada_plantilla_metodos.orden');
    }
    public function ubicaciones()
    {
        return $this->belongsToMany(
            ChecadorUbicacion::class,
            'checador_checada_plantilla_ubicaciones',
            'id_plantilla',
            'id_ubicacion'
        )
            ->withPivot(['obligatorio', 'activo'])
            ->wherePivot('activo', 1)
            ->withTimestamps();
    }
    public function horarios()
    {
        return $this->belongsToMany(
            ChecadorHorarioPlantilla::class,
            'checador_checada_plantilla_horarios',
            'id_plantilla',
            'id_horario_plantilla'
        )
            ->withPivot([
                'obligatorio',
                'activo',
            ])
            ->wherePivot('activo', 1)
            ->withTimestamps();
    }
}
