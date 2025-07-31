<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventosOption extends Model
{
    // Nombre de la tabla (si no sigues la convención)
    protected $table = 'eventos_option';
    protected $connection = 'portal_main';
    // Si tu conexión NO es la default:
    // protected $connection = 'portal_main';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'name',
        'color',
        'id_portal',
        'creacion',
    ];

    // Si no quieres que Eloquent maneje automáticamente los timestamps
    public $timestamps = false; // o true si vas a usar created_at/updated_at

    // Relación con Portal
    public function portal()
    {
        return $this->belongsTo(Portal::class, 'id_portal', 'id');
    }
}
