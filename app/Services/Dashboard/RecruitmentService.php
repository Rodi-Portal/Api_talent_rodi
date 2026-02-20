<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecruitmentService
{
    private string $conn;

    public function __construct(string $connection)
    {
        $this->conn = $connection;
    }

    private function db()
    {
        return DB::connection($this->conn);
    }

    /**
     * =======================================================
     *  ⭐ KPIs para el dashboard
     * =======================================================
     */
    public function getKpis(
        int $portalId,
        $allowedClients,
        ?int $clientId,
        Carbon $start,
        Carbon $end
    ): array {

        $baseQuery = $this->db()->table('requisicion as r')
            ->where('r.id_portal', $portalId)
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            );

        // ======================================
        // 1️⃣ Vacantes activas actuales
        // ======================================
        $active = (clone $baseQuery)
            ->where('r.eliminado', 0)
            ->whereNull('r.comentario_final')
            ->count();

        // ======================================
        // 2️⃣ Vacantes creadas en el periodo
        // ======================================
        $created = (clone $baseQuery)
            ->where('r.eliminado', 0)
            ->whereBetween('r.creacion', [$start, $end])
            ->count();

        // ======================================
        // 3️⃣ Vacantes cerradas en el periodo
        // ======================================
        $closed = (clone $baseQuery)
            ->where('r.eliminado', 0)
            ->whereNotNull('r.comentario_final')
            ->whereBetween('r.edicion', [$start, $end])
            ->count();

        // ======================================
        // 4️⃣ Vacantes canceladas en el periodo
        // ======================================
        $cancelled = (clone $baseQuery)
            ->where('r.eliminado', 1)
            ->whereBetween('r.edicion', [$start, $end])
            ->count();

        // ======================================
        // 5️⃣ Contrataciones reales en el periodo
        // ======================================
        $hired = $this->db()
            ->table('requisicion as r')
            ->join('requisicion_aspirante as ra', 'ra.id_requisicion', '=', 'r.id')
            ->where('r.id_portal', $portalId)
            ->whereIn('ra.status_final', ['COMPLETADO', 'FINALIZADO'])
            ->whereBetween('ra.fecha_ingreso', [$start, $end])
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            )
            ->count();

        // ======================================
        // 6️⃣ % Cobertura del periodo
        // ======================================
        $coverageRate = $created > 0
            ? round(($hired / $created) * 100, 2)
            : 0;

        return [
            'requisitions_active'       => $active,
            'requisitions_created'      => $created,
            'requisitions_closed'       => $closed,
            'requisitions_cancelled'    => $cancelled,
            'requisitions_hired'        => $hired,
            'requisitions_coverage_pct' => $coverageRate,
        ];
    }

    /**
     * =======================================================
     *  ⭐ Gráfica últimos 6 meses
     * =======================================================
     */
    public function getChart(
        int $portalId,
        $allowedClients,
        ?int $clientId,
        Carbon $periodBase
    ): array {

        $months  = [];
        $created = [];
        $closed  = [];

        for ($i = 5; $i >= 0; $i--) {
            $mStart = $periodBase->copy()->subMonths($i)->startOfMonth();
            $mEnd   = $periodBase->copy()->subMonths($i)->endOfMonth();

            $label    = $mStart->format('Y-m');
            $months[] = $label;

            // ================================
            // Requisiciones creadas en el mes
            // ================================
            $createdCount = $this->db()->table('requisicion as r')
                ->where('r.eliminado', 0)
                ->where('r.id_portal', $portalId)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.creacion', [$mStart, $mEnd])
                ->count();

            // ================================
            // Requisiciones finalizadas en el mes
            // ================================
            $closedCount = $this->db()->table('requisicion as r')
                ->where('r.eliminado', 0)
                ->where('r.id_portal', $portalId)
                ->where('status', 3)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.edicion', [$mStart, $mEnd])
                ->where(function ($w) {
                    $w->whereNull('comentario_final')
                        ->orWhereRaw("LOWER(comentario_final) NOT LIKE '%cancel%'");
                })
                ->count();

            $created[] = $createdCount;
            $closed[]  = $closedCount;
        }

        return [
            'months'  => $months,
            'created' => $created,
            'closed'  => $closed,
        ];
    }

    public function getChartByRange(
        int $portalId,
        $allowedClients,
        ?int $clientId,
        Carbon $start,
        Carbon $end
    ): array {

        $months    = [];
        $waiting   = [];
        $inProcess = [];
        $closed    = [];
        $cancelled = [];

        $cursor = $start->copy()->startOfMonth();

        while ($cursor <= $end) {

            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $months[] = $mStart->format('Y-m');

            // ============================
            // EN ESPERA
            // ============================
            $waiting[] = $this->db()->table('requisicion as r')
                ->where('r.id_portal', $portalId)
                ->where('r.eliminado', 0)
                ->whereNull('r.comentario_final')
                ->where('r.status', 1)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.creacion', [$mStart, $mEnd])
                ->count();

            // ============================
            // EN PROCESO
            // ============================
            $inProcess[] = $this->db()->table('requisicion as r')
                ->where('r.id_portal', $portalId)
                ->where('r.eliminado', 0)
                ->whereNull('r.comentario_final')
                ->where('r.status', 2)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.creacion', [$mStart, $mEnd])
                ->count();

            // ============================
            // CERRADAS
            // ============================
            $closed[] = $this->db()->table('requisicion as r')
                ->where('r.id_portal', $portalId)
                ->where('r.eliminado', 0)
                ->whereNotNull('r.comentario_final')
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.edicion', [$mStart, $mEnd])
                ->count();

            // ============================
            // CANCELADAS
            // ============================
            $cancelled[] = $this->db()->table('requisicion as r')
                ->where('r.id_portal', $portalId)
                ->where('r.eliminado', 1)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.edicion', [$mStart, $mEnd])
                ->count();

            $cursor->addMonth();
        }

        return [
            'months'     => $months,
            'waiting'    => $waiting,
            'in_process' => $inProcess,
            'closed'     => $closed,
            'cancelled'  => $cancelled,
        ];
    }

public function getChartDaily(
    int $portalId,
    $allowedClients,
    ?int $clientId,
    Carbon $periodBase
): array {

    $start = $periodBase->copy()->startOfMonth();
    $end   = $periodBase->copy()->endOfMonth();

    $days        = [];
    $waiting     = [];
    $inProcess   = [];
    $closed      = [];
    $cancelled   = [];

    $cursor = $start->copy();

    while ($cursor <= $end) {

        $days[] = $cursor->format('d');

        // ============================
        // EN ESPERA
        // ============================
        $waiting[] = $this->db()->table('requisicion as r')
            ->where('r.id_portal', $portalId)
            ->where('r.eliminado', 0)
            ->whereNull('r.comentario_final')
            ->where('r.status', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            )
            ->whereDate('r.creacion', $cursor->toDateString())
            ->count();

        // ============================
        // EN PROCESO
        // ============================
        $inProcess[] = $this->db()->table('requisicion as r')
            ->where('r.id_portal', $portalId)
            ->where('r.eliminado', 0)
            ->whereNull('r.comentario_final')
            ->where('r.status', 2)
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            )
            ->whereDate('r.creacion', $cursor->toDateString())
            ->count();

        // ============================
        // CERRADAS
        // ============================
        $closed[] = $this->db()->table('requisicion as r')
            ->where('r.id_portal', $portalId)
            ->where('r.eliminado', 0)
            ->whereNotNull('r.comentario_final')
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            )
            ->whereDate('r.edicion', $cursor->toDateString())
            ->count();

        // ============================
        // CANCELADAS
        // ============================
        $cancelled[] = $this->db()->table('requisicion as r')
            ->where('r.id_portal', $portalId)
            ->where('r.eliminado', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            )
            ->whereDate('r.edicion', $cursor->toDateString())
            ->count();

        $cursor->addDay();
    }

    return [
        'days'       => $days,
        'waiting'    => $waiting,
        'in_process' => $inProcess,
        'closed'     => $closed,
        'cancelled'  => $cancelled,
    ];
}


}
