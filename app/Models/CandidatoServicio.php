<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoServicio extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato_servicio';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'agua',
        'drenaje',
        'electricidad',
        'alumbrado',
        'iglesia',
        'transporte',
        'hospital',
        'policia',
        'mercado',
        'plaza_comercial',
        'aseo_publico',
        'areas_verdes',
        'otros',
        'servicios_basicos',
        'vias_acceso',
        'rutas_transporte',
        'status',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function getById($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }
}
