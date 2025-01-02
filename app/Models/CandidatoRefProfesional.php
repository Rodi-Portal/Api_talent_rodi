<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoRefProfesional extends Model
{
    use HasFactory;

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

    protected $dates = [
        'creacion',
        'edicion',
    ];

    /**
     * Relación con el modelo Usuario (quién creó o editó la referencia).
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    /**
     * Relación con el modelo Candidato.
     */
    public function candidato()
    {
        return $this->belongsTo(Candidato::class, 'id_candidato');
    }

    /**
     * Función para obtener referencias profesionales por ID de candidato.
     *
     * @param int $idCandidato
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getById($idCandidato)
    {
        return self::where('id_candidato', $idCandidato)->get();
    }
}
