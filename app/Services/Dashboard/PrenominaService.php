<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PrenominaService
{
    public function __construct(private string $conn = 'portal_main')
    {}

    /**
     * Gráfica de pagos de prenómina por periodo
     *
     * @param int        $portalId
     * @param \Illuminate\Support\Collection $allowedClients
     * @param int|null   $clientId
     * @param int|null   $year  Año seleccionado (opcional)
     */
    public function chartByPeriod(
        int $portalId,
        $allowedClients,
        ?int $clientId,
        ?int $year = null
    ): array {
        $db = DB::connection($this->conn);

        // 📅 Rango de fechas
        if ($year) {
            $start = Carbon::create($year, 1, 1)->startOfDay();
            $end   = Carbon::create($year, 12, 31)->endOfDay();
        } else {
            $end   = Carbon::today()->endOfDay();
            $start = $end->copy()->subDays(365)->startOfDay();
        }

        $rows = $db->table('periodos_nomina as p')
            ->join(
                'pre_nomina_empleados as e',
                'e.id_periodo_nomina',
                '=',
                'p.id'
            )
            ->where('p.id_portal', $portalId)
            ->where(function ($q) use ($allowedClients, $portalId) {
                $q->whereIn('p.id_cliente', $allowedClients->all())
                    ->orWhere(function ($qq) use ($portalId) {
                        $qq->whereNull('p.id_cliente')
                            ->where('p.id_portal', $portalId);
                    });
            })

            ->whereIn('p.estatus', ['pendiente', 'cerrado'])
            ->whereBetween('p.fecha_pago', [$start, $end])
            ->when(
                $clientId && count($allowedClients) === 1,
                fn($q) => $q->where('p.id_cliente', $clientId)
            )
            ->groupBy(
                'p.id',
                'p.fecha_pago',
                'p.periodo_num'
            )
            ->orderBy('p.fecha_pago')
            ->orderBy('p.id')

            ->selectRaw("
                p.periodo_num,
                YEAR(p.fecha_pago) as anio,
                SUM(e.sueldo_total)   as total_imss,
                SUM(e.sueldo_total_a) as total_complementario,
                SUM(e.sueldo_total_t) as total_pagado
            ")
            ->get();

        // 🧩 Normalizar para ApexCharts
        $labels = [];
        $imss   = [];
        $comp   = [];
        $total  = [];

        foreach ($rows as $r) {

            // 🔑 periodo_num viene como JSON (ej. "[18]" o "[18,19]")
            $periodos = is_array($r->periodo_num)
                ? $r->periodo_num
                : json_decode($r->periodo_num, true);

            // fallback defensivo
            if (! is_array($periodos) || empty($periodos)) {
                $labelPeriodo = 'P?';
            } else {
                $periodos = array_map('intval', $periodos);
                sort($periodos);

                if (count($periodos) === 1) {
                    $labelPeriodo = 'P' . str_pad($periodos[0], 2, '0', STR_PAD_LEFT);
                } else {
                    $labelPeriodo =
                    'P' . str_pad($periodos[0], 2, '0', STR_PAD_LEFT)
                    . '–'
                    . str_pad(end($periodos), 2, '0', STR_PAD_LEFT);
                }
            }

            $labels[] = "{$r->anio}-{$labelPeriodo}";
            $imss[]   = (float) $r->total_imss;
            $comp[]   = (float) $r->total_complementario;
            $total[]  = (float) $r->total_pagado;
        }

        return [
            'labels' => $labels,
            'series' => [
                ['name' => 'IMSS', 'data' => $imss],
                ['name' => 'Complementarios', 'data' => $comp],
                ['name' => 'Total pagado', 'data' => $total],
            ],
        ];
    }
}
