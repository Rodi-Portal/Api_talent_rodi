<?php

namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;

class NotificacionesRecordatorios extends Model
{
    protected $table = 'notificaciones_recordatorios';
       protected $connection = 'portal_main';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_portal','id_cliente','notificaciones_activas','status','horarios',
        'whatsapp','lada1','telefono1','lada2','telefono2',
        'correo','correo1','correo2',
        'cursos','evaluaciones','expediente',
        'creacion','id_usuario','creado_en','actualizado_en'
    ];

    protected $casts = [
        'notificaciones_activas' => 'boolean',
        'status' => 'boolean',
        'whatsapp' => 'boolean',
        'correo' => 'boolean',
        'creacion' => 'date',
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
    ];
}
