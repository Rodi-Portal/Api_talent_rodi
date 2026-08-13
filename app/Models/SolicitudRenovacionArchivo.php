<?php

namespace App\Models;

use App\Models\Auth\AdministradorAuth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudRenovacionArchivo extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_APROBADA  = 'aprobada';
    public const ESTADO_RECHAZADA = 'rechazada';
    public const ESTADO_CANCELADA = 'cancelada';

    public const TIPO_DOCUMENTO = 'documento';
    public const TIPO_CURSO     = 'curso';
    public const TIPO_EXAMEN    = 'examen';

    protected $connection = 'portal_main';

    protected $table = 'solicitudes_renovacion_archivo';

    public $timestamps = false;

    protected $fillable = [
        'id_portal',
        'id_cliente',
        'id_empleado',
        'tipo',
        'id_origen',
        'archivo_actual',
        'edicion_origen',
        'archivo_propuesto',
        'nombre_original',
        'mime_type',
        'size_bytes',
        'storage_path',
        'estado',
        'id_usuario_resolvio',
        'comentario_colaborador',
        'comentario_resolucion',
        'fecha_vencimiento_aprobada',
        'creacion',
        'edicion',
        'resolucion',
    ];

    protected $casts = [
        'id'                   => 'integer',
        'id_portal'            => 'integer',
        'id_cliente'           => 'integer',
        'id_empleado'          => 'integer',
        'id_origen'            => 'integer',
        'size_bytes'           => 'integer',
        'id_usuario_resolvio'  => 'integer',
        'edicion_origen'       => 'datetime',
        'creacion'             => 'datetime',
        'edicion'              => 'datetime',
        'resolucion'           => 'datetime',
        'fecha_vencimiento_aprobada' => 'date:Y-m-d',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(
            Empleado::class,
            'id_empleado',
            'id'
        );
    }

    public function administradorResolvio(): BelongsTo
    {
        return $this->belongsTo(
            AdministradorAuth::class,
            'id_usuario_resolvio',
            'id'
        );
    }
}