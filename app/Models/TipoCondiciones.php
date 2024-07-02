<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoCondiciones extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $connection = 'rodi_main';
    protected $table = 'tipo_condiciones';

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'status',
        'eliminado',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];
}
