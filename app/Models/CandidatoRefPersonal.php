<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CandidatoRefPersonal extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'candidato_ref_personal'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'nombre',
        'telefono',
        'domicilio',
        'tiempo_conocerlo',
        'donde_conocerlo',
        'candidato_trabajo',
        'sabe_trabajo',
        'sabe_vive',
        'opinion_persona',
        'opinion_trabajador',
        'candidato_problemas',
        'recomienda',
        'comentario',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'id_usuario');
    }

    public  function getByCandidatoId($id_candidato)
    {
        return self::where('id_candidato', $id_candidato)->first();
    }
}
