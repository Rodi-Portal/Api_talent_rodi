<?php
namespace App\Services\MiPortal;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmpleadoDashboardService
{
    public function __construct(private string $conn = 'portal_main')
    {}

    private function db()
    {
        return DB::connection($this->conn);
    }

    public function fetch(int $employeeId): array
    {
        $today = Carbon::today()->toDateString();

        // =========================
        // VENCIDOS CON REMINDER
        // =========================
        $expired = $this->db()
            ->table('documents_empleado')
            ->where('employee_id', $employeeId)
            ->whereNotNull('expiry_date')
            ->whereNotNull('expiry_reminder')
            ->where('expiry_reminder', '>', 0)
            ->whereDate('expiry_date', '<', $today)
            ->select('id','nameDocument','expiry_date')
            ->get();

        // =========================
        // POR VENCER CON REMINDER
        // =========================
        $expiring = $this->db()
            ->table('documents_empleado')
            ->where('employee_id', $employeeId)
            ->whereNotNull('expiry_date')
            ->whereNotNull('expiry_reminder')
            ->where('expiry_reminder', '>', 0)
            ->whereRaw("DATEDIFF(expiry_date, ?) BETWEEN 0 AND expiry_reminder", [$today])
            ->select('id','nameDocument','expiry_date')
            ->get();

        // =========================
        // CALIDAD
        // =========================
        $regularCount = $this->db()
            ->table('documents_empleado')
            ->where('employee_id', $employeeId)
            ->where('status', 2)
            ->count();

        $badCount = $this->db()
            ->table('documents_empleado')
            ->where('employee_id', $employeeId)
            ->where('status', 3)
            ->count();

        return [
            'kpis' => [
                'expired_count'  => $expired->count(),
                'expiring_count' => $expiring->count(),
                'regular_count'  => $regularCount,
                'bad_count'      => $badCount,
            ],
            'expired_items'  => $expired,
            'expiring_items' => $expiring,
        ];
    }
}