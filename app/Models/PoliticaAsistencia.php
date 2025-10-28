<?php
// app/Models/PoliticaAsistencia.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PoliticaAsistencia extends Model
{
    protected $table = 'politica_asistencia';
    protected $connection = 'portal_main';
    protected $primaryKey = 'id';

    const CREATED_AT = 'creado_en';
    const UPDATED_AT = 'actualizado_en';

    // ===== Constantes útiles =====
    public const SCOPE_PORTAL   = 'PORTAL';
    public const SCOPE_SUCURSAL = 'SUCURSAL';
    public const SCOPE_EMPLEADO = 'EMPLEADO';

    public const ESTADO_BORRADOR  = 'borrador';
    public const ESTADO_PUBLICADA = 'publicada';

    public const RETARDO_NINGUNO     = 'ninguno';
    public const RETARDO_POR_EVENTO  = 'por_evento';
    public const RETARDO_POR_MINUTO  = 'por_minuto';

    public const FALTA_NINGUNO       = 'ninguno';
    public const FALTA_PORCENTAJE    = 'porcentaje_dia';
    public const FALTA_FIJO          = 'fijo';

    public const EXTRA_SOBRE_SALIDA   = 'sobre_salida';
    public const EXTRA_SOBRE_HORAS_DIA = 'sobre_horas_dia';

    protected $fillable = [
        // Tenancy / alcance básico (incluye legado: id_cliente, id_empleado único)
        'id_portal','id_cliente','id_empleado','scope',
        // Identidad / vigencia
        'nombre','vigente_desde','vigente_hasta','timezone',
        // Jornada
        'hora_entrada','hora_salida','trabaja_sabado','trabaja_domingo',
        // Retardos / faltas
        'tolerancia_minutos','retardos_por_falta','contar_salida_temprano',
        'descuento_retardo_modo','descuento_retardo_valor',
        'descuento_falta_modo','descuento_falta_valor',
        'usar_descuento_falta_laboral',
        // Extras
        'calcular_extras','criterio_extra','horas_dia_empleado',
        'minutos_gracia_extra','tope_horas_extra',
        // JSON / estado
        'horario_json','reglas_json','estado',
    ];

    protected $casts = [
        'id_portal' => 'integer',
        'id_cliente' => 'integer',
        'trabaja_sabado' => 'boolean',
        'trabaja_domingo' => 'boolean',
        'tolerancia_minutos' => 'integer',
        'retardos_por_falta' => 'integer',
        'contar_salida_temprano' => 'boolean',
        'descuento_retardo_valor' => 'decimal:2',
        'descuento_falta_valor' => 'decimal:2',
        'usar_descuento_falta_laboral' => 'boolean',
        'calcular_extras' => 'boolean',
        'horas_dia_empleado' => 'decimal:2',
        'minutos_gracia_extra' => 'integer',
        'tope_horas_extra' => 'decimal:2',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
    ];

    /* ============================================================
     *  Relaciones (opcional: actívalas si tienes los modelos)
     * ============================================================ */
    /*
    public function empleados()
    {
        return $this->belongsToMany(
            \App\Models\Empleado::class,
            'politica_asistencia_empleado',
            'id_politica_asistencia',
            'id_empleado'
        )->usingConnection($this->getConnectionName());
    }

    public function clientes()
    {
        return $this->belongsToMany(
            \App\Models\Cliente::class,
            'politica_asistencia_cliente',
            'id_politica_asistencia',
            'id_cliente'
        )->usingConnection($this->getConnectionName());
    }
    */

    // Referencia simple (legado)
    // public function cliente(){ return $this->belongsTo(\App\Models\Cliente::class, 'id_cliente'); }

    /* ============================================================
     *  Scopes básicos
     * ============================================================ */
    public function scopeDelPortal(Builder $q, int $idPortal): Builder
    {
        return $q->where('id_portal', $idPortal);
    }

    public function scopePublicadas(Builder $q): Builder
    {
        return $q->where('estado', self::ESTADO_PUBLICADA);
    }

    public function scopeDeSucursal(Builder $q, int $idCliente): Builder
    {
        return $q->where('id_cliente', $idCliente);
    }

    public function scopeGlobal(Builder $q): Builder
    {
        return $q->whereNull('id_cliente');
    }

    /* ============================================================
     *  Helpers de pivotes (sin modelos relacionados)
     *  — garantizan usar la conexión 'portal_main'
     * ============================================================ */

    /**
     * Sincroniza los ids de empleados en la tabla pivote.
     * Acepta strings/ints; normaliza a string (como viene del front).
     */
    public function syncEmpleados(array $ids): void
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        $conn = DB::connection($this->getConnectionName());

        $conn->table('politica_asistencia_empleado')
            ->where('id_politica_asistencia', $this->id)
            ->delete();

        if (!empty($ids)) {
            $conn->table('politica_asistencia_empleado')->insert(
                array_map(fn($eid) => [
                    'id_politica_asistencia' => $this->id,
                    'id_empleado'            => $eid,
                ], $ids)
            );
        }
    }

    /**
     * Sincroniza los ids de clientes (sucursales) en la tabla pivote.
     */
    public function syncClientes(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $conn = DB::connection($this->getConnectionName());

        $conn->table('politica_asistencia_cliente')
            ->where('id_politica_asistencia', $this->id)
            ->delete();

        if (!empty($ids)) {
            $conn->table('politica_asistencia_cliente')->insert(
                array_map(fn($cid) => [
                    'id_politica_asistencia' => $this->id,
                    'id_cliente'             => $cid,
                ], $ids)
            );
        }
    }

    /**
     * Sincroniza alcance completo según scope:
     *  - EMPLEADO  => ids_empleados (pivote) y limpia clientes
     *  - SUCURSAL  => ids_clientes (pivote) y limpia empleados
     *  - PORTAL    => limpia ambos
     *
     * @param string $scope One of PORTAL|SUCURSAL|EMPLEADO
     * @param array $idsEmpleados
     * @param array $idsClientes
     */
    public function syncAlcance(string $scope, array $idsEmpleados = [], array $idsClientes = []): void
    {
        if ($scope === self::SCOPE_EMPLEADO) {
            $this->syncEmpleados($idsEmpleados);
            $this->syncClientes([]); // limpia
            // opcional: almacenar id_empleado legado cuando solo venga 1
            $this->id_empleado = count($idsEmpleados) === 1 ? (string)$idsEmpleados[0] : null;
            $this->id_cliente  = null;
            $this->scope       = self::SCOPE_EMPLEADO;
        } elseif ($scope === self::SCOPE_SUCURSAL) {
            $this->syncClientes($idsClientes);
            $this->syncEmpleados([]); // limpia
            // opcional: id_cliente legado cuando solo venga 1
            $this->id_cliente  = count($idsClientes) === 1 ? (int)$idsClientes[0] : null;
            $this->id_empleado = null;
            $this->scope       = self::SCOPE_SUCURSAL;
        } else { // PORTAL
            $this->syncEmpleados([]);
            $this->syncClientes([]);
            $this->id_empleado = null;
            $this->id_cliente  = null;
            $this->scope       = self::SCOPE_PORTAL;
        }

        // guarda los campos de “legado”/scope si cambiaron
        $this->save();
    }
}
