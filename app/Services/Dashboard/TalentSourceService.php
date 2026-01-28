<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TalentSourceService
{
    public function __construct(private string $conn = 'portal_main')
    {}

    private function db()
    {
        return DB::connection($this->conn);
    }

    /**
     * Origen del talento (Pie)
     * Fuente: bolsa_trabajo.medio_contacto
     *
     * Regresa:
     *  ['labels' => [...], 'series' => [...]]  // series = [nums] para pie
     *
     * Nota: bolsa_trabajo NO tiene id_cliente, así que el filtro es:
     * - id_portal
     * - rango fechas (creacion)
     */
    public function breakdown(
        int $portalId,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        int $limit = 8
    ): array {

        $rows = $this->db()->table('bolsa_trabajo as b')
            ->selectRaw("
                          CASE
                              WHEN b.medio_contacto IS NULL THEN 'Otros medios'
                              WHEN TRIM(b.medio_contacto) = '' THEN 'Otros medios'
                              WHEN LOWER(TRIM(b.medio_contacto)) IN (
                                  'sin especificar','sinespecificar',
                                  'null','undefined',
                                  'na','n/a','-','no aplica','noaplica'
                              ) THEN 'Otros medios'
                              ELSE TRIM(b.medio_contacto)
                          END as label,
                          COUNT(*) as total
                      ")
            ->where('b.id_portal', $portalId)
            ->whereBetween('b.creacion', [
                $rangeStart->copy()->startOfDay(),
                $rangeEnd->copy()->endOfDay(),
            ])
            ->where('b.status', '>=', 1) // opcional, si quieres ignorar registros “apagados”
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $labels = [];
        $series = [];

        foreach ($rows as $r) {
            $labels[] = (string) $r->label;
            $series[] = (int) $r->total;
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }
}
