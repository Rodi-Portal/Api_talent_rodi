<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Puesto extends Model
{
    protected $table = 'puesto'; // Nombre de la tabla en la base de datos
    protected $primaryKey = 'id'; // Nombre de la clave primaria

    // Indicar si se usan los timestamps 'created_at' y 'updated_at'
    public $timestamps = true;

    // Campos que se pueden llenar con asignación masiva
    protected $fillable = [
        'id_usuario',
        'nombre',
        'status',
        'eliminado',
    ];

    // Métodos adicionales si es necesario, por ejemplo:

    /**
     * Obtener todos los puestos activos.
     *
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public static function getPuestosActivos()
    {
        return self::where('status', 1)->get();
    }
}