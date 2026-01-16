<?php
namespace App\Services\Dashboard\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CalendarWidget
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
     * Cuenta eventos activos hoy por tipo
     */
    public function countToday(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $today,
        array $tipoIds
    ): int {
        return $this->db()->table('calendario_eventos as ev')
            ->join('empleados as e', 'e.id', '=', 'ev.id_empleado')
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->where('e.status', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->where('ev.eliminado', 0)
            ->whereDate('ev.inicio', '<=', $today->toDateString())
            ->whereDate('ev.fin', '>=', $today->toDateString())
            ->whereIn('ev.id_tipo', $tipoIds)
            ->count(DB::raw('DISTINCT ev.id_empleado'));
    }

/**
 * 📊 Incidencias agrupadas por mes y tipo
 */
/**
 * 📊 Incidencias agrupadas por mes y tipo (rango anual)
 */
    public function incidencesByMonth(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $start,
        Carbon $end
    ): array {

        // 1️⃣ Normalizar rango a meses completos
        $start = $start->copy()->startOfMonth();
        $end   = $end->copy()->startOfMonth();

        // 2️⃣ Construir lista de meses (YYYY-MM)
        $months = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        // 3️⃣ Traer incidencias agrupadas por mes y tipo
        $rows = $this->db()
            ->table('calendario_eventos as ev')
            ->join('empleados as e', 'e.id', '=', 'ev.id_empleado')
            ->join('eventos_option as opt', 'opt.id', '=', 'ev.id_tipo')
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->where('e.status', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->where('ev.eliminado', 0)
            ->whereDate('ev.inicio', '<=', $end->copy()->endOfMonth()->toDateString())
            ->whereDate('ev.fin', '>=', $start->toDateString())
            ->selectRaw("
            DATE_FORMAT(ev.inicio, '%Y-%m') as ym,
            opt.name as tipo,
            COUNT(*) as total
        ")
            ->groupBy('ym', 'tipo')
            ->get();

        // 4️⃣ Inicializar series por tipo con ceros
        $seriesMap = [];

        foreach ($rows as $r) {
            if (! isset($seriesMap[$r->tipo])) {
                $seriesMap[$r->tipo] = array_fill(0, count($months), 0);
            }
        }

        // 5️⃣ Asignar valores reales a cada mes
        foreach ($rows as $r) {
            $idx = array_search($r->ym, $months, true);
            if ($idx !== false) {
                $seriesMap[$r->tipo][$idx] = (int) $r->total;
            }
        }

        // 6️⃣ Formato final ApexCharts
        $finalSeries = [];

        foreach ($seriesMap as $name => $data) {
            $finalSeries[] = [
                'name' => $name,
                'data' => $data,
            ];
        }

        return [
            'labels' => $months,
            'series' => $finalSeries,
        ];
    }

/**
 * 📊 Incidencias agrupadas por día (mes actual)
 * Cuenta eventos activos en cada día, por tipo
 */
/**
 * 📊 Incidencias agrupadas por día (mes actual)
 * Cuenta eventos activos en cada día, por tipo
 */
    public function incidencesDaily(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $start,
        Carbon $end
    ): array {

        // 1️⃣ Normalizar rango al mes completo
        $start = $start->copy()->startOfMonth();
        $end   = $start->copy()->endOfMonth();

        // 2️⃣ Generar lista de días del mes (01..28/29/30/31)
        $days   = [];
        $cursor = $start->copy();

        while ($cursor <= $end) {
            $days[] = $cursor->format('d');
            $cursor->addDay();
        }

        // 3️⃣ Traer eventos que INTERSECTAN el mes
        $rows = $this->db()
            ->table('calendario_eventos as ev')
            ->join('empleados as e', 'e.id', '=', 'ev.id_empleado')
            ->join('eventos_option as opt', 'opt.id', '=', 'ev.id_tipo')
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->where('e.status', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->where('ev.eliminado', 0)
            ->whereDate('ev.inicio', '<=', $end->toDateString())
            ->whereDate('ev.fin', '>=', $start->toDateString())
            ->select([
                'ev.inicio',
                'ev.fin',
                'opt.name as tipo',
            ])
            ->get();

        // 4️⃣ Si no hay eventos, devolver series vacías alineadas
        if ($rows->isEmpty()) {
            return [
                'days'   => $days,
                'series' => [],
            ];
        }

        // 5️⃣ Inicializar series por tipo (una posición por cada día)
        $series = [];

        foreach ($rows as $r) {
            if (! isset($series[$r->tipo])) {
                $series[$r->tipo] = array_fill(0, count($days), 0);
            }
        }

        // 6️⃣ Contar eventos activos por día
        foreach ($rows as $r) {
            $eventStart = Carbon::parse($r->inicio)->startOfDay();
            $eventEnd   = Carbon::parse($r->fin)->endOfDay();

            $cursor = $start->copy();
            $i      = 0;

            while ($cursor <= $end) {
                if ($eventStart <= $cursor && $eventEnd >= $cursor) {
                    $series[$r->tipo][$i]++;
                }
                $cursor->addDay();
                $i++;
            }
        }

        // 7️⃣ Formato final compatible con ApexCharts
        $finalSeries = [];

        foreach ($series as $name => $data) {
            $finalSeries[] = [
                'name' => $name,
                'data' => $data,
            ];
        }
        logger()->info('INCIDENCES DAILY FINAL', [
            'days_count' => count($days),
            'series'     => $finalSeries,
        ]);

        return [
            'days'   => $days,
            'series' => $finalSeries,
        ];
    }

}
