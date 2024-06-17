<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visita extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'visita';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'id_candidato',
        'fecha_visita',
        'fecha_inicio',
        'fecha_final',
        'descripcion',
        'hora_inicio',
        'hora_fin',
        'calle',
        'exterior',
        'interior',
        'colonia',
        'id_estado',
        'id_municipio',
        'cp',
        'celular',
        'telefono_casa',
        'telefono_otro',
        'status',
        'comentarios'
    ];
}
