<?php
namespace App\Services\Dashboard\Widgets;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BirthdaysWidget
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
     * Próximos cumpleaños
     */
    public function upcoming(
        int $portalId,
        Collection $allowedClients,
        ?int $clientId,
        Carbon $today,
        int $daysAhead,
        int $limit = 30
    ): array {
        $people = $this->db()->table('empleados as e')
            ->select(
                'e.id',
                'e.id_cliente',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.fecha_nacimiento'
            )
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->where('e.status', 1)
            ->whereNotNull('e.fecha_nacimiento')
            ->when(
                $clientId,
                fn($q) => $q->where('e.id_cliente', $clientId),
                fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
            )
            ->limit(5000)
            ->get();

        $upcoming = [];

        $endDate = $today->copy()->addDays($daysAhead);

        foreach ($people as $p) {
            try {
                $birth = Carbon::parse($p->fecha_nacimiento);

                // Mover cumpleaños al año actual
                $next = $birth->copy()->year($today->year);

                // Ajuste para 29 de febrero
                if (! $next->isValid()) {
                    $next = Carbon::create($today->year, 3, 1);
                }

                // Si ya pasó este año, usar el siguiente
                if ($next->lt($today)) {
                    $next = $next->addYear();

                    if (! $next->isValid()) {
                        $next = Carbon::create($today->year + 1, 3, 1);
                    }
                }

                // ✅ AQUÍ estaba el problema
                if ($next->betweenIncluded($today, $endDate)) {
                    $upcoming[] = [
                        'employee_id' => (int) $p->id,
                        'client_id'   => (int) $p->id_cliente,
                        'name'        => trim("{$p->nombre} {$p->paterno} {$p->materno}"),
                        'date'    => $next->toDateString(),
                        'in_days' => $today->diffInDays($next),
                        'foto'    => $p->foto,
                    ];
                }

            } catch (\Throwable $e) {
                // ignorar fechas inválidas
            }
        }

        usort($upcoming, fn($a, $b) => $a['in_days'] <=> $b['in_days']);

        return array_slice($upcoming, 0, $limit);
    }
}
