<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentacion extends Model
{
    protected $connection = 'rodi_main';
    protected $table = 'tipo_documentacion';
    public $timestamps = false;

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'nombre_ingles',
        'status',
        'eliminado'
    ];
}