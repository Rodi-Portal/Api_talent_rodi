<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoNivelZona extends Model
{
    use HasFactory;

    protected $connection = 'rodi_main';
    protected $table = 'tipo_nivel_zona'; // Nombre de la tabla en la base de datos

    protected $fillable = [
        'creacion',
        'edicion',
        'id_usuario',
        'nombre',
        'status',
        'eliminado',
    ];

    protected $casts = [
        'creacion' => 'datetime',
        'edicion' => 'datetime',
    ];

    // Método para obtener una fila por el campo id
    public static function getById($id)
    {
        return self::where('id', $id)->first();
    }
}
