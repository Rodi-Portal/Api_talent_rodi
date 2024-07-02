<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionEstudiosDetalle extends Model
{
    protected $table = 'verificacion_estudios_detalle';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan

    protected $fillable = [
        'id_verificacion_estudio',
        'fecha',
        'comentarios',
    ];

    /**
     * Relación con la verificación de estudios principal.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function verificacionEstudio()
    {
        return $this->belongsTo(VerificacionEstudios::class, 'id_verificacion_estudio', 'id');
    }


    public function getDetalleVerificacion($idCandidato)
    {
        return $this->join('verificacion_estudios as V', 'V.id', '=', 'verificacion_estudios_detalle.id_verificacion_estudio')
                    ->where('V.id_candidato', $idCandidato)
                    ->get();
    }
}