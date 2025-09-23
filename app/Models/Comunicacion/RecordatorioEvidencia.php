<?php
namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;

class RecordatorioEvidencia extends Model
{
    protected $table = 'recordatorio_evidencia';
       protected $connection = 'portal_main';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_portal','id_cliente','id_recordatorio',
        'tipo','titulo','descripcion','monto','moneda','referencia',
        'url_archivo','id_usuario','creado_en','actualizado_en','eliminado_en'
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
        'eliminado_en' => 'datetime',
    ];
}
