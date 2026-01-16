<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;

class QualityService
{
    public function __construct(private string $conn = 'portal_main')
    {}

    private function db()
    {
        return DB::connection($this->conn);
    }

    public function fetch(
        int $portalId,
        array $allowedClients,
        ?int $clientId
    ): array {

        $applyClientScope = function ($q) use ($clientId, $allowedClients) {
            if ($clientId) {
                return $q->where('e.id_cliente', $clientId);
            }
            return $q->whereIn('e.id_cliente', $allowedClients);
        };

        // =========================
        // DOCUMENTOS
        // =========================
        $docs = $this->db()->table('documents_empleado as d')
            ->join('empleados as e', 'e.id', '=', 'd.employee_id')
            ->leftJoin('document_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'd.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->whereIn('d.status', [2, 3])
            ->tap($applyClientScope)
            ->selectRaw("
            'document' as kind,
            d.id,
            d.employee_id,
            e.id_cliente as client_id,
            e.foto,
            TRIM(CONCAT_WS(' ', e.nombre, e.paterno, e.materno)) as employee_name,
            CONCAT(
                CASE
                    WHEN d.id_opcion IS NOT NULL THEN opt.name
                    ELSE d.nameDocument
                END,
                ' - Documento'
            ) as title,
            d.status
        ");

        // =========================
        // CURSOS
        // =========================
        $courses = $this->db()->table('cursos_empleados as c')
            ->join('empleados as e', 'e.id', '=', 'c.employee_id')
            ->leftJoin('cursos_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'c.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->whereIn('c.status', [2, 3])
            ->tap($applyClientScope)
            ->selectRaw("
                'course' as kind,
                c.id,
                c.employee_id,
                e.id_cliente as client_id,
                e.foto,
                TRIM(CONCAT_WS(' ', e.nombre, e.paterno, e.materno)) as employee_name,
                CONCAT(
                    CASE
                        WHEN c.id_opcion IS NOT NULL THEN opt.name
                        ELSE c.nameDocument
                    END,
                    ' - Curso'
                ) as title,
                c.status
            ");

        // =========================
        // EXÁMENES
        // =========================
        $exams = $this->db()->table('exams_empleados as x')
            ->join('empleados as e', 'e.id', '=', 'x.employee_id')
            ->leftJoin('exams_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'x.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)
            ->whereIn('x.status', [2, 3])
            ->tap($applyClientScope)
            ->selectRaw("
                'exam' as kind,
                x.id,
                x.employee_id,
                e.id_cliente as client_id,
                e.foto,
                TRIM(CONCAT_WS(' ', e.nombre, e.paterno, e.materno)) as employee_name,
                CONCAT(
                    CASE
                        WHEN x.id_candidato IS NOT NULL THEN x.name
                        WHEN x.id_opcion IS NOT NULL THEN opt.name
                        ELSE x.nameDocument
                    END,
                    ' - Examen'
                ) as title,
                x.status
            ");

        $union = $docs->unionAll($courses)->unionAll($exams);

        $items = $this->db()->query()
            ->fromSub($union, 'u')
            ->orderBy('status') // 2 primero, luego 3
            ->limit(50)
            ->get()
            ->map(fn($r) => (array) $r)
            ->all();

        return [
            'count_regular' => collect($items)->where('status', 2)->count(),
            'count_bad'     => collect($items)->where('status', 3)->count(),
            'items'         => $items,
        ];
    }
}
