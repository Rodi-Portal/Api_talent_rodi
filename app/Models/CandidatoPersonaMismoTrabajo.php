<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CandidatoPersonaMismoTrabajo extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_persona_mismo_trabajo';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'nombre',
        'puesto',
    ];

    /**
     * Get the row by id_candidato.
     *
     * @param int $id_candidato
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function getContactosMismoTrabajo($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->get();
    }
}