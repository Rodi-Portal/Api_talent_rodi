<?php
namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;

class Recordatorio extends Model
{
    protected $table = 'recordatorio';
       protected $connection = 'portal_main';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_portal','id_cliente','nombre','descripcion',
        'tipo','fecha_base','intervalo_meses','dias_anticipacion',
        'proxima_fecha','activo','id_usuario','creado_en','actualizado_en', 'eliminado'
    ];

    protected $casts = [
        'fecha_base' => 'date',
        'proxima_fecha' => 'date',
        'activo' => 'boolean',
        'creado_en' => 'datetime',
        'actualizado_en' => 'datetime',
    ];

     public function scopeVigentes($q)
    {
        return $q->where('eliminado', 0);
    }

    // Relación con filtro de evidencias NO eliminadas
    public function evidencias()
    {
        return $this->hasMany(RecordatorioEvidencia::class, 'id_recordatorio')
                    ->whereNull('eliminado_en');
    }
}