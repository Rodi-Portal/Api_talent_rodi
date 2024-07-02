<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoEscolaridad extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'tipo_escolaridad';
    public $timestamps = false;

    protected $fillable = [
        'fecha_creacion',
        'fecha_edicion',
        'id_usuario',
        'nombre',
        'nombre_ingles',
        'status',
        'eliminado',
    ];

    // Customize relationships or add additional methods as needed

}