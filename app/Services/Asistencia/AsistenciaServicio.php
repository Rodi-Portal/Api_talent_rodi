<?php
namespace App\Services\Asistencia;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Evalúa asistencia por empleado/fecha y registra eventos en calendario:
 * - Respeta políticas (ResolverPolitica) y calendario (Vacaciones/Incapacidad/Permiso).
 * - Calcula Retardo y Salida anticipada (primer IN, último OUT).
 * - Inserción/actualización idempotente en calendario_eventos.
 * - Respeta banderas de política: registrar_falta, registrar_retardo, contar_salida_temprano (columna o reglas_json).
 *
 * NOTA: Siempre usamos empleados.id (int) como PK real.
 */
final class AsistenciaServicio
{
    public const EV_VACACIONES  = 'Vacaciones';
    public const EV_INCAPACIDAD = 'Incapacidad';
    public const EV_PERMISO     = 'Permiso';
    public const EV_FALTA       = 'Falta';
    public const EV_RETARDO     = 'Retardo';
    public const EV_SALIDA_ANT  = 'Salida anticipada';

    public function __construct(
        private ResolverPolitica $resolver,
        private string $conn = 'portal_main'
    ) {}

    public function withConnection(string $conn): self
    {
        $this->conn     = $conn;
        $this->resolver = new ResolverPolitica($conn);
        return $this;
    }

    /**
     * Evalúa un día concreto para un empleado (empleados.id).
     * @return array{status:string, details?:array}
     */
    public function evaluateDay(int $portalId, ?int $clienteId, int $empleadoId, string $fechaYmd): array
    {
        // 1) Política efectiva
        $pol = $this->resolver->getEffectivePolicy($portalId, $clienteId, $empleadoId, $fechaYmd);
        if (! $pol) {
            // limpiar auto-eventos si no hay política
            $this->syncSystemEvents($empleadoId, $fechaYmd, []);
            return ['status' => 'no_policy'];
        }

        // 1.1 switches de política (columna o reglas_json)
        $sw = $this->policySwitches($pol);
        $tz = $pol['timezone'] ?? 'America/Mexico_City';

        // 2) Bloqueos de calendario (vacaciones/incapacidad/permiso).
        if ($this->hasBlockingEvent($empleadoId, $fechaYmd, [
            self::EV_VACACIONES, self::EV_INCAPACIDAD, self::EV_PERMISO,
        ])) {
            $this->syncSystemEvents($empleadoId, $fechaYmd, []);
            return ['status' => 'blocked_by_calendar'];
        }

        // 3) ¿Trabaja hoy? (laborales_empleado con fallback a política)
        if (! $this->shouldWorkToday($empleadoId, $fechaYmd, $pol)) {
            $this->syncSystemEvents($empleadoId, $fechaYmd, []);
            return ['status' => 'day_off'];
        }

        // 4) Checadas del día (primer IN, último OUT)
        [$firstIn, $lastOut] = $this->firstInLastOut($portalId, $clienteId, $empleadoId, $fechaYmd);

        $expectedIn  = CarbonImmutable::parse("$fechaYmd {$pol['hora_entrada']}", $tz);
        $expectedOut = CarbonImmutable::parse("$fechaYmd {$pol['hora_salida']}", $tz);
        $tolMin      = (int) ($pol['tolerancia_minutos'] ?? 0);

        $made = [];

        // Inasistencia total
        if (! $firstIn && ! $lastOut) {
            if ($sw['registrar_falta']) {
                $this->upsertCalendarEvent($empleadoId, $fechaYmd, self::EV_FALTA, 'Falta por inasistencia (sin checadas).');
                $made[] = self::EV_FALTA;
            }
            $this->syncSystemEvents($empleadoId, $fechaYmd, $made);
            return ['status' => 'done', 'details' => compact('made')];
        }

        // Retardo (opcional según política)
        if ($firstIn) {
            if ($sw['registrar_retardo']) {
                $late = $firstIn->greaterThan($expectedIn) ? $expectedIn->diffInMinutes($firstIn) : 0;
                if ($late > $tolMin) {
                    $this->upsertCalendarEvent($empleadoId, $fechaYmd, self::EV_RETARDO, "Retardo de {$late} minutos.");
                    $made[] = self::EV_RETARDO;
                }
            }
        } else {
            // Sin entrada (pero quizá hay salida) => Falta de entrada
            if ($sw['registrar_falta']) {
                $this->upsertCalendarEvent($empleadoId, $fechaYmd, self::EV_FALTA, 'Falta (no se detectó entrada).');
                $made[] = self::EV_FALTA;
            }
            $this->syncSystemEvents($empleadoId, $fechaYmd, $made);
            return ['status' => 'done', 'details' => compact('made')];
        }

        // Salida anticipada (solo si la política indica contarla)
        if ($sw['contar_salida_temprano']) {
            if ($lastOut) {
                $early = $lastOut->lessThan($expectedOut) ? $lastOut->diffInMinutes($expectedOut) : 0;
                if ($early > 0) {
                    $this->upsertCalendarEvent($empleadoId, $fechaYmd, self::EV_SALIDA_ANT, "Salida anticipada de {$early} minutos.");
                    $made[] = self::EV_SALIDA_ANT;
                }
            } else {
                // No hay OUT -> registro incompleto; lo tratamos como salida anticipada si política lo desea
                $this->upsertCalendarEvent($empleadoId, $fechaYmd, self::EV_SALIDA_ANT, 'No se detectó salida (registro incompleto).');
                $made[] = self::EV_SALIDA_ANT;
            }
        }

        // Sincroniza: elimina (HARD) auto-eventos obsoletos
        $this->syncSystemEvents($empleadoId, $fechaYmd, $made);

        return ['status' => 'done', 'details' => compact('made')];
    }

    /** Evalúa en lote. */
    public function evaluateBatch(array $groups): array
    {
        $out = [];
        foreach ($groups as $g) {
            $res   = $this->evaluateDay($g['portalId'], $g['clienteId'] ?? null, $g['empleadoId'], $g['fecha']);
            $out[] = array_merge($g, $res);
        }
        return $out;
    }

    /* -------------------------- helpers internos -------------------------- */

    /** Lee switches de política (tolerante: columna o reglas_json) */
    private function policySwitches(array $pol): array
    {
        // defaults seguros
        $registrarFalta   = true;
        $registrarRetardo = true;

        // 1) Si existen columnas físicas, úsalas
        // (el resolver te da $pol como array; si agregó estos campos, perfecto)
        if (array_key_exists('registrar_falta', $pol)) {
            $registrarFalta = (int) $pol['registrar_falta'] === 1;
        }
        if (array_key_exists('registrar_retardo', $pol)) {
            $registrarRetardo = (int) $pol['registrar_retardo'] === 1;
        }

        // 2) reglas_json
        if (! empty($pol['reglas_json'])) {
            try {
                $rj = is_array($pol['reglas_json']) ? $pol['reglas_json'] : json_decode((string) $pol['reglas_json'], true);
                if (is_array($rj)) {
                    if (array_key_exists('registrar_falta', $rj)) {
                        $registrarFalta = (bool) $rj['registrar_falta'];
                    }
                    if (array_key_exists('registrar_retardo', $rj)) {
                        $registrarRetardo = (bool) $rj['registrar_retardo'];
                    }
                    // Si quisieras forzar salida_temprano desde reglas_json:
                    if (array_key_exists('contar_salida_temprano', $rj)) {
                        $pol['contar_salida_temprano'] = $rj['contar_salida_temprano'] ? 1 : 0;
                    }
                }
            } catch (\Throwable $e) {
                // ignora parseo inválido
            }
        }

        // 3) salida anticipada: usa columna existente (de tu tabla) o override desde reglas_json
        $contarSalidaTemprano = ! empty($pol['contar_salida_temprano']) && (int) $pol['contar_salida_temprano'] === 1;

        return [
            'registrar_falta'        => $registrarFalta,
            'registrar_retardo'      => $registrarRetardo,
            'contar_salida_temprano' => $contarSalidaTemprano,
        ];
    }

    /** Primer IN y último OUT del día */
    private function firstInLastOut(int $portalId, ?int $clienteId, int $empleadoId, string $fechaYmd): array
    {
        $q = DB::connection($this->conn)->table('checadas')
            ->where('id_portal', $portalId)
            ->where('id_empleado', $empleadoId)
            ->whereDate('fecha', $fechaYmd)
            ->orderBy('check_time');

        if (! is_null($clienteId)) {
            $q->where('id_cliente', $clienteId);
        }

        $rows = $q->get(['tipo', 'check_time'])->all();

        $firstIn = null;
        $lastOut = null;
        foreach ($rows as $r) {
            if ($r->tipo === 'in' && ! $firstIn) {
                $firstIn = Carbon::parse($r->check_time);
            }
            if ($r->tipo === 'out') {
                $lastOut = Carbon::parse($r->check_time);
            }
        }
        return [$firstIn, $lastOut];
    }

    /** ¿Hay evento de vacaciones/incapacidad/permiso que bloquee ese día? */
    private function hasBlockingEvent(int $empleadoId, string $fechaYmd, array $names): bool
    {
        $ids = DB::connection($this->conn)->table('eventos_option')
            ->whereIn('name', $names)->pluck('id')->all();

        if (! $ids) {
            return false;
        }

        $cnt = DB::connection($this->conn)->table('calendario_eventos')
            ->where('id_empleado', $empleadoId)
            ->where('eliminado', 0)
            ->whereIn('id_tipo', $ids)
            ->whereDate('inicio', '<=', $fechaYmd)
            ->whereDate('fin', '>=', $fechaYmd)
            ->count();

        return $cnt > 0;
    }

    /** Upsert idempotente en calendario_eventos por empleado+fecha+tipo */
    private function upsertCalendarEvent(int $empleadoId, string $fechaYmd, string $name, string $desc): void
    {
        try {
            $tipoId = DB::connection($this->conn)->table('eventos_option')->where('name', $name)->value('id');
            if (! $tipoId) {
                $tipoId = DB::connection($this->conn)->table('eventos_option')->insertGetId([
                    'name'      => $name,
                    'color'     => null,
                    'id_portal' => null,
                    'creacion'  => now(),
                ]);
                Log::info('[Asistencia] Tipo creado en eventos_option', ['name' => $name, 'id' => $tipoId]);
            }

            $exists = DB::connection($this->conn)->table('calendario_eventos')
                ->where('id_empleado', $empleadoId)
                ->where('eliminado', 0)
                ->whereDate('inicio', $fechaYmd)
                ->whereDate('fin', $fechaYmd)
                ->where('id_tipo', $tipoId)
                ->first();

            if ($exists) {
                DB::connection($this->conn)->table('calendario_eventos')
                    ->where('id', $exists->id)
                    ->update([
                        'descripcion' => $desc,
                        'updated_at'  => now(),
                    ]);
                Log::info('[Asistencia] Evento actualizado', [
                    'id' => $exists->id, 'emp' => $empleadoId, 'fecha' => $fechaYmd, 'tipoId' => $tipoId,
                ]);
                return;
            }

            DB::connection($this->conn)->table('calendario_eventos')->insert([
                'id_usuario'        => null,
                'id_empleado'       => $empleadoId,
                'id_periodo_nomina' => null,
                'inicio'            => $fechaYmd,
                'fin'               => $fechaYmd,
                'dias_evento'       => 1,
                'descripcion'       => $desc,
                'archivo'           => null,
                'created_at'        => now(),
                'updated_at'        => now(),
                'id_tipo'           => $tipoId,
                'eliminado'         => 0,
            ]);
            Log::info('[Asistencia] Evento insertado', [
                'emp' => $empleadoId, 'fecha' => $fechaYmd, 'tipo' => $name, 'tipoId' => $tipoId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Asistencia] Error al upsert calendario_eventos', [
                'emp' => $empleadoId, 'fecha' => $fechaYmd, 'name' => $name, 'msg' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ======== DÍAS LABORALES / DESCANSO ========

    private array $workdaysCache = [];

    private function shouldWorkToday(int $empleadoId, string $fechaYmd, array $pol): bool
    {
        $tz      = $pol['timezone'] ?? 'America/Mexico_City';
        $weekday = (int) CarbonImmutable::parse($fechaYmd, $tz)->dayOfWeekIso; // 1..7

        if (! isset($this->workdaysCache[$empleadoId])) {
            $this->workdaysCache[$empleadoId] = $this->resolveEmployeeWorkdays($empleadoId, $pol);
        }
        return in_array($weekday, $this->workdaysCache[$empleadoId], true);
    }

    private function resolveEmployeeWorkdays(int $empleadoId, array $pol): array
    {
        if (! Schema::connection($this->conn)->hasTable('laborales_empleado')) {
            return $this->policyWorkdays($pol);
        }

        $row = DB::connection($this->conn)->table('laborales_empleado')
            ->where('id_empleado', $empleadoId)
            ->orderByDesc('id')
            ->first();

        if (! $row) {
            return $this->policyWorkdays($pol);
        }

        $candidateCols = [
            'dias_descanso', 'descanso', 'descansos',
            'dias_descanso_json', 'descansos_json',
        ];

        foreach ($candidateCols as $col) {
            if (isset($row->$col) && $this->looksLikeDaysJson($row->$col)) {
                $daysOff = $this->parseDaysJsonToIso((string) $row->$col);
                if ($daysOff !== null) {
                    return $this->invertDaysOffToWorkdays($daysOff, $pol);
                }

            }
        }

        foreach ((array) $row as $k => $v) {
            if (is_string($v) && $this->looksLikeDaysJson($v)) {
                $daysOff = $this->parseDaysJsonToIso($v);
                if ($daysOff !== null) {
                    return $this->invertDaysOffToWorkdays($daysOff, $pol);
                }

            }
        }

        return $this->policyWorkdays($pol);
    }

    private function policyWorkdays(array $pol): array
    {
        $wd = [1, 2, 3, 4, 5];
        if (! empty($pol['trabaja_sabado'])) {
            $wd[] = 6;
        }

        if (! empty($pol['trabaja_domingo'])) {
            $wd[] = 7;
        }

        return $wd;
    }

    private function invertDaysOffToWorkdays(array $daysOffIso, array $pol): array
    {
        $all  = [1, 2, 3, 4, 5, 6, 7];
        $work = array_values(array_diff($all, array_unique($daysOffIso)));
        return $work ?: $this->policyWorkdays($pol);
    }

    private function looksLikeDaysJson(?string $s): bool
    {
        if ($s === null) {
            return false;
        }

        $t = trim($s);
        if ($t === '' || $t[0] !== '[') {
            return false;
        }

        return (bool) preg_match('/lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo/i', $t);
    }

    private function parseDaysJsonToIso(string $json): ?array
    {
        try {
            $arr = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($arr)) {
                return null;
            }

            $out = [];
            foreach ($arr as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $d = $this->spanishWeekdayToIso($item);
                if ($d !== null) {
                    $out[] = $d;
                }

            }
            return $out ? array_values(array_unique($out)) : [];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function spanishWeekdayToIso(string $name): ?int
    {
        $n = $this->normalizeNoAccents(mb_strtolower(trim($name)));
        return match ($n) {
            'lunes', 'lun'   => 1,
            'martes', 'mar'  => 2,
            'miercoles', 'miercoles.', 'mie', 'mie.' => 3,
            'jueves', 'jue'  => 4,
            'viernes', 'vie' => 5,
            'sabado', 'sab'  => 6,
            'domingo', 'dom' => 7,
            default => null,
        };
    }

    private function normalizeNoAccents(string $s): string
    {
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D) ?: $s;
        }
        $s = preg_replace('/\p{Mn}/u', '', $s);
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = preg_replace('/[^a-z0-9._ -]/i', '', $s);
        return $s;
    }

    // ======== Limpieza (HARD DELETE) de auto-eventos Falta/Retardo/Salida ========

    private function syncSystemEvents(int $empleadoId, string $fechaYmd, array $madeNames): void
    {
        $systemNames = [self::EV_FALTA, self::EV_RETARDO, self::EV_SALIDA_ANT];

        $tipoRows = DB::connection($this->conn)->table('eventos_option')
            ->whereIn('name', $systemNames)
            ->get(['id', 'name']);

        if ($tipoRows->isEmpty()) {
            return;
        }

        $nameToId = [];
        foreach ($tipoRows as $r) {
            $nameToId[$r->name] = (int) $r->id;
        }

        $keepIds = [];
        foreach ($madeNames as $n) {
            if (isset($nameToId[$n])) {
                $keepIds[] = $nameToId[$n];
            }
        }

        $allIds    = array_values($nameToId);
        $deleteIds = array_values(array_diff($allIds, $keepIds));

        if (empty($deleteIds)) {
            return;
        }

        DB::connection($this->conn)->table('calendario_eventos')
            ->where('id_empleado', $empleadoId)
            ->whereNull('id_usuario')
            ->whereIn('id_tipo', $deleteIds)
            ->whereDate('inicio', $fechaYmd)
            ->whereDate('fin', $fechaYmd)
            ->delete();
    }

    public function handleCalendarEventDeletion(int $eventoId): void
    {
        $ev = DB::connection($this->conn)
            ->table('calendario_eventos as ce')
            ->join('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->where('ce.id', $eventoId)
            ->select('ce.*', 'eo.name as tipo_nombre')
            ->first();

        if (! $ev) {
            Log::warning('[Asistencia] Evento no encontrado para deletion', ['id' => $eventoId]);
            return;
        }

        // Cargamos portal/cliente del empleado (para checadas)
        $emp = DB::connection($this->conn)->table('empleados')
            ->where('id', $ev->id_empleado)
            ->select('id_portal', 'id_cliente')
            ->first();

        if (! $emp) {
            Log::warning('[Asistencia] Empleado no encontrado para evento eliminado', ['evento' => $eventoId, 'id_empleado' => $ev->id_empleado]);
            return;
        }

        $portalId   = (int) $emp->id_portal;
        $clienteId  = $emp->id_cliente !== null ? (int) $emp->id_cliente : null;
        $empleadoId = (int) $ev->id_empleado;

        // Rango de fechas inclusivo del evento
        $inicio = Carbon::parse($ev->inicio)->startOfDay();
        $fin    = Carbon::parse($ev->fin)->startOfDay();

        for ($d = $inicio->copy(); $d->lte($fin); $d = $d->addDay()) {
            $fechaYmd = $d->toDateString();

            // Traer política efectiva para este día
            $pol = $this->resolver->getEffectivePolicy($portalId, $clienteId, $empleadoId, $fechaYmd);
            if (! $pol) {
                Log::info('[Asistencia] Sin política en día al borrar evento; solo purgamos eventos auto', compact('empleadoId', 'fechaYmd'));
                $this->purgeDayEventsByNames($empleadoId, $fechaYmd, [self::EV_FALTA, self::EV_RETARDO, self::EV_SALIDA_ANT]);
                continue;
            }

            $tz          = $pol['timezone'] ?? 'America/Mexico_City';
            $expectedIn  = Carbon::parse("$fechaYmd {$pol['hora_entrada']}", $tz);
            $expectedOut = Carbon::parse("$fechaYmd {$pol['hora_salida']}", $tz);

            // Acciones compensatorias según tipo
            switch ($ev->tipo_nombre) {
                case self::EV_FALTA:
                    // Si quito una Falta, queremos marcar asistencia (IN/OUT en horas de política)
                    $this->ensureChecada($portalId, $clienteId, $empleadoId, $expectedIn, 'in');
                    $this->ensureChecada($portalId, $clienteId, $empleadoId, $expectedOut, 'out');
                    break;

                case self::EV_RETARDO:
                    // Si quito Retardo, asegurar IN a la hora de entrada de política (si no existe una ≤ expectedIn)
                    [$firstIn] = $this->firstInLastOut($portalId, $clienteId, $empleadoId, $fechaYmd);
                    if (! $firstIn || $firstIn->greaterThan($expectedIn)) {
                        $this->ensureChecada($portalId, $clienteId, $empleadoId, $expectedIn, 'in');
                    }
                    break;

                case self::EV_SALIDA_ANT:
                    // Si quito Salida anticipada, asegurar OUT a la hora de salida de política (si no existe una ≥ expectedOut)
                    [, $lastOut] = $this->firstInLastOut($portalId, $clienteId, $empleadoId, $fechaYmd);
                    if (! $lastOut || $lastOut->lessThan($expectedOut)) {
                        $this->ensureChecada($portalId, $clienteId, $empleadoId, $expectedOut, 'out');
                    }
                    break;

                default:
                    // Para otros tipos (Vacaciones, Incapacidad, …) no tocamos checadas
                    break;
            }

            // Purga eventos auto (Falta/Retardo/Salida anticipada) del día
            $this->purgeDayEventsByNames($empleadoId, $fechaYmd, [self::EV_FALTA, self::EV_RETARDO, self::EV_SALIDA_ANT]);

            // Re-evalúa el día: si tras el ajuste hiciera falta recrear algo, lo hará evaluateDay
            $this->evaluateDay($portalId, $clienteId, $empleadoId, $fechaYmd);
        }
    }

/** Inserta una checada si no existe exactamente esa (id_portal, id_cliente, id_empleado, check_time). */
    private function ensureChecada(int $portalId, ?int $clienteId, int $empleadoId, Carbon $dt, string $tipo, string $clase = 'work'): void
    {
        $fecha = $dt->toDateString();
        $dtStr = $dt->format('Y-m-d H:i:s');

        $exists = DB::connection($this->conn)->table('checadas')->where([
            'id_portal'   => $portalId,
            'id_cliente'  => $clienteId,
            'id_empleado' => $empleadoId,
            'check_time'  => $dtStr,
        ])->first();

        if ($exists) {
            return;
        }

        DB::connection($this->conn)->table('checadas')->insert([
            'id_portal'   => $portalId,
            'id_cliente'  => $clienteId,
            'id_empleado' => $empleadoId,
            'fecha'       => $fecha,
            'check_time'  => $dtStr,
            'tipo'        => $tipo,  // 'in' | 'out'
            'clase'       => $clase, // 'work'
            'dispositivo' => null,
            'origen'      => 'manual', // <- importante distinguirlo
            'observacion' => 'Ajuste por eliminación de evento',
            'hash'        => sha1($portalId . '|' . $clienteId . '|' . $empleadoId . '|' . $dtStr),
            'creado_en'   => now(),
        ]);

        Log::info('[Asistencia] Checada insertada por compensación', compact('portalId', 'clienteId', 'empleadoId', 'dtStr', 'tipo'));
    }

/** Borra (soft o hard) los eventos auto de ese día por nombre(s). */
    private function purgeDayEventsByNames(int $empleadoId, string $fechaYmd, array $names): void
    {
        $ids = DB::connection($this->conn)->table('eventos_option')->whereIn('name', $names)->pluck('id')->all();
        if (! $ids) {
            return;
        }

        // Si tu tabla soporta soft delete con 'eliminado', úsalo:
        DB::connection($this->conn)->table('calendario_eventos')
            ->where('id_empleado', $empleadoId)
            ->whereIn('id_tipo', $ids)
            ->whereDate('inicio', '<=', $fechaYmd)
            ->whereDate('fin', '>=', $fechaYmd)
            ->update([
                'eliminado'  => 1,
                'updated_at' => now(),
            ]);
    }
}
