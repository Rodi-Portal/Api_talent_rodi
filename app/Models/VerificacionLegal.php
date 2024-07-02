<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificacionLegal extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'verificacion_legal';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'penal',
        'penal_notas',
        'civil',
        'civil_notas',
        'laboral',
        'laboral_notas',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function getById($id_candidato)
    {
        return $this->where('id_candidato', $id_candidato)->first();
    }
}
