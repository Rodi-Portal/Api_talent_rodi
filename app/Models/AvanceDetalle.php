<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AvanceDetalle extends Model
{
    use HasFactory;

    // Conexión a la base de datos
    protected $connection = 'rodi_main';

    // Nombre de la tabla
    protected $table = 'avance_detalle';

    // Desactivar timestamps automáticos
    public $timestamps = false;

    // Campos que se pueden asignar de forma masiva
    protected $fillable = [
        'id_avance',
        'fecha',
        'comentarios',
        'adjunto',
    ];

    // Relación con el modelo Avance
    public function avance()
    {
        return $this->belongsTo(Avance::class, 'id_avance');
    }
}
