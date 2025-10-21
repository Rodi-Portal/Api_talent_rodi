<?php
// app/Models/PoliticaAsistencia.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PoliticaAsistencia extends Model
{
    /** Nombre de la tabla */
    protected $table = 'politica_asistencia';
    protected $connection = 'portal_main';

    /** PK */
    protected $primaryKey = 'id';

    /** Timestamps personalizados */
    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';

    /** Asignación masiva */
    protected $fillable = [
        'id_portal',
        'id_cliente',
        'nombre',

        // Horario base
        'hora_entrada',
        'hora_salida',
        'trabaja_sabado',
        'trabaja_domingo',

        // Retardos y faltas
        'tolerancia_minutos',
        'retardos_por_falta',
        'contar_salida_temprano',

        // Descuentos
        'descuento_retardo_modo',
        'descuento_retardo_valor',
        'descuento_falta_modo',
        'descuento_falta_valor',

        // Horas extra
        'calcular_extras',
        'criterio_extra',
        'horas_dia_empleado',
        'minutos_gracia_extra',
        'tope_horas_extra',

        // Metadatos
        'estado',
    ];

    /** Conversión de tipos */
    protected $casts = [
        'id_portal'               => 'integer',
        'id_cliente'              => 'integer',
        'trabaja_sabado'          => 'boolean',
        'trabaja_domingo'         => 'boolean',
        'tolerancia_minutos'      => 'integer',
        'retardos_por_falta'      => 'integer',
        'contar_salida_temprano'  => 'boolean',

        'descuento_retardo_valor' => 'decimal:2',
        'descuento_falta_valor'   => 'decimal:2',

        'calcular_extras'         => 'boolean',
        'horas_dia_empleado'      => 'decimal:2',
        'minutos_gracia_extra'    => 'integer',
        'tope_horas_extra'        => 'decimal:2',

        // 'hora_entrada' y 'hora_salida' las dejamos como string (TIME en MySQL)
    ];

    /** Constantes de enumeración */
    public const ESTADO_BORRADOR  = 'borrador';
    public const ESTADO_PUBLICADA = 'publicada';

    public const RETARDO_NINGUNO     = 'ninguno';
    public const RETARDO_POR_EVENTO  = 'por_evento';
    public const RETARDO_POR_MINUTO  = 'por_minuto';

    public const FALTA_NINGUNO       = 'ninguno';
    public const FALTA_PORCENTAJE    = 'porcentaje_dia';
    public const FALTA_FIJO          = 'fijo';

    public const EXTRA_SOBRE_SALIDA    = 'sobre_salida';
    public const EXTRA_SOBRE_HORAS_DIA  = 'sobre_horas_dia';

    /* ===========================
     * Relaciones (opcionales)
     * =========================== */

    // Si tienes modelos Portal / Cliente(Sucursal), descomenta y ajusta:
    // public function portal(){ return $this->belongsTo(Portal::class, 'id_portal'); }
    // public function sucursal(){ return $this->belongsTo(Cliente::class, 'id_cliente'); }

    /* ===========================
     * Scopes útiles
     * =========================== */

    /** Políticas del portal */
    public function scopeDelPortal(Builder $q, int $idPortal): Builder
    {
        return $q->where('id_portal', $idPortal);
    }

    /** Publicadas */
    public function scopePublicadas(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_PUBLICADA);
    }

    /** Por sucursal (id_cliente) o globales (NULL) */
    public function scopeParaSucursalOGlobal(Builder $q, ?int $idCliente): Builder
    {
        return $q->where(function ($qq) use ($idCliente) {
            $qq->where('id_cliente', $idCliente)
               ->orWhereNull('id_cliente');
        });
    }

    /** Exactamente para sucursal */
    public function scopeDeSucursal(Builder $q, int $idCliente): Builder
    {
        return $q->where('id_cliente', $idCliente);
    }

    /** Global (id_cliente NULL) */
    public function scopeGlobal(Builder $q): Builder
    {
        return $q->whereNull('id_cliente');
    }

    /* ===========================
     * Resolución de política efectiva (estática)
     * Prioridad: empleado -> sucursal -> portal (global)
     * =========================== */

    /**
     * Resuelve la política efectiva para un empleado dado.
     *
     * @param int $idPortal
     * @param int|null $idCliente  Sucursal del empleado (id_cliente)
     * @param int|null $idPoliticaEmpleado  Si el empleado tiene política específica (columna empleado.id_politica)
     * @return PoliticaAsistencia|null
     */
    public static function resolverParaEmpleado(
        int $idPortal,
        ?int $idCliente,
        ?int $idPoliticaEmpleado
    ): ?self {
        // 1) Si el empleado tiene política específica y está publicada
        if ($idPoliticaEmpleado) {
            $empPolicy = static::query()
                ->delPortal($idPortal)
                ->where('id', $idPoliticaEmpleado)
                ->publicadas()
                ->first();
            if ($empPolicy) {
                return $empPolicy;
            }
        }

        // 2) Buscar por sucursal (id_cliente) publicada más reciente
        if ($idCliente) {
            $byBranch = static::query()
                ->delPortal($idPortal)
                ->deSucursal($idCliente)
                ->publicadas()
                ->orderByDesc('actualizado_en')
                ->first();
            if ($byBranch) {
                return $byBranch;
            }
        }

        // 3) Global del portal (id_cliente NULL) publicada más reciente
        return static::query()
            ->delPortal($idPortal)
            ->global()
            ->publicadas()
            ->orderByDesc('actualizado_en')
            ->first();
    }
}
