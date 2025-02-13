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
    public $timestamps = false;

    // Especificar los campos que son asignables
    protected $fillable = [
        'id_empleado',
        'sueldo_base',
        'horas_extras', 
        'comisiones',
        'bonificaciones',
        'pago_dias_festivos',
        'descuento_ausencias',
        'aguinaldo',
        'vacaciones',
        'prima_vacacional',
        'vales_despensa',
        'fondo_ahorro',
        'prestamos',
        'descuentos_adicionales',
    ];

    // Definir la relación con el modelo de Empleado
    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id');
    }
}
