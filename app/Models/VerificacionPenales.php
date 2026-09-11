<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificacionPenales extends Model
{
    use HasFactory;

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

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
        'fecha_solicitud' => 'datetime',
        'fecha_finalizado' => 'datetime',
        'finalizado' => 'boolean',
    ];

    /**
     * Relación con los detalles de verificación penales.
     */
    public function detalles()
    {
        return $this->hasMany(VerificacionPenalesDetalle::class, 'id_verificacion_penales');
    }

    /**
     * Obtener todas las verificaciones penales de un candidato por su id.
     *
     * @param int $idCandidato ID del candidato
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getByCandidatoId($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->with('detalles')->get();
    }
}
