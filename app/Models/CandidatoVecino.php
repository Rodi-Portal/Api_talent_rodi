<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoVecino extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'candidato_vecino';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'nombre',
        'telefono',
        'domicilio',
        'tiempo_conocerlo',
        'opinion_trabajador',
        'candidato_problemas',
        'recomienda',
        'concepto_candidato',
        'concepto_familia',
        'civil_candidato',
        'hijos_candidato',
        'sabe_trabaja',
        'notas',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function getById($id)
    {
        return $this->where('id_candidato', $id)->get()->toArray();
    }
}