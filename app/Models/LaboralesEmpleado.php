<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LaboralesEmpleado extends Model
{
    use HasFactory;

    protected $table = 'laborales_empleado';
    protected $primaryKey = 'id';
    public $incrementing = true;
    public $timestamps = false;
    protected $connection = 'portal_main';

    // ✅ Claves SAT habilitadas para asignación masiva
    protected $fillable = [
        'id_empleado',
        // Legacy (se quedan para histórico/visualización)
        'tipo_contrato',
        'otro_tipo_contrato',
        'tipo_regimen',
        'tipo_jornada',
        'periodicidad_pago',
        // ✅ Nuevas columnas SAT
        'tipo_contrato_sat',
        'tipo_regimen_sat',
        'tipo_jornada_sat',
        'periodicidad_pago_sat',
        'horas_dia',
        'grupo_nomina',
        'dias_descanso',
        'vacaciones_disponibles',
        'sueldo_diario',
        'sueldo_asimilado',
        'sueldo_mes',
        'pago_dia_festivo',
        'pago_dia_festivo_a',
        'pago_hora_extra',
        'pago_hora_extra_a',
        'dias_aguinaldo',
        'prima_vacacional',
        'prestamo_pendiente',
        'descuento_ausencia',
        'descuento_ausencia_a',
        'sindicato',
    ];

    // ✅ Para leer/escribir dias_descanso como arreglo automáticamente
    protected $casts = [
        'dias_descanso' => 'array',
    ];

    protected $hidden = ['puesto'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }
}
