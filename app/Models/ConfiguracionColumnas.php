<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionColumnas extends Model {
    protected $table = 'configuracion_columnas';
    public $timestamps = false;
  protected $connection = 'portal_main';
    protected $casts = [
        'columnas' => 'array',
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    protected $fillable = [
        'id_cliente', 'id_usuario', 'id_portal', 'modulo', 'columnas'
    ];
}
