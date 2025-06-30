<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CursoEmpleado extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table      = 'cursos_empleados';
    public $timestamps    = false;
    protected $connection = 'portal_main';
    // Clave primaria
    protected $primaryKey = 'id';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'creacion',
        'edicion',
        'employee_id',
        'name',
        'origen',
        'id_opcion',
        'description',
        'expiry_date',
        'expiry_reminder',
        'nameDocument',
        'status',
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
    public function documentOption()
    {
        return $this->belongsTo(CursosOption::class, 'id_opcion');
    }

}
