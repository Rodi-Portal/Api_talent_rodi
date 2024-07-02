<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionMayoresEstudio extends Model
{
    protected $connection = 'rodi_main';
    protected $table = 'verificacion_mayores_estudios';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'id_tipo_studies',
        'periodo',
        'escuela',
        'ciudad',
        'certificado',
        'comentarios',
    ];


     /**
     * Obtener todas las verificaciones de mayores estudios por ID de candidato.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getMayoresByCandidatoId($idCandidato)
    {
        return $this->select('verificacion_mayores_estudios.*', 'grado_estudio.nombre as nombre_grado')
                    ->leftJoin('grado_estudio', 'grado_estudio.id', '=', 'verificacion_mayores_estudios.id_tipo_studies')
                    ->where('verificacion_mayores_estudios.id_candidato', $idCandidato)
                    ->get();
    }
    // Aquí puedes agregar métodos adicionales según sea necesario
}

