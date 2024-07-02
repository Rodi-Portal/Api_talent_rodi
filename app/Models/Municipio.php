<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Municipio extends Model
{
    protected $table = 'municipio'; // Nombre de la tabla en la base de datos
    protected $primaryKey = 'id'; // Nombre de la clave primaria

    // Indicar si se usan los timestamps 'created_at' y 'updated_at'
    public $timestamps = false;

    // Campos que se pueden llenar con asignación masiva
    protected $fillable = [
        'id_estado',
        'consecutivo_estado',
        'nombre',
        'clave',
        'completo',
    ];

    // Relación con el estado
    public function estado()
    {
        return $this->belongsTo(Estado::class, 'id_estado', 'id');
    }
}
