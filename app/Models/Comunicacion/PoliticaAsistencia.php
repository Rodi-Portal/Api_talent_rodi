<?php

namespace App\Models\Comunicacion;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PoliticaAsistencia extends Model
{
    /** Si usas una conexión distinta, cámbiala o déjala null para usar la default */
    protected $connection = 'portal_main';

    protected $table = 'politica_asistencia';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    /** Timestamps personalizados de tu tabla */
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';
    public $timestamps = true;

    /** Campos asignables en masa (alineados con tu estructura) */
    protected $fillable = [
        'id_portal',
        'id_cliente',
        'scope',
        'id_empleado',
        'nombre',
        'vigente_desde',
        'vigente_hasta',
        'timezone',
        'hora_entrada',
        'hora_salida',
        'trabaja_sabado',
        'trabaja_domingo',
        'tolerancia_minutos',
        'retardos_por_falta',
        'contar_salida_temprano',
        'descuento_retardo_modo',
        'descuento_retardo_valor',
        'descuento_falta_modo',
        'descuento_falta_valor',
        'usar_descuento_falta_laboral',
        'calcular_extras',
        'criterio_extra',
        'horas_dia_empleado',
        'minutos_gracia_extra',
        'tope_horas_extra',
        'horario_json',
        'reglas_json',
        'estado',
    ];

    /** Casts prácticos para lectura/escritura */
    protected $casts = [
        'id_portal'  => 'integer',
        'id_cliente' => 'integer',
        'vigente_desde' => 'date:Y-m-d',
        'vigente_hasta' => 'date:Y-m-d',
        // hora_entrada / hora_salida: guárdalas y léelas como string "HH:MM:SS"
        'hora_entrada' => 'string',
        'hora_salida'  => 'string',

        'trabaja_sabado'  => 'boolean',
        'trabaja_domingo' => 'boolean',
        'contar_salida_temprano' => 'boolean',

        'tolerancia_minutos' => 'integer',
        'retardos_por_falta' => 'integer',

        'descuento_retardo_modo'  => 'string',
        'descuento_retardo_valor' => 'decimal:2',
        'descuento_falta_modo'    => 'string',
        'descuento_falta_valor'   => 'decimal:2',
        'usar_descuento_falta_laboral' => 'boolean',

        'calcular_extras'    => 'boolean',
        'criterio_extra'     => 'string',
        'horas_dia_empleado' => 'decimal:2',
        'minutos_gracia_extra' => 'integer',
        'tope_horas_extra'     => 'decimal:2',

        'horario_json' => 'array',
        'reglas_json'  => 'array',

        'estado' => 'string',
        'timezone' => 'string',
        'scope'    => 'string',
        'id_empleado' => 'string',
        'nombre'      => 'string',
    ];

    /* =========================
     * Scopes usados por tu controlador
     * ========================= */
    /** Filtra por portal */
    public function scopeDelPortal(Builder $q, int $portalId): Builder
    {
        return $q->where('id_portal', $portalId);
    }

    /** Solo publicadas */
    public function scopePublicadas(Builder $q): Builder
    {
        return $q->where('estado', 'publicada');
    }

    /**
     * Vigentes en una fecha Y-m-d (considera null como "sin límite")
     * Ej: PoliticaAsistencia::delPortal(1)->vigentesEn('2025-10-26')->get()
     */
    public function scopeVigentesEn(Builder $q, string $ymd): Builder
    {
        return $q
            ->where(function ($qq) use ($ymd) {
                $qq->whereNull('vigente_desde')
                   ->orWhere('vigente_desde', '<=', $ymd);
            })
            ->where(function ($qq) use ($ymd) {
                $qq->whereNull('vigente_hasta')
                   ->orWhere('vigente_hasta', '>=', $ymd);
            });
    }

    /* =========================
     * Relaciones
     * ========================= */

    /** Festivos ligados a esta política */
    public function festivos()
    {
        return $this->hasMany(PoliticaFestivo::class, 'id_politica_asistencia');
    }

    /**
     * (Opcional) Si tienes modelo Empleado:
     * return $this->belongsToMany(Empleado::class, 'politica_asistencia_empleado', 'id_politica_asistencia', 'id_empleado');
     */

    /**
     * (Opcional) Si tienes modelo Cliente/Sucursal:
     * return $this->belongsToMany(Cliente::class, 'politica_asistencia_cliente', 'id_politica_asistencia', 'id_cliente');
     */
}
