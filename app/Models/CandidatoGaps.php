<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoGaps extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_gaps'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'fecha_inicio',
        'fecha_fin',
        'razon',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public static function getGapsByCandidatoId($id_candidato)
    {
        return self::where('id_candidato', $id_candidato)->first();
    }
}
