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
    private string $CONN          = 'portal_main';
    private string $TABLA_EVENTOS = 'calendario_eventos'; // o 'eventos_option'

    public function preview(Request $request, ResolverPolitica $resolver)
    {
        // 1) Validación suave
        $v = Validator::make($request->all(), [
            'id_portal'            => ['required', 'integer'],
            'cliente_ids'          => ['required', 'array', 'min:1'],
            'cliente_ids.*'        => ['integer'],
            'periodo'              => ['required', 'array'],
            'periodo.id'           => ['nullable', 'integer'],
            'periodo.fecha_inicio' => ['required_without:periodo.id', 'date'],
            'periodo.fecha_fin'    => ['required_without:periodo.id', 'date'],
            'empleados'            => ['required', 'array', 'min:1'],
            'empleados.*'          => ['integer'],
        ]);

        if ($v->fails()) {
            Log::warning('[Incidencias/preview] validation failed', $v->errors()->toArray());
            return response()->json(['ok' => false, 'errors' => $v->errors(), 'items' => []], 200);
        }

        // 2) Inputs
        $portalId   = (int) $request->integer('id_portal');
        $clienteIds = array_map('intval', (array) $request->input('cliente_ids', []));
        $empIds     = array_map('intval', (array) $request->input('empleados', []));
        $per        = (array) $request->input('periodo', []);
        $fi         = substr((string) ($per['fecha_inicio'] ?? ''), 0, 10);
        $ff         = substr((string) ($per['fecha_fin'] ?? ''), 0, 10);

        Log::info('[Incidencias/preview] received', compact('portalId', 'clienteIds', 'empIds', 'fi', 'ff'));

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
            ->leftJoin('cliente as c', 'c.id', '=', 'e.id_cliente')
            ->leftJoin('laborales_empleado as le', 'le.id_empleado', '=', 'e.id')
            ->where('e.id_portal', $portalId)
            ->when(! empty($clienteIds), fn($q) => $q->whereIn('e.id_cliente', $clienteIds))
            ->whereIn('e.id', $empIds)
            ->get()
            ->keyBy('id');

        $empConsiderados = $empIds;

        /* --------------------------------------------------------------------
     * 4) Calendario (JOIN eventos_option.name como tipo_txt)
     *     Tolerante a columnas opcionales y a fin = NULL
     * ------------------------------------------------------------------ */
        $hasDiasEv     = Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'dias_evento');
        $hasIdPortalE  = Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'id_portal');
        $hasIdClienteE = Schema::connection($this->CONN)->hasColumn($this->TABLA_EVENTOS, 'id_cliente');

        $selects = [
            'ev.id_empleado',
            'ev.inicio',
            'ev.fin',
            'ev.id_tipo',
            'ev.eliminado',
            DB::raw('COALESCE(t.name, "") as tipo_txt'),
        ];
        if ($hasDiasEv) {
            $selects[] = 'ev.dias_evento';
        }

        $evQuery = DB::connection($this->CONN)
            ->table($this->TABLA_EVENTOS . ' as ev')
            ->leftJoin('eventos_option as t', 't.id', '=', 'ev.id_tipo')
            ->select($selects)
            ->where('ev.eliminado', 0)
            ->when(! empty($empConsiderados), fn($q) => $q->whereIn('ev.id_empleado', $empConsiderados))
            ->whereDate('ev.inicio', '<=', $ff)
            ->where(function ($q) use ($fi) {
                $q->whereDate('ev.fin', '>=', $fi)
                    ->orWhereNull('ev.fin');
            });

        if ($hasIdPortalE) {
            $evQuery->where('ev.id_portal', $portalId);
        }
        if ($hasIdClienteE && ! empty($clienteIds)) {
            $evQuery->whereIn('ev.id_cliente', $clienteIds);
        }

        $evRows = $evQuery->get()->groupBy('id_empleado');

        // Clasificación por tipo (ID o texto en name)
        // Clasificación por tipo (ID o texto en name)
        $IDT_VACACIONES  = [];
        $IDT_AUSENCIAS   = [];
        $IDT_FESTIVOS    = [];
        $IDT_HORAS_EXTRA = []; // por si luego quieres mapear por ID

        $evAgg = [];
        foreach ($evRows as $empIdKey => $lista) {
            $vac    = 0.0;
            $aus    = 0.0;
            $fes    = 0.0;
            $inc    = 0.0; // incapacidad
            $hexMin = 0.0; // 🔹 minutos de horas extra desde calendario

            foreach ($lista as $row) {
                $dias = (isset($row->dias_evento) && $row->dias_evento !== null)
                    ? (float) $row->dias_evento
                    : $this->overlapDays($row->inicio, $row->fin, $fi, $ff);

                $tipoTxt = strtolower((string) ($row->tipo_txt ?? ''));

                // Normalizar acentos
                $tipoNorm = strtr($tipoTxt, [
                    'á' => 'a',
                    'é' => 'e',
                    'í' => 'i',
                    'ó' => 'o',
                    'ú' => 'u',
                ]);

                // Vacaciones
                $isVac = in_array((int) $row->id_tipo, $IDT_VACACIONES, true)
                || str_contains($tipoNorm, 'vaca');

                // Festivos (incluye tu "Dia Fetivo")
                $isFes = in_array((int) $row->id_tipo, $IDT_FESTIVOS, true)
                || str_contains($tipoNorm, 'fest')
                || str_contains($tipoNorm, 'fetiv')
                || str_contains($tipoNorm, 'feriado')
                || str_contains($tipoNorm, 'no laborable')
                || str_contains($tipoNorm, 'descanso obligatorio');

                // Incapacidad (SOLO incapacidad)
                $isIncap = str_contains($tipoNorm, 'incap');

                // Ausencias reales: falta / ausencia / permiso
                $isAus = in_array((int) $row->id_tipo, $IDT_AUSENCIAS, true)
                || str_contains($tipoNorm, 'ausen')
                || str_contains($tipoNorm, 'falta')
                || str_contains($tipoNorm, 'permiso');

                // Horas extra registradas en calendario
                $isHex = in_array((int) $row->id_tipo, $IDT_HORAS_EXTRA, true)
                || str_contains($tipoNorm, 'hora extra')
                || str_contains($tipoNorm, 'horas extra');

                if ($isVac) {
                    $vac += $dias;
                } elseif ($isFes) {
                    $fes += $dias;
                } elseif ($isIncap) {
                    $inc += $dias;
                } elseif ($isAus) {
                    $aus += $dias;
                } elseif ($isHex) {
                    // 🔹 Leer minutos desde la descripción
                    $desc = (string) ($row->descripcion ?? '');
                    $mins = 0.0;

                    // a) "120 minutos", "30 min", etc.
                    if (preg_match('/(\d+(?:[.,]\d+)?)\s*(min|mins|minuto|minutos)/i', $desc, $m)) {
                        $val  = str_replace(',', '.', $m[1]);
                        $mins = (float) $val;
                    }
                    // b) "2 horas", "1.5 horas", etc.
                    elseif (preg_match('/(\d+(?:[.,]\d+)?)\s*hora/i', $desc, $m)) {
                        $val  = str_replace(',', '.', $m[1]);
                        $mins = (float) $val * 60.0;
                    }
                    // c) Fallback: si usas dias_evento como "horas"
                    elseif ($dias > 0) {
                        $mins = $dias * 60.0;
                    }

                    $hexMin += $mins;
                }
            }

            $evAgg[$empIdKey] = (object) [
                'vacaciones'           => $vac,
                'dias_ausencia_cal'    => $aus,
                'dias_festivos'        => $fes,
                'dias_incapacidad'     => $inc,
                'horas_extras_min_cal' => $hexMin, // 🔹 aquí guardamos los minutos de HX calendario
            ];
        }

        // === 4.x) Resolver IDs de opciones (Retardo y Falta) ===
        $opts = DB::connection($this->CONN)
            ->table('eventos_option')
            ->select('id', 'name', 'id_crol')
            ->get();

        $ID_TIPO_RETARDO = (int) ($opts->firstWhere('name', 'Retardo')->id ?? 0);
        $ID_TIPO_FALTA   = (int) ($opts->firstWhere('name', 'Falta')->id ?? 0);
        $ID_CROL_FALTA   = (int) ($opts->firstWhere('name', 'Falta')->id_crol ?? 0);

        // Helper expandir días
        $expandDays = function (string $desde, ?string $hasta, string $clipFi, string $clipFf) {
            $desde = substr($desde, 0, 10);
            $hasta = substr((string) ($hasta ?: $desde), 0, 10);
            $start = max($desde, $clipFi);
            $end   = min($hasta, $clipFf);
            if ($end < $start) {
                return [];
            }

            $out   = [];
            $cur   = new \DateTime($start . ' 00:00:00');
            $endDt = new \DateTime($end . ' 00:00:00');
            while ($cur <= $endDt) {$out[] = $cur->format('Y-m-d');
                $cur->modify('+1 day');}
            return $out;
        };

        /* --------------------------------------------------------------------
     * 4.1) Eventos EXPANDIDOS + conversión del N-ésimo retardo a Falta
     * ------------------------------------------------------------------ */
        $evRowsFull = DB::connection($this->CONN)
            ->table($this->TABLA_EVENTOS . ' as ev')
            ->leftJoin('eventos_option as t', 't.id', '=', 'ev.id_tipo')
            ->select([
                'ev.id            as id_evento',
                'ev.id_usuario',
                'ev.id_empleado',
                'ev.inicio',
                'ev.fin',
                'ev.dias_evento',
                'ev.descripcion',
                'ev.archivo',
                'ev.created_at',
                'ev.updated_at',
                'ev.id_tipo',
                'ev.eliminado',
                'ev.tipo_incapacidad_sat',
                DB::raw('COALESCE(t.name, "") as tipo_txt'),
                DB::raw('COALESCE(t.id_crol, 0) as id_crol'),
                DB::raw('CAST(ev.tipo_incapacidad_sat AS UNSIGNED) as tipo_incapacidad_id'),
            ])
            ->where('ev.eliminado', 0)
            ->when(! empty($empIds), fn($q) => $q->whereIn('ev.id_empleado', $empIds))
            ->whereDate('ev.inicio', '<=', $ff)
            ->where(function ($q) use ($fi) {
                $q->whereDate('ev.fin', '>=', $fi)->orWhereNull('ev.fin');
            })
            ->get()
            ->groupBy('id_empleado');

        // Auxiliares para totales fuera de 'incidencias'
        $__faltas_por_ret_por_evento = [];
        $__retardos_resid_por_evento = [];
        $__mins_retardo_por_evento   = []; // << NUEVO: minutos de retardos NO convertidos a falta

        $eventosPorEmpleado = [];

        foreach ($empIds as $empId) { // PRIMER foreach
            $lista = ($evRowsFull->get($empId) ?? collect())->values();

            // Separa retardos y otros
            // Separa retardos y otros (robusto: por id_tipo y por texto)
            $retardos = [];
            $otros    = [];

            foreach ($lista as $row) {
                $tipoTxtNorm = strtolower(trim((string) $row->tipo_txt));
                $esRetardo   = false;

                // 1) Si tenemos el ID exacto de tipo "Retardo"
                if ($ID_TIPO_RETARDO > 0 && (int) $row->id_tipo === $ID_TIPO_RETARDO) {
                    $esRetardo = true;
                }
                // 2) O si el nombre contiene "retard" (Retardo, Retardos, Retardo leve, etc.)
                elseif ($tipoTxtNorm === 'retardo' || str_contains($tipoTxtNorm, 'retard')) {
                    $esRetardo = true;
                }

                if ($esRetardo) {
                    // 🔹 NO se agregan como incidencia directa
                    //    Solo se usan después para:
                    //    - convertir N-ésimo a Falta
                    //    - sumar minutos de retardo
                    $retardos[] = $row;
                } else {
                    // Estos sí se convertirán a incidencias (vacaciones, falta, incapacidad, etc.)
                    $otros[] = $row;
                }
            }

            // Ordena retardos por fecha (inicio) y luego por id_evento
            usort($retardos, function ($a, $b) {
                $ai = substr((string) $a->inicio, 0, 10);
                $bi = substr((string) $b->inicio, 0, 10);
                return $ai === $bi ? ($a->id_evento <=> $b->id_evento) : ($ai <=> $bi);
            });

            // N desde la política efectiva (fallback 3)
            $clienteId = (int) ($infoByEmp->get($empId)?->id_cliente ?? ($clienteIds[0] ?? 0));
            $policyArr = $resolver->getEffectivePolicy($portalId, $clienteId, $empId, $fi) ?? [];
            $N         = max((int) ($policyArr['retardos_por_falta'] ?? 3), 1);

            $incidencias = [];

            // 1) Expandir "otros" (no-retardos)
// 1) Registrar "otros" (no-retardos) SIN expandir por día
            foreach ($otros as $ev) {
                // Fechas del evento (rango original)
                $ini = substr((string) $ev->inicio, 0, 10);
                $fin = substr((string) ($ev->fin ?? $ev->inicio), 0, 10);

                // Días del evento dentro del periodo (por si lo necesitas)
                $diasEvento = $ev->dias_evento;
                if ($diasEvento === null) {
                    $diasEvento = $this->overlapDays($ini, $fin, $fi, $ff);
                }

                $incidencias[] = [
                    'id_evento'            => (int) $ev->id_evento,
                    'id_usuario'           => $ev->id_usuario,
                    'id_empleado'          => $empId,
                    'inicio'               => $ini,
                    'fin'                  => $fin,
                    'dias_evento'          => (float) $diasEvento,
                    'descripcion'          => $ev->descripcion,
                    'archivo'              => $ev->archivo,
                    'created_at'           => $ev->created_at,
                    'updated_at'           => $ev->updated_at,
                    'id_tipo'              => (int) $ev->id_tipo,
                    'eliminado'            => (int) $ev->eliminado,
                    'tipo_txt'             => (string) $ev->tipo_txt,
                    'id_crol'              => (int) $ev->id_crol,

                    // Para compat con el front: dejamos "fecha" como el inicio
                    'fecha'                => $ini,

                    // Aquí va la clave/ID de incapacidad (ya la estás casteando arriba)
                    'tipo_incapacidad_sat' => $ev->tipo_incapacidad_sat,
                    'tipo_incapacidad_id'  => $ev->tipo_incapacidad_id ?? null,
                ];
            }

            // 2) Expandir retardos y convertir solo el N-ésimo a Falta
            $count = 0;
            if ($N > 0) {
                foreach ($retardos as $r) {
                    $dias = $expandDays((string) $r->inicio, (string) ($r->fin ?? $r->inicio), $fi, $ff);
                    foreach ($dias as $ymd) {
                        $count++;
                        if ($count % $N === 0) {
                            // N-ésimo retardo ⇒ Falta
                            $incidencias[] = [
                                'id_evento'           => (int) $r->id_evento,
                                'id_usuario'          => $r->id_usuario,
                                'id_empleado'         => $empId,
                                'inicio'              => $ymd,
                                'fin'                 => $ymd,
                                'dias_evento'         => 1,
                                'descripcion'         => $r->descripcion,
                                'archivo'             => $r->archivo,
                                'created_at'          => $r->created_at,
                                'updated_at'          => $r->updated_at,
                                'id_tipo'             => $ID_TIPO_FALTA,
                                'eliminado'           => 0,
                                'tipo_txt'            => 'Falta',
                                'id_crol'             => $ID_CROL_FALTA,
                                'fecha'               => $ymd,
                                'origen'              => 'retardo→falta',
                                'retardos_acumulados' => $N,
                                'retardo_source_id'   => (int) $r->id_evento,
                            ];
                        } else {
                            // Retardo que NO completa N ⇒ no se agrega a incidencias,
                            // pero sí sumamos sus minutos a un acumulador.
                            $desc = (string) ($r->descripcion ?? '');
                            $mins = 0;
                            if (preg_match('/(\d+)\s*min/i', $desc, $mm)) {
                                $mins = (int) $mm[1];
                            }
                            if ($mins > 0) {
                                if (! isset($__mins_retardo_por_evento[$empId])) {
                                    $__mins_retardo_por_evento[$empId] = 0;
                                }
                                $__mins_retardo_por_evento[$empId] += $mins;
                            }
                        }
                    }
                }
            }

                                               // Guardar conteos para reflejarlos fuera de 'incidencias'
            $ret_ev_total      = (int) $count; // total de retardos EVENTO en el periodo
            $faltas_por_ret_ev = ($N > 0) ? intdiv($ret_ev_total, $N) : 0;
            $ret_ev_residuales = ($N > 0) ? ($ret_ev_total % $N) : 0;

            $__faltas_por_ret_por_evento[$empId] = $faltas_por_ret_ev;
            $__retardos_resid_por_evento[$empId] = $ret_ev_residuales;

            // Ordenar incidencias por fecha asc
            usort($incidencias, fn($a, $b) => strcmp($a['inicio'], $b['inicio']));
            $eventosPorEmpleado[$empId] = $incidencias;
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
            ->when(! empty($clienteIds), fn($q) => $q->whereIn('ch.id_cliente', $clienteIds))
            ->when(! empty($empConsiderados), fn($q) => $q->whereIn('ch.id_empleado', $empConsiderados))
            ->whereBetween('ch.fecha', [$fi, $ff])
            ->groupBy('ch.id_empleado', 'ch.fecha')
            ->get()
            ->groupBy('id_empleado');

        /* --------------------------------------------------------------------
     * 6) Agregado por empleado (retardos + faltas + extras + descuentos)
     * ------------------------------------------------------------------ */

        $items = [];

        foreach ($empIds as $empId) { // SEGUNDO foreach
            $info      = $infoByEmp->get($empId);
            $clienteId = $info?->id_cliente ?? ($clienteIds[0] ?? null);
            $sueldoDia = (float) ($info?->sueldo_diario ?? 0);
            $hexMinCal = 0.0; // minutos de HX calendario (se llenará con $evAgg)

            // Política efectiva (usa primer día del periodo)
            $policyArr = $resolver->getEffectivePolicy($portalId, $clienteId, $empId, $fi) ?? [];

            $pol = (object) [
                'hora_entrada'            => substr((string) ($policyArr['hora_entrada'] ?? '09:00'), 0, 5),
                'hora_salida'             => substr((string) ($policyArr['hora_salida'] ?? '18:00'), 0, 5),
                'tolerancia_min'          => (int) ($policyArr['tolerancia_minutos'] ?? 5),
                'retardos_por_falta'      => (int) ($policyArr['retardos_por_falta'] ?? 3),
                'extra_umbral_min'        => (int) ($policyArr['minutos_gracia_extra'] ?? 30),
                'tope_horas_extra'        => (int) ($policyArr['tope_horas_extra'] ?? 0),
                'calcular_extras'         => (int) ($policyArr['calcular_extras'] ?? 1) ? true : false,
                'criterio_extra'          => (string) ($policyArr['criterio_extra'] ?? ''),
                'trabaja_sabado'          => (int) ($policyArr['trabaja_sabado'] ?? 0),
                'trabaja_domingo'         => (int) ($policyArr['trabaja_domingo'] ?? 0),
                'horario_json'            => $policyArr['horario_json'] ?? null,

                'contar_salida_temprano'  => (int) ($policyArr['contar_salida_temprano'] ?? 0),
                'descuento_retardo_modo'  => (string) ($policyArr['descuento_retardo_modo'] ?? 'PCT_MIN'),
                'descuento_retardo_valor' => (float) ($policyArr['descuento_retardo_valor'] ?? 0),
                'descuento_falta_modo'    => (string) ($policyArr['descuento_falta_modo'] ?? 'PCT_DIA'),
                'descuento_falta_valor'   => (float) ($policyArr['descuento_falta_valor'] ?? 100),
            ];

            // Duración de jornada / valor minuto
            [$eBase, $sBase] = [$pol->hora_entrada ?: '09:00', $pol->hora_salida ?: '18:00'];
            $minsJornada     = max(
                1,
                (int) round(
                    ($this->mkDt('2000-01-01', $sBase)->getTimestamp() - $this->mkDt('2000-01-01', $eBase)->getTimestamp()) / 60
                )
            );
            if ($minsJornada <= 0) {
                $minsJornada = 480;
            }

            $valorMinuto    = $sueldoDia > 0 ? ($sueldoDia / $minsJornada) : 0.0;
            $retardosDias   = 0;
            $horasExtraMin  = 0;
            $minsLateTotal  = 0; // minutos por llegada tarde
            $minsEarlyTotal = 0; // minutos por salida temprano (si aplica)

            // ================== CHECADAS ==================
            $dias = $checadas->get($empId) ?? collect();
            foreach ($dias as $d) {
                $ymd = (string) $d->fecha;

                $dow = (int) date('N', strtotime($ymd)); // 1..7
                if (($dow === 6 && ! $pol->trabaja_sabado) || ($dow === 7 && ! $pol->trabaja_domingo)) {
                    continue;
                }

                [$hEntrada, $hSalida] = $this->expectedTimesForDate($pol, $ymd);
                $entradaEsperada      = $this->mkDt($ymd, $hEntrada);
                $salidaEsperada       = $this->mkDt($ymd, $hSalida);

                $firstIn = $d->first_in ? new \DateTime($d->first_in) : null;
                $lastOut = $d->last_out ? new \DateTime($d->last_out) : null;

                // Retardos por checadas
                if ($firstIn) {
                    $limiteTol = (clone $entradaEsperada)->modify("+{$pol->tolerancia_min} minutes");
                    if ($firstIn > $limiteTol) {
                        $retardosDias += 1;
                        $minsLate = (int) floor(($firstIn->getTimestamp() - $limiteTol->getTimestamp()) / 60);
                        $minsLateTotal += max(0, $minsLate);
                    }
                }

                // Salida temprano (si política lo pide)
                if ($pol->contar_salida_temprano && $lastOut && $lastOut < $salidaEsperada) {
                    $minsEarly = (int) floor(($salidaEsperada->getTimestamp() - $lastOut->getTimestamp()) / 60);
                    $minsEarlyTotal += max(0, $minsEarly);
                }

                // Horas extra por checador
                if ($pol->calcular_extras && $lastOut && $lastOut > $salidaEsperada) {
                    $mins = (int) floor(($lastOut->getTimestamp() - $salidaEsperada->getTimestamp()) / 60);
                    if ($mins > $pol->extra_umbral_min) {
                        $horasExtraMin += $mins;
                    }
                }
            }

            // ---------- Retardos → faltas y residuales ----------
            $faltasPorRet       = 0;
            $retardosResiduales = $retardosDias;
            if ($pol->retardos_por_falta > 0) {
                $faltasPorRet       = intdiv($retardosDias, $pol->retardos_por_falta);
                $retardosResiduales = $retardosDias % $pol->retardos_por_falta;
            }

            // Minutos provenientes de EVENTOS (retardos no convertidos a falta)
            $minsEventosResid = (int) ($__mins_retardo_por_evento[$empId] ?? 0);

            // Residuales de llegada tarde (prorrateo por las piezas que NO completaron falta)
            $minLateResidual = $retardosDias > 0
                ? (int) round($minsLateTotal * ($retardosResiduales / $retardosDias))
                : 0;

            // Residual final mostrado = llegadas tarde residuales + salidas temprano + eventos residuales
            $retardoMinTotResidual = $minLateResidual + $minsEarlyTotal + $minsEventosResid;

            // Total informativo (para tooltip)
            $retardoMinTot = $minsLateTotal + $minsEarlyTotal + $minsEventosResid;

            // ---------- Descuentos (retardos + faltas) ----------
            [$pctRet, $diasRet, $montoRet] = $this->calcDescuentoRetardo(
                $pol,
                $retardoMinTotResidual,
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

            // ---------- Calendario del periodo (vacaciones, ausencias, festivos, HX) ----------
            $c = $evAgg[$empId] ?? null;
            if ($c) {
                $vacaciones  = (float) ($c->vacaciones ?? 0);
                $ausenciaCal = (float) ($c->dias_ausencia_cal ?? 0);
                $festivos    = (float) ($c->dias_festivos ?? 0);
                $hexMinCal   = (float) ($c->horas_extras_min_cal ?? 0);
                $incapacidad = (float) ($c->dias_incapacidad ?? 0); // 👈 NUEVO
            } else {
                $vacaciones  = 0.0;
                $ausenciaCal = 0.0;
                $festivos    = 0.0;
                $hexMinCal   = 0.0;
                $incapacidad = 0.0; // 👈 NUEVO
            }

            // 🔹 SUMAR horas extra que vienen del calendario (en minutos)
            if ($hexMinCal > 0) {
                $horasExtraMin += (int) $hexMinCal;
            }

            // Tope de horas extra (asumiendo que el tope está en MINUTOS)
            if ($pol->tope_horas_extra > 0) {
                $horasExtraMin = min($horasExtraMin, (int) $pol->tope_horas_extra);
            }

            // Identidad
            $codigo                = (string) ($info?->id_empleado ?? $empId);
            $nombre                = trim((($info?->nombre ?? '') . ' ' . ($info?->paterno ?? '') . ' ' . ($info?->materno ?? '')));
            $sucursal              = $info?->nombre_cliente ?? '';
            $eventosDeEsteEmpleado = array_values($eventosPorEmpleado[$empId] ?? []); // plano por día

            // === Selección de conteos de salida ===
            // Si hubo retardos EVENTO, usamos esos conteos; si no, usamos los de checadas.
            $hasRetEventos        = array_key_exists($empId, $__faltas_por_ret_por_evento);
            $faltasPorRet_Salida  = $hasRetEventos ? $__faltas_por_ret_por_evento[$empId] : $faltasPorRet;
            $retardosResid_Salida = $hasRetEventos ? $__retardos_resid_por_evento[$empId] : $retardosResiduales;

            // Total ausencias = calendario + faltas por retardo (sea por eventos o checadas)
            $diasAusencia = $ausenciaCal + $faltasPorRet_Salida;

            // Horas extra en horas (2 decimales) TOTAL (checador + calendario)
            $horasExtras = round($horasExtraMin / 60, 2);

            $items[] = [
                'id_empleado'              => $empId,
                'codigo'                   => $codigo,
                'nombre'                   => $nombre,
                'sucursal'                 => $sucursal,

                // Puntualidad (ajustadas si hubo eventos)
                'retardos'                 => $retardosResid_Salida,
                'retardo_minutos_total'    => $retardoMinTot,
                'retardo_minutos_residual' => $retardoMinTotResidual,
                'faltas_por_retardo'       => $faltasPorRet_Salida,

                // Descuentos
                'descuento_retardo_pct'    => $pctRet,
                'descuento_falta_pct'      => $pctFal,
                'descuento_pct_total'      => $descuentoPctTotal,
                'descuento_dias_total'     => $descuentoDiasTotal,
                'descuento_retardo_monto'  => $montoRet,
                'descuento_falta_monto'    => $montoFal,
                'descuento_monto_total'    => $descuentoMontoTot,
                'valor_minuto'             => round($valorMinuto, 6),
                'sueldo_diario'            => round($sueldoDia, 2),
                'dias_incapacidad'         => $incapacidad, // 👈 NUEVO

                // Calendario
                'dias_festivos'            => $festivos,
                'dias_ausencia'            => $diasAusencia,
                'dias_ausencia_cal'        => $ausenciaCal,
                'dias_ausencia_extra'      => $faltasPorRet, // faltas generadas por retardo (solo para referencia)
                'vacaciones'               => $vacaciones,

                // Extras
                'horas_extras'             => $horasExtras,
                'incidencias'              => $eventosDeEsteEmpleado, // [{fecha, tipo_txt, id_tipo, id_crol, id_evento}, ...]
            ];
        }

        return response()->json([
            'ok'    => true,
            'items' => $items,
        ], 200);
    }

    /** Construye DateTime local a partir de Y-m-d + HH:MM */
    private function mkDt(string $ymd, string $hhmm): \DateTime
    {
        $s = trim($ymd) . ' ' . substr(trim($hhmm), 0, 5) . ':00';
        return new \DateTime($s);
    }

    /**
     * Horario esperado del día (usa horario_json si existe, si no toma la base).
     * Formato: ['dias' => ['lun' => ['entrada'=>'09:00','salida'=>'18:00'], ...]]
     */
    private function expectedTimesForDate(object $pol, string $ymd): array
    {
        $entrada = $pol->hora_entrada ?: '09:00';
        $salida  = $pol->hora_salida ?: '18:00';

        if (! is_array($pol->horario_json)) {
            return [$entrada, $salida];
        }

        $w   = (int) date('N', strtotime($ymd)); // 1..7
        $map = [
            1 => ['lun', 'monday', 'mon', '1'],
            2 => ['mar', 'tuesday', 'tue', '2'],
            3 => ['mie', 'miércoles', 'miercoles', 'wednesday', 'wed', '3'],
            4 => ['jue', 'thursday', 'thu', '4'],
            5 => ['vie', 'friday', 'fri', '5'],
            6 => ['sab', 'sábado', 'sabado', 'saturday', 'sat', '6'],
            7 => ['dom', 'domingo', 'sunday', 'sun', '7'],
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
            $e = substr((string) ($diaCfg['entrada'] ?? $entrada), 0, 5);
            $s = substr((string) ($diaCfg['salida'] ?? $salida), 0, 5);
            return [$e ?: $entrada, $s ?: $salida];
        }

        return [$entrada, $salida];
    }

    /** 50 => 0.50 ; 0.5 => 0.5 */
    /** Convierte porcentaje a fracción: 2 => 0.02, 0.5 => 0.005 */
    private function pctToFrac(float $v): float
    {
        return $v / 100.0;
    }

    /** ⚠️ Shim de compatibilidad para código que aún llama a toFrac() */
    private function toFrac(float $v): float
    {
        return $this->pctToFrac($v);
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
    private function calcDescuentoRetardo(
        object $pol, int $minResidual, int $retardosResidual,
        float $valorMinuto, float $sueldoDia
    ): array {
        $modo  = strtoupper((string) ($pol->descuento_retardo_modo ?? 'PCT_MIN'));
        $valor = (float) ($pol->descuento_retardo_valor ?? 0);
        $pct   = 0.0;
        $dias  = 0.0;
        $monto = 0.0;

        switch ($modo) {
            case 'PESOS_MIN':
                // $$ por minuto: monto directo y % = monto / sueldo
                $monto = max(0, $minResidual) * max(0, $valorMinuto);
                $pct   = ($sueldoDia > 0) ? ($monto / $sueldoDia) : 0.0;
                $dias  = $pct;
                break;

            case 'PCT_DIA':{                                     // % del día por retardo residual
                    $porUnidad = $this->pctToFrac($valor);               // valor viene en %
                    $pct       = $porUnidad * max(0, $retardosResidual); // fracción del día
                    $dias      = $pct;
                    $monto     = $pct * max(0, $sueldoDia);
                    break;
                }

            case 'PCT_MIN':
            default: {                                      // % del día por minuto residual
                    $porUnidad = $this->pctToFrac($valor);          // valor viene en %
                    $pct       = $porUnidad * max(0, $minResidual); // fracción del día
                    $dias      = $pct;
                    $monto     = $pct * max(0, $sueldoDia);
                    break;
                }
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
        $modo  = strtoupper((string) ($pol->descuento_falta_modo ?? 'PCT_DIA'));
        $valor = (float) ($pol->descuento_falta_valor ?? 100);
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
                $pct  = $this->pctToFrac($valor) * max(0, $faltas);
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
        if (! $ini) {
            return 0.0;
        }

        $iniDate = new \DateTime(substr($ini, 0, 10));
        $finDate = $fin ? new \DateTime(substr($fin, 0, 10)) : new \DateTime(substr($ini, 0, 10));
        $fiDate  = new \DateTime($fi);
        $ffDate  = new \DateTime($ff);

        if ($finDate < $iniDate) {
            $finDate = clone $iniDate;
        }

        $start = $iniDate > $fiDate ? $iniDate : $fiDate;
        $end   = $finDate < $ffDate ? $finDate : $ffDate;

        if ($end < $start) {
            return 0.0;
        }

        // +1 porque ambos extremos son inclusivos
        $days = (int) $start->diff($end)->format('%a') + 1;
        return (float) $days;
    }
}
