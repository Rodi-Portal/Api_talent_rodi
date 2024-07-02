<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionEstudios extends Model
{
    protected $table = 'verificacion_estudios';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'fecha_solicitud',
        'finalizado',
        'fecha_finalizado',
        'status',
    ];

    /**
     * Obtener todas las verificaciones de estudios por ID de candidato.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getVerificarEstudiosByCandidatoId($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->get();
    }
}
