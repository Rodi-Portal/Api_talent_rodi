<?php
namespace App\Models\Comunicacion360\Checador;

use Illuminate\Database\Eloquent\Model;

class Checada extends Model
{
    protected $connection = 'portal_main';

    protected $table = 'checadas';

    protected $primaryKey = 'id';

    public $timestamps = true;
    const CREATED_AT   = 'creado_en';
    const UPDATED_AT   = null;

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'id_empleado',
        'id_asignacion',
        'id_ubicacion',
        'fecha',
        'check_time',
        'tipo',
        'clase',
        'dispositivo',
        'origen',
        'metodo_validacion',
        'estatus_validacion',
        'distancia_metros',
        'precision_metros',
        'latitud',
        'longitud',
        'observacion',
        'hash',
        'evidencia_foto',
        'qr_token',
        'ip_address',
        'timezone',
        'device_info',
        'metadata',
    ];

    protected $casts = [
        'id_portal'        => 'integer',
        'id_cliente'       => 'integer',
        'id_empleado'      => 'integer',
        'id_asignacion'    => 'integer',
        'id_ubicacion'     => 'integer',
        'fecha'            => 'date',
        'check_time'       => 'datetime',
        'distancia_metros' => 'decimal:2',
        'precision_metros' => 'decimal:2',
        'latitud'          => 'decimal:7',
        'longitud'         => 'decimal:7',
        'metadata'         => 'array',
    ];
}
