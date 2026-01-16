<?php

namespace App\Services\Dashboard\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EmployeesWidget
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

    public function activeCount(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId
    ): int {
        return $this->db()->table('empleados as e')
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->where('e.status', 1)
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->count();
    }

    public function hiresInRange(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $rangeStart,
        Carbon $rangeEnd
    ): int {
        return $this->db()->table('empleados as e')
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
            ->count();
    }
}
