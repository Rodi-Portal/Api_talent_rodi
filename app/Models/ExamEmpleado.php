<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamEmpleado extends Model
{
    use HasFactory;

    // Especifica la tabla si el nombre no sigue la convención
    protected $table = 'exams_empleados';
    protected $connection = 'portal_main';
    public $timestamps = false;


    // Definir los campos que pueden ser asignados en masa
    protected $fillable = [
        'creacion',
        'edicion',
        'employee_id',
        'name',
        'id_opcion',
        'description',
        'expiry_date',
        'expiry_reminder',
        'id_candidato',
        'status',
    ];

    // Si deseas que la marca de tiempo 'creacion' y 'edicion' se gestionen automáticamente

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id', 'id');
    }
    
    public function examOption()
    {
        return $this->belongsTo(ExamOption::class, 'id_opcion', 'id'); // Ajusta 'exam_option_id' según tu esquema
    }
    // Otras configuraciones o relaciones pueden ir aquí
}
