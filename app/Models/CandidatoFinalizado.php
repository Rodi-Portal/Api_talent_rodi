<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoFinalizado extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_finalizado'; // Nombre de la tabla en la base de datos
    protected $primaryKey = 'id';

    // Deshabilitar marcas de tiempo automáticas si no están en la tabla
    public $timestamps = false;
    protected $fillable = [
        'creacion',
        'id_usuario',
        'id_candidato',
        'descripcion_personal1',
        'descripcion_personal2',
        'descripcion_personal3',
        'descripcion_personal4',
        'descripcion_laboral1',
        'descripcion_laboral2',
        'descripcion_socio1',
        'descripcion_socio2',
        'descripcion_visita1',
        'descripcion_visita2',
        'conclusion_investigacion',
        'recomendable',
        'tiempo',
        'comentario',
    ];

    protected $casts = [
        'creacion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public static function getByCandidatoId($id_candidato)
    {
        return self::where('id_candidato', $id_candidato)->first();
    }
}