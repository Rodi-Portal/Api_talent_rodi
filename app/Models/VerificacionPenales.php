<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionPenales extends Model
{
    protected $table = 'verificacion_penales';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'id_candidato',
        'fecha_solicitud',
        'finalizado',
        'fecha_finalizado',
        'status',
    ];

    protected $dates = [
        'creacion',
        'edicion',
        'fecha_solicitud',
        'fecha_finalizado',
    ];

    protected $casts = [
        'finalizado' => 'boolean',
    ];

    /**
     * Función para obtener todas las verificaciones penales de un candidato por su id.
     *
     * @param int $idCandidato ID del candidato
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByCandidatoId($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->get();
    }

    // Define relaciones u otros métodos según sea necesario
}