<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoParentesco extends Model
{
    protected $table = 'tipo_parentesco';
    protected $primaryKey = 'id'; // Ajustar si la clave primaria no es 'id'
    public $timestamps = false; // Deshabilitar timestamps automáticos si no se utilizan
    protected $fillable = [
        'nombre', 'nombre_ingles', 'status', 'eliminado'
    ];
}