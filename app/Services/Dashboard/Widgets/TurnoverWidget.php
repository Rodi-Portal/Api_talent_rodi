<?php
namespace App\Services\Dashboard\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TurnoverWidget
{
    private string $conn;

    public function __construct(string $conn)
    {
        $this->conn = $conn;
    }

    private function db()
    {
        return DB::connection($this->conn);
    }

    /**
     * Subquery base de ciclos former
     */
    private function formerCycleSub()
    {
        return $this->db()->table('comentarios_former_empleado as cf')
            ->selectRaw(
                'cf.id_empleado,
                 MIN(cf.creacion) as exit_date,
                 MAX(cf.fecha_salida_reingreso) as rehire_date'
            )
            ->groupBy('cf.id_empleado');
    }

    /**
     * Headcount a una fecha
     */
    private function headcountAsOf(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $asOf
    ): int {
        $sub      = $this->formerCycleSub();
        $asOfDate = $asOf->toDateString();

        return $this->db()->table('empleados as e')
            ->leftJoin(DB::raw("({$sub->toSql()}) as x"), 'x.id_empleado', '=', 'e.id')
            ->mergeBindings($sub)
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->whereRaw('COALESCE(e.fecha_ingreso, DATE(e.creacion)) <= ?', [$asOfDate])
            ->where(function ($w) use ($asOfDate) {
                $w->whereNull('x.exit_date')
                    ->orWhere('x.exit_date', '>', $asOfDate)
                    ->orWhere(function ($w2) use ($asOfDate) {
                        $w2->whereNotNull('x.exit_date')
                            ->where('x.exit_date', '<=', $asOfDate)
                            ->whereNotNull('x.rehire_date')
                            ->where('x.rehire_date', '<=', $asOfDate);
                    });
            })
            ->count();
    }

    /**
     * KPIs de rotación del periodo
     */
    public function kpis(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {
        $sub = $this->formerCycleSub();

        $terminations = $this->db()->table(DB::raw("({$sub->toSql()}) as x"))
            ->mergeBindings($sub)
            ->join('empleados as e', 'e.id', '=', 'x.id_empleado')
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->whereBetween('x.exit_date', [
                $rangeStart->toDateString(),
                $rangeEnd->toDateString(),
            ])
            ->count();

        $hcStart = $this->headcountAsOf(
            $portalId,
            $allowedClients,
            $clientId,
            $rangeStart
        );

        $hcEnd = $this->headcountAsOf(
            $portalId,
            $allowedClients,
            $clientId,
            $rangeEnd
        );

        $avgHc = ($hcStart + $hcEnd) / 2;

        return [
            'terminations_month' => $terminations,
            'turnover_month_pct' => $avgHc > 0
                ? round(($terminations / $avgHc) * 100, 2)
                : 0.0,
        ];
    }

    /**
     * Gráfica de rotación últimos 6 meses
     */
    public function chart6Months(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $periodBase
    ): array {
        $sub = $this->formerCycleSub();

        $chart = [
            'months'       => [],
            'hires'        => [],
            'terminations' => [],
            'turnover_pct' => [],
        ];

        for ($i = 5; $i >= 0; $i--) {
            $mStart = $periodBase->copy()->subMonths($i)->startOfMonth();
            $mEnd   = $periodBase->copy()->subMonths($i)->endOfMonth();

            $label = $mStart->format('Y-m');

            $hires = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                    [$mStart->toDateString(), $mEnd->toDateString()]
                )
                ->count();

            $terms = $this->db()->table(DB::raw("({$sub->toSql()}) as x"))
                ->mergeBindings($sub)
                ->join('empleados as e', 'e.id', '=', 'x.id_empleado')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    'x.exit_date',
                    [$mStart->toDateString(), $mEnd->toDateString()]
                )
                ->count();

            $hcS = $this->headcountAsOf(
                $portalId,
                $allowedClients,
                $clientId,
                $mStart
            );

            $hcE = $this->headcountAsOf(
                $portalId,
                $allowedClients,
                $clientId,
                $mEnd
            );

            $avg  = ($hcS + $hcE) / 2;
            $turn = $avg > 0 ? round(($terms / $avg) * 100, 2) : 0.0;

            $chart['months'][]       = $label;
            $chart['hires'][]        = $hires;
            $chart['terminations'][] = $terms;
            $chart['turnover_pct'][] = $turn;
        }

        return $chart;
    }

    /**
     * Gráfica de rotación por rango (mensual)
     */
    public function chartByRange(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): array {

        $sub = $this->formerCycleSub();

        // === generar meses del rango ===
        $months = [];
        $cursor = $rangeStart->copy()->startOfMonth();

        while ($cursor <= $rangeEnd) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        $chart = [
            'months'       => $months,
            'hires'        => array_fill(0, count($months), 0),
            'terminations' => array_fill(0, count($months), 0),
            'turnover_pct' => array_fill(0, count($months), 0),
        ];

        foreach ($months as $i => $label) {
            [$y, $m] = explode('-', $label);
            $mStart  = Carbon::createFromDate($y, $m, 1)->startOfMonth();
            $mEnd    = $mStart->copy()->endOfMonth();

            // ALTAS
            $hires = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                    [$mStart->toDateString(), $mEnd->toDateString()]
                )
                ->count();

            // BAJAS
            $terms = $this->db()->table(DB::raw("({$sub->toSql()}) as x"))
                ->mergeBindings($sub)
                ->join('empleados as e', 'e.id', '=', 'x.id_empleado')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    'x.exit_date',
                    [$mStart->toDateString(), $mEnd->toDateString()]
                )
                ->count();

            // HEADCOUNT PROMEDIO
            $hcS = $this->headcountAsOf($portalId, $allowedClients, $clientId, $mStart);
            $hcE = $this->headcountAsOf($portalId, $allowedClients, $clientId, $mEnd);
            $avg = ($hcS + $hcE) / 2;

            $chart['hires'][$i]        = $hires;
            $chart['terminations'][$i] = $terms;
            $chart['turnover_pct'][$i] = $avg > 0 ? round(($terms / $avg) * 100, 2) : 0.0;
        }

        return $chart;
    }
/**
 * Gráfica de rotación último año (12 meses)
 */
    public function chartLastYear(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $endDate
    ): array {
        $start = $endDate->copy()->subMonths(11)->startOfMonth();
        $end   = $endDate->copy()->endOfMonth();

        return $this->chartByRange(
            $portalId,
            $allowedClients,
            $clientId,
            $start,
            $end
        );
    }

    /**
     * Gráfica de rotación diaria (días del mes)
     */
    public function chartDaily(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $month
    ): array {
        $sub = $this->formerCycleSub();

        $start = $month->copy()->startOfMonth();
        $end   = $month->copy()->endOfMonth();

        $daysInMonth = $start->daysInMonth;

        $chart = [
            'days'         => [],
            'hires'        => [],
            'terminations' => [],
            'turnover_pct' => [],
        ];
        // =======================
        // ALTAS agrupadas por día
        // =======================
        $hiresByDay = $this->db()->table('empleados as e')
            ->selectRaw(
                "DATE(COALESCE(e.fecha_ingreso, DATE(e.creacion))) as d, COUNT(*) as total"
            )
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->whereBetween(
                DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                [$start->toDateString(), $end->toDateString()]
            )
            ->groupBy('d')
            ->pluck('total', 'd'); // ['2026-01-05' => 3]
                               // =======================
                               // BAJAS agrupadas por día
                               // =======================
        $termsByDay = $this->db()->table(DB::raw("({$sub->toSql()}) as x"))
            ->mergeBindings($sub)
            ->join('empleados as e', 'e.id', '=', 'x.id_empleado')
            ->selectRaw("DATE(x.exit_date) as d, COUNT(*) as total")
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->whereBetween(
                'x.exit_date',
                [$start->toDateString(), $end->toDateString()]
            )
            ->groupBy('d')
            ->pluck('total', 'd');

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $day = Carbon::create($start->year, $start->month, $d);

            $dateKey = $day->toDateString(); // yyyy-mm-dd

            $chart['days'][] = $day->format('d');

            // ✅ ALTAS (desde agrupado)
            $hires = (int) ($hiresByDay[$dateKey] ?? 0);

            // ✅ BAJAS (desde agrupado)
            $terms = (int) ($termsByDay[$dateKey] ?? 0);

            // HEADCOUNT PROMEDIO DEL DÍA (se queda igual)
            $hcStart = $this->headcountAsOf(
                $portalId,
                $allowedClients,
                $clientId,
                $day->copy()->startOfDay()
            );

            $hcEnd = $this->headcountAsOf(
                $portalId,
                $allowedClients,
                $clientId,
                $day->copy()->endOfDay()
            );

            $avg = ($hcStart + $hcEnd) / 2;

            $chart['hires'][]        = $hires;
            $chart['terminations'][] = $terms;
            $chart['turnover_pct'][] = $avg > 0
                ? round(($terms / $avg) * 100, 2)
                : 0.0;
        }

        return $chart;
    }
    /**
 * Top sucursales/clientes con mayor rotación en el rango
 * (usa la MISMA definición de bajas/headcount que el widget)
 */
public function topClientsByTurnover(
    int $portalId,
    Collection $allowedClients,
    ?int $clientId,          // si viene un cliente único, limita a ese
    Carbon $rangeStart,
    Carbon $rangeEnd,
    int $limit = 5
): array {

    $sub = $this->formerCycleSub();

    // =========================
    // 1) BAJAS por cliente (exit_date)
    // =========================
    $termsByClient = $this->db()->table(DB::raw("({$sub->toSql()}) as x"))
        ->mergeBindings($sub)
        ->join('empleados as e', 'e.id', '=', 'x.id_empleado')
        ->selectRaw('e.id_cliente as client_id, COUNT(*) as total')
        ->where('e.id_portal', $portalId)
        ->where('e.eliminado', 0)
        ->when(
            $clientId,
            fn($q) => $q->where('e.id_cliente', $clientId),
            fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
        )
        ->whereBetween('x.exit_date', [
            $rangeStart->toDateString(),
            $rangeEnd->toDateString(),
        ])
        ->groupBy('e.id_cliente')
        ->pluck('total', 'client_id'); // [12 => 3, 7 => 1]

    // =========================
    // 2) ALTAS por cliente (misma lógica que chartByRange)
    // =========================
    $hiresByClient = $this->db()->table('empleados as e')
        ->selectRaw('e.id_cliente as client_id, COUNT(*) as total')
        ->where('e.id_portal', $portalId)
        ->where('e.eliminado', 0)
        ->when(
            $clientId,
            fn($q) => $q->where('e.id_cliente', $clientId),
            fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
        )
        ->whereBetween(
            DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
            [$rangeStart->toDateString(), $rangeEnd->toDateString()]
        )
        ->groupBy('e.id_cliente')
        ->pluck('total', 'client_id');

    // =========================
    // 3) Nombres (cliente)
    // =========================
    $clientIds = $clientId
        ? collect([$clientId])
        : $allowedClients;

    $names = $this->db()->table('cliente')
        ->whereIn('id', $clientIds->values()->all())
        ->pluck('nombre', 'id'); // [12 => 'SUCURSAL GDL', 7 => '...']

    // =========================
    // 4) Armar lista + turnover%
    // =========================
    $out = [];

    foreach ($clientIds->values()->all() as $cid) {
        $cid = (int) $cid;

        $terms = (int) ($termsByClient[$cid] ?? 0);
        $hires = (int) ($hiresByClient[$cid] ?? 0);

        $hcS = $this->headcountAsOf($portalId, $allowedClients, $cid, $rangeStart);
        $hcE = $this->headcountAsOf($portalId, $allowedClients, $cid, $rangeEnd);
        $avg = ($hcS + $hcE) / 2;

        $pct = $avg > 0 ? round(($terms / $avg) * 100, 2) : 0.0;

        $level = $pct >= 10 ? 'danger' : ($pct >= 5 ? 'warn' : 'ok');

        $out[] = [
            'key'   => $cid,
            'main'  => (string) ($names[$cid] ?? ("Cliente {$cid}")),
            'sub'   => "{$terms} bajas · {$hires} altas · HC " . (int) round($avg),
            'badge' => number_format($pct, 2) . '%',
            'level' => $level,
            '_raw'  => [
                'client_id'    => $cid,
                'hires'        => $hires,
                'terminations' => $terms,
                'hc_start'     => $hcS,
                'hc_end'       => $hcE,
                'avg_hc'       => $avg,
                'turnover_pct' => $pct,
            ],
        ];
    }

    // ordenar por % desc, luego por bajas desc
    usort($out, function ($a, $b) {
        $pa = (float) ($a['_raw']['turnover_pct'] ?? 0);
        $pb = (float) ($b['_raw']['turnover_pct'] ?? 0);
        if ($pb !== $pa) return $pb <=> $pa;

        $ta = (int) ($a['_raw']['terminations'] ?? 0);
        $tb = (int) ($b['_raw']['terminations'] ?? 0);
        return $tb <=> $ta;
    });

    return array_slice($out, 0, $limit);
}


}
