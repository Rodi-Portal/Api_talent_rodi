<?php
namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;

class ChecadorUbicacion extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checador_ubicaciones';

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'nombre',
        'descripcion',
        'tipo_zona',
        'latitud',
        'longitud',
        'radio_metros',
        'polygon_json',
        'direccion',
        'referencia',
        'activa',
        'qr_modo',
        'qr_token_fijo',
        'qr_expira_segundos',
        'qr_actualizado_en',
    ];

    protected $casts = [
        'id_portal'          => 'integer',
        'id_cliente'         => 'integer',
        'latitud'            => 'decimal:7',
        'longitud'           => 'decimal:7',
        'radio_metros'       => 'integer',
        'polygon_json'       => 'array',
        'activa'             => 'integer',
        'qr_expira_segundos' => 'integer',
        'qr_actualizado_en'  => 'datetime',
    ];
}
