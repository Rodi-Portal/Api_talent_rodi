<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatoEmpresa extends Model
{
    protected $table = 'candidato_empresa';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'nombre',
        'tipo',
        'calle',
        'colonia',
        'cp',
        'telefono',
        'antiguedad',
        'comentarios',
    ];

    // Opcionalmente, puedes definir campos de fecha para castear automáticamente
    protected $dates = [
        'creacion',
        'edicion',
    ];

    // Función para obtener la empresa del candidato por id_candidato
    public static function getById($idCandidato)
    {
        return self::where('id_candidato', $idCandidato)->first();
    }
}
