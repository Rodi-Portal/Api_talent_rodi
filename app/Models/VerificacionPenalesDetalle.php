<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificacionPenalesDetalle extends Model
{
    protected $table = 'verificacion_penales_detalle';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_verificacion_penales',
        'fecha',
        'comentarios',
    ];

    protected $dates = [
        'fecha',
    ];

    /**
     * Obtener los detalles de verificación penales para un candidato específico.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function getDetalleVerificacion($idCandidato)
    {
        return self::select('D.*')
            ->from('verificacion_penales_detalle as D')
            ->join('verificacion_penales as V', 'V.id', '=', 'D.id_verificacion_penales')
            ->where('V.id_candidato', $idCandidato)
            ->get();
    }
}
