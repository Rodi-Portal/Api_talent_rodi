<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaboralesEmpleado extends Model
{
    use HasFactory;

    // Nombre de la tabla en la base de datos
    protected $table = 'laborales_empleado';

    // Llave primaria
    protected $primaryKey = 'id';

    // Indicar si el ID es autoincremental (por defecto es true)
    public $incrementing = true;

    // Si la tabla no tiene timestamps, se debe poner false
    public $timestamps    = false;

    protected $connection = 'portal_main';
  
    // Definir los campos que se pueden asignar masivamente
    protected $fillable = [
        'id_empleado',
        'tipo_contrato',
        'otro_tipo_contrato',
        'tipo_regimen',
        'tipo_jornada',
        'horas_dia',
        'grupo_nomina',
        'periodicidad_pago',
        'dias_descanso',
        'vacaciones_disponibles',
        'sueldo_diario',
        'sueldo_mes',
        'pago_dia_festivo',
        'pago_hora_extra',
        'dias_aguinaldo',
        'prima_vacacional',
        'prestamo_pendiente',
        'descuento_ausencia',
    ];

    protected $hidden = [
        'puesto',
    ];

    // Definir la relación con la tabla empleados (asumiendo que existe un modelo Empleado)
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
