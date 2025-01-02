<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificacionDomicilio extends Model
{
    use HasFactory;

    protected $table = 'verificacion_domicilio';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'comentario',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    /**
     * Relación con el usuario que creó o editó la verificación de domicilio.
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    /**
     * Relación con el candidato asociado a la verificación de domicilio.
     */
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    /**
     * Obtener todas las verificaciones de domicilio por ID de candidato.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getVerificarDomicilioByCandidatoId($idCandidato)
    {
        return $this->where('id_candidato', $idCandidato)->get();
    }
}
