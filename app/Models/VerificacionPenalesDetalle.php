<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificacionPenalesDetalle extends Model
{
    use HasFactory;

    protected $table = 'verificacion_penales_detalle';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'id_verificacion_penales',
        'fecha',
        'comentarios',
    ];

    protected $dates = [
        'fecha',
    ];

    /**
     * Relación con la verificación penal principal.
     */
    public function verificacion()
    {
        return $this->belongsTo(VerificacionPenales::class, 'id_verificacion_penales');
    }
}
