<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenciaCliente extends Model
{
    protected $table = 'referencia_cliente';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'id_candidato',
        'numero_referencia',
        'empresa',
        'telefono',
        'tipo_empresa',
        'tiempo_conocerlo',
        'recomienda',
        'nombre',
        'comentarios',
    ];

    /**
     * Obtener todas las referencias de cliente para un candidato específico.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function getReferenciasCliente($idCandidato)
    {
        return self::where('id_candidato', $idCandidato)->get();
    }
}
