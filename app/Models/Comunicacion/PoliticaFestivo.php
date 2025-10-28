<?php

namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;

class PoliticaFestivo extends Model
{
    // Usa la MISMA conexión que PoliticaAsistencia (ajústala si tu modelo la define)

    protected $table = 'politica_festivos';
    protected $connection = 'portal_main';

    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'id_politica_asistencia',
        'fecha',
        'nombre',
        'es_laborado',
    ];

    protected $casts = [
        'fecha'       => 'date:Y-m-d',
        'es_laborado' => 'boolean',
    ];

    public function politica()
    {
        return $this->belongsTo(PoliticaAsistencia::class, 'id_politica_asistencia');
    }
}