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

    // ✅ Campos que puedes llenar en asignación masiva
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

    // ❌ De momento NO usamos cast aquí porque hay mezcla en la BD.
    // Cuando todo esté limpio, puedes volver a activarlo.
    //
    // protected $casts = [
    //     'dias_descanso' => 'array',
    // ];

    protected $hidden = ['puesto'];

    public function empleado()
    {
        return $this->belongsTo(Empleado::class, 'id_empleado');
    }

    /**
     * ✅ ACCESSOR:
     * Siempre que LEAS $laboral->dias_descanso
     * te regresa un array limpio de días.
     */
    public function getDiasDescansoAttribute($value)
    {
        // Nada guardado
        if ($value === null || $value === '') {
            return [];
        }

        // Si ya viene como array (por algún cast previo o colección),
        // solo normalizamos
        if (is_array($value)) {
            return $this->normalizarDias($value);
        }

        // Si viene como string, puede estar:
        // - JSON normal: ["Domingo","Sábado"]
        // - JSON doble: "[\"Domingo\",\"Sábado\"]"
        // - Texto simple: "Domingo, Sábado"
        if (is_string($value)) {
            $trimmed = trim($value);

            // Intento 1: json_decode directo
            $decoded = json_decode($trimmed, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                // 1.1 Array normal
                if (is_array($decoded)) {
                    return $this->normalizarDias($decoded);
                }

                // 1.2 String doblemente codificado
                if (is_string($decoded)) {
                    $decoded2 = json_decode($decoded, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2)) {
                        return $this->normalizarDias($decoded2);
                    }
                }
            }

            // Intento 2: quitar corchetes [ ] y partir por comas
            $sinCorchetes = preg_replace('/^\[|\]$/', '', $trimmed);
            if (strpos($sinCorchetes, ',') !== false) {
                $partes = array_map(function ($d) {
                    return trim($d, " \t\n\r\0\x0B\"'");
                }, explode(',', $sinCorchetes));

                return $this->normalizarDias($partes);
            }

            // Intento 3: un solo valor tipo "Domingo"
            if ($sinCorchetes !== '') {
                return $this->normalizarDias([$sinCorchetes]);
            }

            return [];
        }

        // Último recurso: lo forzamos a array
        return $this->normalizarDias((array) $value);
    }

    /**
     * ✅ MUTATOR:
     * Siempre que ASIGNES $laboral->dias_descanso = ...
     * se guarda como JSON bien formado en la BD.
     */
    public function setDiasDescansoAttribute($value)
    {
        // Nada o vacío → guardamos NULL
        if ($value === null || $value === '' || (is_array($value) && count($value) === 0)) {
            $this->attributes['dias_descanso'] = null;
            return;
        }

        // Si no es array, intentamos convertirlo
        if (!is_array($value)) {
            // Intento 1: viene como JSON string
            $tmp = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
                $value = $tmp;
            } else {
                // Intento 2: texto "Lunes, Martes"
                $value = array_map('trim', explode(',', $value));
            }
        }

        // Normalizamos días y quitamos duplicados
        $value = $this->normalizarDias($value);

        // Guardamos como JSON plano
        $this->attributes['dias_descanso'] = json_encode(array_values($value));
    }

    /**
     * 🔧 Normaliza nombres de días y evita duplicados
     * Siempre regresa algo tipo: ["Lunes","Domingo"]
     */
    protected function normalizarDias(array $dias)
    {
        $map = [
            'lunes'      => 'Lunes',
            'martes'     => 'Martes',
            'miercoles'  => 'Miércoles',
            'miércoles'  => 'Miércoles',
            'jueves'     => 'Jueves',
            'viernes'    => 'Viernes',
            'sabado'     => 'Sábado',
            'sábado'     => 'Sábado',
            'domingo'    => 'Domingo',
        ];

        $limpios = [];

        foreach ($dias as $d) {
            if ($d === null) {
                continue;
            }

            $key    = mb_strtolower(trim($d), 'UTF-8');
            $normal = $map[$key] ?? trim($d);

            if ($normal !== '' && !in_array($normal, $limpios, true)) {
                $limpios[] = $normal;
            }
        }

        return $limpios;
    }
}
