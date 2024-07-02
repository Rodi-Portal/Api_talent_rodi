<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoRefProfesional extends Model
{
    protected $table = 'candidato_ref_profesional';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'numero',
        'nombre',
        'telefono',
        'tiempo_conocerlo',
        'donde_conocerlo',
        'puesto',
        'verificacion_tiempo',
        'verificacion_conocerlo',
        'verificacion_puesto',
        'cualidades',
        'desempeno',
        'recomienda',
        'comentarios',
    ];

    // Opcionalmente, puedes definir campos de fecha para castear automáticamente
    protected $dates = [
        'creacion',
        'edicion',
    ];

    // Función para obtener la referencia profesional del candidato por id_candidato
    public static function getById($idCandidato)
    {
        return self::where('id_candidato', $idCandidato)->first();
    }
}
