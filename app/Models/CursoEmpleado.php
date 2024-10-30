<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CursoEmpleado extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table = 'cursos_empleados';
    public $timestamps = false;
    protected $connection = 'portal_main';
    // Clave primaria
    protected $primaryKey = 'id';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'employee_id',
        'name',
        'name_document',
        'id_opcion_curso',
        'description',
        'expiry_date',
        'expiry_reminder',
        'origen'
    ];


    // Si necesitas definir relaciones, puedes hacerlo aquí
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'employee_id', 'id');
    }
    

    public static function getCursosPorEmpleado($employeeId)
    {
        return self::where('employee_id', $employeeId)->get();
    }

}
