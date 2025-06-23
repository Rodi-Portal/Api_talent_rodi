<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreNominaEmpleado extends Model
{
    use HasFactory;

    // Definir el nombre de la tabla en la base de datos
    protected $table = 'pre_nomina_empleados';

    // Definir la clave primaria
    protected $primaryKey = 'id';

    // Deshabilitar el uso de timestamps si no los necesitas
    public $timestamps    = false;
    protected $connection = 'portal_main';
    // Especificar los campos que son asignables
    protected $fillable = [
        'creacion',
        'edicion',
        'id_empleado',
        'id_periodo_nomina', 
        'sueldo_base',
        'horas_extras',
        'pago_horas_extra',
        'dias_festivos',
        'pago_dias_festivos',
        'dias_ausencia',
        'descuento_ausencias',
        'pago_vacaciones',
        'aguinaldo',
        'dias_vacaciones',
        'prestamos',
        'deducciones_extra',  // jason
        'prestaciones_extra', // jason
        'sueldo_total',
        'sueldo_neto',
    ];

    // Definir la relación con el modelo de Empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id');
    }
    public function periodo()
    {
        return $this->belongsTo(PeriodoNomina::class, 'id_periodo_nomina');
    }
}
