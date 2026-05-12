<?php

namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;

class ChecadorMetodo extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_metodos';

protected $fillable = [
    'id_portal',
    'id_cliente',
    'clave',
    'nombre',
    'descripcion',
    'requiere_gps',
    'requiere_qr',
    'requiere_foto',
    'es_sistema',
    'activo',
];

protected $casts = [
    'requiere_gps' => 'boolean',
    'requiere_qr' => 'boolean',
    'requiere_foto' => 'boolean',
    'es_sistema' => 'boolean',
    'activo' => 'boolean',
];
}