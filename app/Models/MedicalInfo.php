<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MedicalInfo extends Model
{
    use HasFactory;

    // Especifica la tabla si no sigue la convención de pluralización de Laravel
    protected $table = 'medical_info';
    protected $connection = 'portal_main';
    // Especifica los campos que se pueden asignar masivamente
    protected $fillable = [
        'creacion',
        'edicion',
        'id_empleado',
        'peso',
        'edad',
        'alergias_medicamentos',
        'alergias_alimentos',
        'enfermedades_cronicas',
        'cirugias',
        'tipo_sangre',
        'contacto_emergencia',
        'medicamentos_frecuentes',
        'lesiones',
        'otros_padecimientos',
        'otros_padecimientos2'  // Campo para fecha de edición
    ];

    // Desactiva los timestamps automáticos
    public $timestamps = false;

    // Relación con el modelo Empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
