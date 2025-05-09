<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmpleadoCampoExtra extends Model
{
    use HasFactory;

    // Especifica el nombre de la tabla
    protected $table = 'empleado_campos_extra';
    protected $connection = 'portal_main';

    // Definir los campos que se pueden asignar masivamente
    protected $fillable = [
        'id_empleado', 
        'nombre', 
        'valor',
        'creacion', 
        'edicion'
    ];

    // Indicar que las fechas son gestionadas automáticamente
    const CREATED_AT = 'creacion';
    const UPDATED_AT = 'edicion';

    // Relación con el modelo Empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
