<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreNominaEmpleado extends Model
{
    use HasFactory;

    protected $connection = 'portal_main';
    protected $table      = 'pre_nomina_empleados';
    protected $primaryKey = 'id';
    public    $timestamps = false;

    // Campos EXACTOS según tu esquema
    protected $fillable = [
        'creacion',
        'edicion',

        'id_empleado',
        'id_periodo_nomina',

        'sueldo_base',
        'sueldo_asimilado',

        'horas_extras',
        'pago_horas_extra',
        'pago_horas_extra_a',

        'pago_dias_festivos',
        'pago_dias_festivos_a',

        'descuento_ausencias',
        'descuento_ausencias_a',

        'aguinaldo',
        'aguinaldo_a',

        'prestamos',

        'dias_festivos',
        'dias_ausencia',
        'dias_vacaciones',

        'pago_vacaciones',
        'pago_vacaciones_a',
        'prima_vacacional',

        'deducciones_extra',
        'deducciones_extra_a',
        'prestaciones_extra',
        'prestaciones_extra_a',

        'sueldo_total',
        'sueldo_total_a',
        'sueldo_total_t',
    ];

    // Casts útiles (números y JSON)
   protected $casts = [
    'id_empleado'       => 'integer',
    'id_periodo_nomina' => 'integer',

    // Sueldos y montos (2 decimales)
    'sueldo_base'        => 'decimal:2',
    'sueldo_asimilado'   => 'decimal:2',

    // Cantidades enteras
    'horas_extras'       => 'integer',
    'dias_festivos'      => 'integer',
    'dias_ausencia'      => 'integer',
    'dias_vacaciones'    => 'integer',

    // Pagos y descuentos (2 decimales)
    'pago_horas_extra'    => 'decimal:2',
    'pago_horas_extra_a'  => 'decimal:2',
    'pago_dias_festivos'  => 'decimal:2',
    'descuento_ausencias' => 'decimal:2',
    'descuento_ausencias_a'=> 'decimal:2',
    'pago_vacaciones'     => 'decimal:2',
    'pago_vacaciones_a'   => 'decimal:2',
    'aguinaldo'           => 'decimal:2',
    'aguinaldo_a'         => 'decimal:2',
    'prestamos'           => 'decimal:2',
    'prima_vacacional'    => 'decimal:2',

    // En tu DB esta es DECIMAL(10,0)
    'pago_dias_festivos_a'=> 'decimal:0',

    // JSON
    'prestaciones_extra'   => 'array',
    'deducciones_extra'    => 'array',
    'prestaciones_extra_a' => 'array',
    'deducciones_extra_a'  => 'array',

    // Totales (2 decimales)
    'sueldo_total'   => 'decimal:2',
    'sueldo_total_a' => 'decimal:2',
    'sueldo_total_t' => 'decimal:2',

    'creacion' => 'datetime',
    'edicion'  => 'datetime',
];


    public function empleado()
    {
        // FK: pre_nomina_empleados.id_empleado -> empleados.id (PK)
        return $this->belongsTo(Empleado::class, 'id_empleado', 'id');
    }

    public function periodo()
    {
        return $this->belongsTo(PeriodoNomina::class, 'id_periodo_nomina');
    }
}
