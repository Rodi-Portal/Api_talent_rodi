<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Services\Asistencia\ResolverPolitica;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class IncidenciasController extends Controller
{
    private string $CONN = 'portal_main';
    private string $TABLA_EVENTOS = 'calendario_eventos'; // o 'eventos_option'

    public function preview(Request $request, ResolverPolitica $resolver)
    {
        // 1) Validación suave
        $v = Validator::make($request->all(), [
            'id_portal'             => ['required','integer'],
            'cliente_ids'           => ['required','array','min:1'],
            'cliente_ids.*'         => ['integer'],
            'periodo'               => ['required','array'],
            'periodo.id'            => ['nullable','integer'],
            'periodo.fecha_inicio'  => ['required_without:periodo.id','date'],
            'periodo.fecha_fin'     => ['required_without:periodo.id','date'],
            'empleados'             => ['required','array','min:1'],
            'empleados.*'           => ['integer'],
        ]);

        if ($v->fails()) {
            Log::warning('[Incidencias/preview] validation failed', $v->errors()->toArray());
            return response()->json(['ok'=>false,'errors'=>$v->errors(),'items'=>[]], 200);
        }

        // 2) Inputs
        $portalId   = (int) $request->integer('id_portal');
        $clienteIds = array_map('intval', (array) $request->input('cliente_ids', []));
        $empIds     = array_map('intval', (array) $request->input('empleados', []));
        $per        = (array) $request->input('periodo', []);
        $fi         = substr((string) ($per['fecha_inicio'] ?? ''), 0, 10);
        $ff         = substr((string) ($per['fecha_fin']    ?? ''), 0, 10);

        Log::info('[Incidencias/preview] received', compact('portalId','clienteIds','empIds','fi','ff'));

        /* --------------------------------------------------------------------
         * 3) Empleados + Cliente + Sueldo Diario (laborales_empleado)
         * ------------------------------------------------------------------ */
        $infoByEmp = DB::connection($this->CONN)->table('empleados as e')
            ->selectRaw('
                e.id as id,
                e.id_empleado,
                e.nombre, e.paterno, e.materno,
                e.id_cliente,
                c.nombre as nombre_cliente,
                COALESCE(le.sueldo_diario, 0) as sueldo_diario
            ')
            ->leftJoin('cliente as c','c.id','=','e.id_cliente')
            ->leftJoin('laborales_empleado as le','le.id_empleado','=','e.id')
            ->where('e.id_portal', $portalId)
            ->when(!empty($clienteIds), fn($q) => $q->whereIn('e.id_cliente', $clienteIds))
            ->whereIn('e.id', $empIds)
            ->get()
            ->keyBy('id');

        $empConsiderados = $empIds;

        /* --------------------------------------------------------------------
         * 4) Calendario (JOIN eventos_option.name como tipo_txt)
         *     Tolerante a columnas opcionales y a fin = NULL
         * ------------------------------------------------------------------ */
        $hasDiasEv    = Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'dias_evento');
        $hasIdPortalE = Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'id_portal');
        $hasIdClienteE= Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'id_cliente');

        $selects = [
            'ev.id_empleado',
            'ev.inicio',
            'ev.fin',
            'ev.id_tipo',
            'ev.eliminado',
            DB::raw('COALESCE(t.name, "") as tipo_txt'),
        ];
        if ($hasDiasEv) $selects[] = 'ev.dias_evento';

        $evQuery = DB::connection($this->CONN)
            ->table($this->TABLA_EVENTOS.' as ev')
            ->leftJoin('eventos_option as t','t.id','=','ev.id_tipo')
            ->select($selects)
            ->where('ev.eliminado', 0)
            ->when(!empty($empConsiderados), fn($q)=>$q->whereIn('ev.id_empleado',$empConsiderados))
            ->whereDate('ev.inicio','<=',$ff)
            ->where(function($q) use ($fi) {
                $q->whereDate('ev.fin','>=',$fi)
                  ->orWhereNull('ev.fin');
            });

        if ($hasIdPortalE)  $evQuery->where('ev.id_portal', $portalId);
        if ($hasIdClienteE && !empty($clienteIds)) $evQuery->whereIn('ev.id_cliente', $clienteIds);

        $evRows = $evQuery->get()->groupBy('id_empleado');

        // Clasificación por tipo (ID o texto en name)
        // Ajusta estos ID si tienes catálogos fijos:
        $IDT_VACACIONES = []; // p.ej. [10]
        $IDT_AUSENCIAS  = []; // p.ej. [20]
        $IDT_FESTIVOS   = []; // p.ej. [30]

        $evAgg = [];
        foreach ($evRows as $empIdKey => $lista) {
            $vac = 0.0; $aus = 0.0; $fes = 0.0;

            foreach ($lista as $row) {
                $dias = (isset($row->dias_evento) && $row->dias_evento !== null)
                    ? (float)$row->dias_evento
                    : $this->overlapDays($row->inicio, $row->fin, $fi, $ff);

                $tipoTxt = strtolower((string)($row->tipo_txt ?? ''));
                $isVac = in_array((int)$row->id_tipo, $IDT_VACACIONES, true)
                       || str_contains($tipoTxt, 'vaca');
                $isAus = in_array((int)$row->id_tipo, $IDT_AUSENCIAS, true)
                       || str_contains($tipoTxt, 'ausen')
                       || str_contains($tipoTxt, 'falta')
                       || str_contains($tipoTxt, 'incap')
                       || str_contains($tipoTxt, 'permiso');
                $isFes = in_array((int)$row->id_tipo, $IDT_FESTIVOS, true)
                       || str_contains($tipoTxt, 'fest');

                if     ($isVac) $vac += $dias;
                elseif ($isFes) $fes += $dias;
                elseif ($isAus) $aus += $dias;
            }

            $evAgg[$empIdKey] = (object)[
                'vacaciones'        => $vac,
                'dias_ausencia_cal' => $aus,
                'dias_festivos'     => $fes,
            ];
        }

        /* --------------------------------------------------------------------
         * 5) Checadas: primer IN y último OUT (clase=work) por día
         * ------------------------------------------------------------------ */
        $checadas = DB::connection($this->CONN)->table('checadas as ch')
            ->selectRaw("
                ch.id_empleado,
                ch.fecha,
                MIN(CASE WHEN ch.clase='work' AND ch.tipo='in'  THEN ch.check_time END) as first_in,
                MAX(CASE WHEN ch.clase='work' AND ch.tipo='out' THEN ch.check_time END) as last_out
            ")
            ->where('ch.id_portal', $portalId)
            ->when(!empty($clienteIds), fn($q)=>$q->whereIn('ch.id_cliente',$clienteIds))
            ->when(!empty($empConsiderados), fn($q)=>$q->whereIn('ch.id_empleado',$empConsiderados))
            ->whereBetween('ch.fecha', [$fi, $ff])
            ->groupBy('ch.id_empleado','ch.fecha')
            ->get()
            ->groupBy('id_empleado');

        /* --------------------------------------------------------------------
         * 6) Agregado por empleado (retardos + faltas + extras + descuentos)
         * ------------------------------------------------------------------ */
        $items = [];

        foreach ($empIds as $empId) {
            $info      = $infoByEmp->get($empId);
            $clienteId = $info?->id_cliente ?? ($clienteIds[0] ?? null);
            $sueldoDia = (float)($info?->sueldo_diario ?? 0);

            // Política efectiva (usa primer día del periodo)
            $policyArr = $resolver->getEffectivePolicy($portalId, $clienteId, $empId, $fi) ?? [];

            $pol = (object) [
                'hora_entrada'           => substr((string)($policyArr['hora_entrada'] ?? '09:00'), 0, 5),
                'hora_salida'            => substr((string)($policyArr['hora_salida']  ?? '18:00'), 0, 5),
                'tolerancia_min'         => (int)($policyArr['tolerancia_minutos']     ?? 5),
                'retardos_por_falta'     => (int)($policyArr['retardos_por_falta']     ?? 3),
                'extra_umbral_min'       => (int)($policyArr['minutos_gracia_extra']   ?? 30),
                'tope_horas_extra'       => (int)($policyArr['tope_horas_extra']       ?? 0),
                'calcular_extras'        => (int)($policyArr['calcular_extras']        ?? 1) ? true : false,
                'criterio_extra'         => (string)($policyArr['criterio_extra']      ?? ''),
                'trabaja_sabado'         => (int)($policyArr['trabaja_sabado']         ?? 0),
                'trabaja_domingo'        => (int)($policyArr['trabaja_domingo']        ?? 0),
                'horario_json'           => $policyArr['horario_json'] ?? null,

                // Descuentos / opciones
                'contar_salida_temprano' => (int)($policyArr['contar_salida_temprano'] ?? 0),
                // Modos soportados:
                //  - PCT_MIN  (porcentaje por minuto residual)
                //  - PCT_DIA  (% por cada retardo residual)
                //  - PESOS_MIN (monto = minutos residuales * valorMinuto; pct = monto/sueldoDia)
                'descuento_retardo_modo' => (string)($policyArr['descuento_retardo_modo'] ?? 'PCT_MIN'),
                'descuento_retardo_valor'=> (float)($policyArr['descuento_retardo_valor'] ?? 0),
                // Faltas:
                //  - PCT_DIA (ej. 100% por falta)
                //  - FIJO_DIA (X días por falta)
                'descuento_falta_modo'   => (string)($policyArr['descuento_falta_modo']   ?? 'PCT_DIA'),
                'descuento_falta_valor'  => (float)($policyArr['descuento_falta_valor']   ?? 100),
            ];

            // Duración de jornada para valorar minuto (default 8h = 480 min)
            [$eBase, $sBase] = [$pol->hora_entrada ?: '09:00', $pol->hora_salida ?: '18:00'];
            $minsJornada = max(1, (int) round(($this->mkDt('2000-01-01',$sBase)->getTimestamp() - $this->mkDt('2000-01-01',$eBase)->getTimestamp())/60));
            if ($minsJornada <= 0) $minsJornada = 480;
            $valorMinuto = $sueldoDia > 0 ? ($sueldoDia / $minsJornada) : 0.0;

            $retardosDias   = 0;   // días con retardo
            $retardoMinTot  = 0;   // minutos totales (incl. salida temprano si aplica)
            $horasExtraMin  = 0;

            $dias = $checadas->get($empId) ?? collect();
            foreach ($dias as $d) {
                $ymd = (string) $d->fecha;

                $dow = (int) date('N', strtotime($ymd)); // 1..7
                if (($dow === 6 && !$pol->trabaja_sabado) || ($dow === 7 && !$pol->trabaja_domingo)) {
                    continue;
                }

                [$hEntrada, $hSalida] = $this->expectedTimesForDate($pol, $ymd);
                $entradaEsperada = $this->mkDt($ymd, $hEntrada);
                $salidaEsperada  = $this->mkDt($ymd, $hSalida);

                $firstIn = $d->first_in ? new \DateTime($d->first_in) : null;
                $lastOut = $d->last_out ? new \DateTime($d->last_out) : null;

                // Retardo por encima de tolerancia
                if ($firstIn) {
                    $limiteTol = (clone $entradaEsperada)->modify("+{$pol->tolerancia_min} minutes");
                    if ($firstIn > $limiteTol) {
                        $retardosDias += 1;
                        $minsLate = (int) floor(($firstIn->getTimestamp() - $limiteTol->getTimestamp()) / 60);
                        $retardoMinTot += max(0, $minsLate);
                    }
                }

                // Salida temprano como retardo (opcional)
                if ($pol->contar_salida_temprano && $lastOut && $lastOut < $salidaEsperada) {
                    $minsEarly = (int) floor(($salidaEsperada->getTimestamp() - $lastOut->getTimestamp()) / 60);
                    $retardoMinTot += max(0, $minsEarly);
                }

                // Horas extra
                if ($pol->calcular_extras && $lastOut && $lastOut > $salidaEsperada) {
                    $mins = (int) floor(($lastOut->getTimestamp() - $salidaEsperada->getTimestamp()) / 60);
                    if ($mins > $pol->extra_umbral_min) {
                        $horasExtraMin += $mins;
                    }
                }
            }

            // Faltas por retardo y residuales
            $faltasPorRet = 0;
            $retardosResiduales = $retardosDias;
            if ($pol->retardos_por_falta > 0) {
                $faltasPorRet       = intdiv($retardosDias, $pol->retardos_por_falta);
                $retardosResiduales = $retardosDias % $pol->retardos_por_falta;
            }

            // Minutos residuales (aprox proporcional a # de retardos residuales)
            $retardoMinTotResidual = $retardosDias > 0
                ? (int) round($retardoMinTot * ($retardosResiduales / $retardosDias))
                : 0;

            // Tope de horas extra (si es en minutos)
            if ($pol->tope_horas_extra > 0) {
                $horasExtraMin = min($horasExtraMin, (int) $pol->tope_horas_extra);
            }

            // Descuentos (porcentaje y montos)
            [$pctRet, $diasRet, $montoRet] = $this->calcDescuentoRetardo(
                $pol,
                $retardoMinTotResidual, // SOLO minutos residuales
                $retardosResiduales,
                $valorMinuto,
                $sueldoDia
            );

            [$pctFal, $diasFal, $montoFal] = $this->calcDescuentoFalta(
                $pol,
                $faltasPorRet,
                $sueldoDia
            );

            $descuentoPctTotal  = round($pctRet + $pctFal, 6);
            $descuentoDiasTotal = round($diasRet + $diasFal, 6);
            $descuentoMontoTot  = round($montoRet + $montoFal, 2);

            // Calendario del periodo
            $c = $evAgg[$empId] ?? null;
            $vacaciones  = (float)($c->vacaciones        ?? 0);
            $ausenciaCal = (float)($c->dias_ausencia_cal ?? 0);
            $festivos    = (float)($c->dias_festivos     ?? 0);

            // Total ausencias = calendario + faltas por retardo
            $diasAusencia = $ausenciaCal + $faltasPorRet;

            // Horas extra en horas (2 decimales)
            $horasExtras = round($horasExtraMin / 60, 2);

            // Identidad
            $codigo   = (string)($info?->id_empleado ?? $empId);
            $nombre   = trim( (($info?->nombre ?? '') . ' ' . ($info?->paterno ?? '') . ' ' . ($info?->materno ?? '')) );
            $sucursal = $info?->nombre_cliente ?? '';

            $items[] = [
                'id_empleado'              => $empId,      // PK real
                'codigo'                   => $codigo,     // id_empleado (código)
                'nombre'                   => $nombre,
                'sucursal'                 => $sucursal,

                // Puntualidad
                'retardos'                 => $retardosDias,
                'retardo_minutos_total'    => $retardoMinTot,
                'retardo_minutos_residual' => $retardoMinTotResidual,
                'faltas_por_retardo'       => $faltasPorRet,

                // Descuentos (% del día y días equivalentes)
                'descuento_retardo_pct'    => $pctRet,
                'descuento_falta_pct'      => $pctFal,
                'descuento_pct_total'      => $descuentoPctTotal,
                'descuento_dias_total'     => $descuentoDiasTotal,

                // Descuentos ($)
                'descuento_retardo_monto'  => $montoRet,
                'descuento_falta_monto'    => $montoFal,
                'descuento_monto_total'    => $descuentoMontoTot,
                'valor_minuto'             => round($valorMinuto, 6),
                'sueldo_diario'            => round($sueldoDia, 2),

                // Calendario
                'dias_festivos'            => $festivos,
                'dias_ausencia'            => $diasAusencia,
                'dias_ausencia_cal'        => $ausenciaCal,
                'dias_ausencia_extra'      => $faltasPorRet,
                'vacaciones'               => $vacaciones,

                // Extras
                'horas_extras'             => $horasExtras,
            ];
        }

        return response()->json(['ok' => true, 'items' => $items], 200);
    }

    /** Construye DateTime local a partir de Y-m-d + HH:MM */
    private function mkDt(string $ymd, string $hhmm): \DateTime
    {
        $s = trim($ymd).' '.substr(trim($hhmm), 0, 5).':00';
        return new \DateTime($s);
    }

    /**
     * Horario esperado del día (usa horario_json si existe, si no toma la base).
     * Formato: ['dias' => ['lun' => ['entrada'=>'09:00','salida'=>'18:00'], ...]]
     */
    private function expectedTimesForDate(object $pol, string $ymd): array
    {
        $entrada = $pol->hora_entrada ?: '09:00';
        $salida  = $pol->hora_salida  ?: '18:00';

        if (!is_array($pol->horario_json)) return [$entrada, $salida];

        $w = (int) date('N', strtotime($ymd)); // 1..7
        $map = [
            1 => ['lun','monday','mon','1'],
            2 => ['mar','tuesday','tue','2'],
            3 => ['mie','miércoles','miercoles','wednesday','wed','3'],
            4 => ['jue','thursday','thu','4'],
            5 => ['vie','friday','fri','5'],
            6 => ['sab','sábado','sabado','saturday','sat','6'],
            7 => ['dom','domingo','sunday','sun','7'],
        ];

        $diaCfg = null;
        if (isset($pol->horario_json['dias']) && is_array($pol->horario_json['dias'])) {
            foreach ($map[$w] as $kk) {
                if (isset($pol->horario_json['dias'][$kk]) && is_array($pol->horario_json['dias'][$kk])) {
                    $diaCfg = $pol->horario_json['dias'][$kk];
                    break;
                }
            }
        }

        if ($diaCfg) {
            $e = substr((string)($diaCfg['entrada'] ?? $entrada), 0, 5);
            $s = substr((string)($diaCfg['salida']  ?? $salida),  0, 5);
            return [$e ?: $entrada, $s ?: $salida];
        }

        return [$entrada, $salida];
    }

    /** 50 => 0.50 ; 0.5 => 0.5 */
    private function toFrac(float $v): float
    {
        return $v > 1 ? ($v / 100.0) : $v;
    }

    /**
     * Descuento por retardo
     * Retorna [pctDeDia, diasEquiv, montoPesos]
     * - Usa SOLO minutos residuales (no los que ya formaron faltas)
     * - Soporta:
     *    PCT_MIN : % del día por minuto residual (valor = % por minuto)
     *    PCT_DIA : % del día por cada retardo residual (valor = % por retardo)
     *    PESOS_MIN: monto = minutos residuales * valorMinuto, pct = monto / sueldoDia
     */
    private function calcDescuentoRetardo(object $pol, int $minResidual, int $retardosResidual,
                                          float $valorMinuto, float $sueldoDia): array
    {
        $modo  = strtoupper((string)($pol->descuento_retardo_modo ?? 'PCT_MIN'));
        $valor = (float)($pol->descuento_retardo_valor ?? 0);
        $pct   = 0.0;
        $dias  = 0.0;
        $monto = 0.0;

        switch ($modo) {
            case 'PESOS_MIN':
                $monto = max(0, $minResidual) * max(0, $valorMinuto);
                $pct   = ($sueldoDia > 0) ? ($monto / $sueldoDia) : 0.0;
                $dias  = $pct;
                break;

            case 'PCT_DIA':
                $pct  = $this->toFrac($valor) * max(0, $retardosResidual);
                $dias = $pct;
                $monto = $pct * max(0, $sueldoDia);
                break;

            case 'PCT_MIN':
            default:
                $pct  = $this->toFrac($valor) * max(0, $minResidual);
                $dias = $pct;
                $monto = $pct * max(0, $sueldoDia);
                break;
        }

        return [round($pct, 6), round($dias, 6), round($monto, 2)];
    }

    /**
     * Descuento por faltas (derivadas de retardos)
     * Retorna [pctDeDia, diasEquiv, montoPesos]
     * - Modos:
     *    PCT_DIA : % del día por falta (p.ej. 100% = 1.0 día)
     *    FIJO_DIA: X días por falta
     */
    private function calcDescuentoFalta(object $pol, int $faltas, float $sueldoDia): array
    {
        $modo  = strtoupper((string)($pol->descuento_falta_modo ?? 'PCT_DIA'));
        $valor = (float)($pol->descuento_falta_valor ?? 100);
        $pct   = 0.0;
        $dias  = 0.0;

        switch ($modo) {
            case 'FIJO_DIA':
            case 'DIAS':
                $dias = max(0, $faltas) * max(0, $valor);
                $pct  = $dias;
                break;

            case 'PCT_DIA':
            default:
                $pct  = $this->toFrac($valor) * max(0, $faltas);
                $dias = $pct;
                break;
        }

        $monto = $pct * max(0, $sueldoDia);
        return [round($pct, 6), round($dias, 6), round($monto, 2)];
    }

    /**
     * Días de solapamiento entre [ini, fin] del evento y [fi, ff] del periodo.
     * Si fin es NULL, se considera evento de un solo día (= inicio).
     */
    private function overlapDays(?string $ini, ?string $fin, string $fi, string $ff): float
    {
        if (!$ini) return 0.0;
        $iniDate = new \DateTime(substr($ini,0,10));
        $finDate = $fin ? new \DateTime(substr($fin,0,10)) : new \DateTime(substr($ini,0,10));
        $fiDate  = new \DateTime($fi);
        $ffDate  = new \DateTime($ff);

        if ($finDate < $iniDate) $finDate = clone $iniDate;

        $start = $iniDate > $fiDate ? $iniDate : $fiDate;
        $end   = $finDate < $ffDate ? $finDate : $ffDate;

        if ($end < $start) return 0.0;

        // +1 porque ambos extremos son inclusivos
        $days = (int)$start->diff($end)->format('%a') + 1;
        return (float)$days;
    }
}
