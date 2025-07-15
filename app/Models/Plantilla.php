<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plantilla extends Model
{
    use HasFactory;

    protected $table = 'plantillas';
    protected $connection = 'portal_main';

    protected $fillable = [
        'nombre_personalizado',
        'nombre_plantilla',
        'titulo',
        'asunto',
        'cuerpo',
        'saludo',
        'logo_path',
        'id_usuario',
        'id_cliente',
        'id_portal',
    ];

    // Relaciones

    public function adjuntos()
    {
        return $this->hasMany(PlantillaAdjunto::class, 'id_plantilla');
    }

    public function usuario()
    {
        return $this->belongsTo(UsuarioPortal::class, 'id_usuario');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'id_cliente');
    }
}
