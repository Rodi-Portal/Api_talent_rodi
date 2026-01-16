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

        $q = $this->db()->table('requisicion as r')
            ->where('r.eliminado', 0)
            ->where('r.id_portal', $portalId)
            ->when(
                $clientId,
                fn($q) => $q->where('r.id_cliente', $clientId),
                fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
            );

        // ================================
        // Abiertas
        // ================================
        $open = (clone $q)->where('status', 1)->count();

        // ================================
        // En proceso
        // ================================
        $inProcess = (clone $q)->where('status', 2)->count();

        // ================================
        // Finalizadas (status 3 + NO cancelada)
        // ================================
        $finished = (clone $q)
            ->where('status', 3)
            ->where(function ($w) {
                $w->whereNull('comentario_final')
                    ->orWhereRaw("LOWER(comentario_final) NOT LIKE '%cancel%'");
            })
            ->count();

        // ================================
        // Canceladas (status 3 + texto cancelada)
        // ================================
        $cancelled = (clone $q)
            ->where('status', 3)
            ->whereRaw("LOWER(comentario_final) LIKE '%cancel%'")
            ->count();

        return [
            'requisitions_open'       => $open,
            'requisitions_in_process' => $inProcess,
            'requisitions_finished'   => $finished,
            'requisitions_cancelled'  => $cancelled,
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

        $months  = [];
        $created = [];
        $closed  = [];

        $cursor = $start->copy()->startOfMonth();

        while ($cursor <= $end) {
            $mStart = $cursor->copy()->startOfMonth();
            $mEnd   = $cursor->copy()->endOfMonth();

            $label    = $mStart->format('Y-m');
            $months[] = $label;

            // Creadas
            $created[] = $this->db()->table('requisicion as r')
                ->where('r.eliminado', 0)
                ->where('r.id_portal', $portalId)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereBetween('r.creacion', [$mStart, $mEnd])
                ->count();

            // Cerradas
            $closed[] = $this->db()->table('requisicion as r')
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

            $cursor->addMonth();
        }

        return [
            'months'  => $months,
            'created' => $created,
            'closed'  => $closed,
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

        $days    = [];
        $created = [];
        $closed  = [];

        $cursor = $start->copy();

        while ($cursor <= $end) {
            $label  = $cursor->format('d');
            $days[] = $label;

            // Creadas ese día
            $created[] = $this->db()->table('requisicion as r')
                ->where('r.eliminado', 0)
                ->where('r.id_portal', $portalId)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereDate('r.creacion', $cursor->toDateString())
                ->count();

            // Cerradas ese día
            $closed[] = $this->db()->table('requisicion as r')
                ->where('r.eliminado', 0)
                ->where('r.id_portal', $portalId)
                ->where('status', 3)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->whereDate('r.edicion', $cursor->toDateString())
                ->where(function ($w) {
                    $w->whereNull('comentario_final')
                        ->orWhereRaw("LOWER(comentario_final) NOT LIKE '%cancel%'");
                })
                ->count();

            $cursor->addDay();
        }

        return [
            'days'    => $days,
            'created' => $created,
            'closed'  => $closed,
        ];
    }

}
