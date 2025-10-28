<?php

namespace App\Services\Asistencia;

use Illuminate\Support\Facades\DB;
use Carbon\CarbonImmutable;

/**
 * Resuelve la política efectiva con precedencia:
 *   EMPLEADO (directa o puente) > SUCURSAL (directa o puente) > PORTAL (y SUCURSAL genérica sin id_cliente)
 *
 * Reglas importantes:
 * - El PK real del empleado es empleados.id (int). Ese ID se recibe en $empleadoId.
 * - politica_asistencia.id_empleado y politica_asistencia_empleado.id_empleado son VARCHAR:
 *   podemos tener ahí el "código del empleado" o incluso el ID numérico como string.
 *   Por compatibilidad comparamos contra AMBOS: emp_code (si existe) y (string)$empleadoId.
 */
final class ResolverPolitica
{
    public function __construct(private string $conn = 'portal_main') {}

    /**
     * @param int                       $portalId
     * @param int|null                  $clienteId
     * @param int                       $empleadoId   (PK real: empleados.id)
     * @param string|\DateTimeInterface $fecha        (Y-m-d)
     * @return array|null
     */
    public function getEffectivePolicy(int $portalId, ?int $clienteId, int $empleadoId, $fecha): ?array
    {
        $day = $fecha instanceof \DateTimeInterface
            ? CarbonImmutable::instance($fecha)->format('Y-m-d')
            : (string)$fecha;

        // Traer emp_code solo para compatibilidad con tablas de política (varchar)
        $emp = DB::connection($this->conn)->table('empleados')
            ->select('id', 'id_empleado') // id_empleado = código opcional
            ->where('id', $empleadoId)
            ->first();

        if (!$emp) return null;

        $empCode  = trim((string)($emp->id_empleado ?? '')); // puede estar vacío
        $empIdStr = (string)$empleadoId;

        // A) EMPLEADO
        if ($p = $this->firstPolicyEmpleadoDirecta($portalId, $clienteId, $empCode, $empIdStr, $day)) return $p;
        if ($p = $this->firstPolicyEmpleadoBridge($portalId, $clienteId, $empCode, $empIdStr, $day))  return $p;

        // B) SUCURSAL
        if ($clienteId) {
            if ($p = $this->firstPolicyClienteDirecta($portalId, $clienteId, $day)) return $p;
            if ($p = $this->firstPolicyClienteBridge($portalId, $clienteId, $day))  return $p;
        }

        // C) PORTAL (y SUCURSAL genérica sin id_cliente)
        if ($p = $this->firstPolicyPortal($portalId, $day)) return $p;

        return null;
    }

    /* -------------------------- helpers internos -------------------------- */

    private function baseSelect(): array
    {
        return [
            'pa.id',
            'pa.id_portal',
            'pa.id_cliente',
            'pa.scope',
            'pa.id_empleado',
            'pa.nombre',
            'pa.vigente_desde',
            'pa.vigente_hasta',
            'pa.timezone',
            'pa.hora_entrada',
            'pa.hora_salida',
            'pa.trabaja_sabado',
            'pa.trabaja_domingo',
            'pa.tolerancia_minutos',
            'pa.retardos_por_falta',
            'pa.contar_salida_temprano',
            'pa.descuento_retardo_modo',
            'pa.descuento_retardo_valor',
            'pa.descuento_falta_modo',
            'pa.descuento_falta_valor',
            'pa.usar_descuento_falta_laboral',
            'pa.calcular_extras',
            'pa.criterio_extra',
            'pa.horas_dia_empleado',
            'pa.minutos_gracia_extra',
            'pa.tope_horas_extra',
            'pa.horario_json',
            'pa.reglas_json',
            'pa.estado',
        ];
    }

    private function filterVigencia($q, string $day)
    {
        return $q->where(function ($w) use ($day) {
            $w->whereNull('pa.vigente_desde')->orWhere('pa.vigente_desde', '<=', $day);
        })->where(function ($w) use ($day) {
            $w->whereNull('pa.vigente_hasta')->orWhere('pa.vigente_hasta', '>=', $day);
        })->where('pa.estado', '=', 'publicada');
    }

    private function firstPolicyEmpleadoDirecta(int $portalId, ?int $clienteId, string $empCode, string $empIdStr, string $day): ?array
    {
        $q = DB::connection($this->conn)->table('politica_asistencia AS pa')
            ->select($this->baseSelect())
            ->where('pa.id_portal', $portalId)
            ->where(function ($w) use ($clienteId) {
                $w->whereNull('pa.id_cliente');
                if ($clienteId) $w->orWhere('pa.id_cliente', $clienteId);
            })
            ->where(function ($w) use ($empCode, $empIdStr) {
                // scope EMPLEADO o coincidencia por id_empleado (código o id numérico en string)
                $w->where('pa.scope', 'EMPLEADO');
                if ($empCode !== '') $w->orWhere('pa.id_empleado', $empCode);
                $w->orWhere('pa.id_empleado', $empIdStr);
            });

        $this->filterVigencia($q, $day);
        $row = $q->orderByRaw("CASE WHEN pa.id_empleado IS NOT NULL THEN 1 ELSE 0 END DESC")
                 ->orderBy('pa.vigente_desde', 'desc')
                 ->first();

        return $row ? $this->rowToArray($row) : null;
    }

    private function firstPolicyEmpleadoBridge(int $portalId, ?int $clienteId, string $empCode, string $empIdStr, string $day): ?array
    {
        $q = DB::connection($this->conn)->table('politica_asistencia_empleado AS pae')
            ->join('politica_asistencia AS pa', 'pa.id', '=', 'pae.id_politica_asistencia')
            ->select($this->baseSelect())
            ->where('pa.id_portal', $portalId)
            ->where(function ($w) use ($clienteId) {
                $w->whereNull('pa.id_cliente');
                if ($clienteId) $w->orWhere('pa.id_cliente', $clienteId);
            })
            ->where(function ($w) use ($empCode, $empIdStr) {
                // El puente guarda VARCHAR: comparamos contra código y contra id numérico como string
                if ($empCode !== '') $w->where('pae.id_empleado', $empCode)->orWhere('pae.id_empleado', $empIdStr);
                else $w->where('pae.id_empleado', $empIdStr);
            });

        $this->filterVigencia($q, $day);
        $row = $q->orderBy('pa.vigente_desde', 'desc')->first();

        return $row ? $this->rowToArray($row) : null;
    }

    private function firstPolicyClienteDirecta(int $portalId, int $clienteId, string $day): ?array
    {
        $q = DB::connection($this->conn)->table('politica_asistencia AS pa')
            ->select($this->baseSelect())
            ->where('pa.id_portal', $portalId)
            ->where('pa.scope', 'SUCURSAL')
            ->where('pa.id_cliente', $clienteId);

        $this->filterVigencia($q, $day);
        $row = $q->orderBy('pa.vigente_desde', 'desc')->first();

        return $row ? $this->rowToArray($row) : null;
    }

    private function firstPolicyClienteBridge(int $portalId, int $clienteId, string $day): ?array
    {
        $q = DB::connection($this->conn)->table('politica_asistencia_cliente AS pac')
            ->join('politica_asistencia AS pa', 'pa.id', '=', 'pac.id_politica_asistencia')
            ->select($this->baseSelect())
            ->where('pa.id_portal', $portalId)
            ->where('pac.id_cliente', $clienteId);

        $this->filterVigencia($q, $day);
        $row = $q->orderBy('pa.vigente_desde', 'desc')->first();

        return $row ? $this->rowToArray($row) : null;
    }

    private function firstPolicyPortal(int $portalId, string $day): ?array
    {
        // Acepta PORTAL y también SUCURSAL con id_cliente NULL como genérica
        $q = DB::connection($this->conn)->table('politica_asistencia AS pa')
            ->select($this->baseSelect())
            ->where('pa.id_portal', $portalId)
            ->where(function ($w) {
                $w->where('pa.scope', 'PORTAL')
                  ->orWhere(function ($z) {
                      $z->where('pa.scope', 'SUCURSAL')->whereNull('pa.id_cliente');
                  });
            });

        $this->filterVigencia($q, $day);
        $row = $q->orderBy('pa.vigente_desde', 'desc')->first();

        return $row ? $this->rowToArray($row) : null;
    }

    private function rowToArray(object $row): array
    {
        $arr = (array)$row;
        foreach (['horario_json', 'reglas_json'] as $k) {
            if (array_key_exists($k, $arr) && !is_null($arr[$k])) {
                $arr[$k] = json_decode($arr[$k], true);
            }
        }
        return $arr;
    }
}
