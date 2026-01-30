<?php
namespace App\Services\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ExpiryService
{
    public function __construct(private string $conn = 'portal_main')
    {}
    private function cleanTitle($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        if ($v === '' || $v === '0') {
            return null;
        }

        return $v;
    }

    public function fetch(
        int $portalId,
        array $allowedClients,
        ?int $clientId,
        Carbon $today,
        int $expireDays,
        int $expiredDays
    ): array {
        $db       = DB::connection($this->conn);
        $todayStr = $today->toDateString();

        // ===== helper de scope clientes (mismo para los 3)
        $applyClientScope = function ($q) use ($clientId, $allowedClients) {
            if ($clientId) {
                return $q->where('e.id_cliente', $clientId);
            }

            return $q->whereIn('e.id_cliente', $allowedClients);
        };

        // ==================================================
        // EXPIRING (por vencer)
        // ==================================================
        $docsExp = $db->table('documents_empleado as d')
            ->join('empleados as e', 'e.id', '=', 'd.employee_id')
            ->leftJoin('document_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'd.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->where('d.status', 1)
            ->whereNotNull('d.expiry_date')
            ->whereNotNull('d.expiry_reminder')
            ->where('d.expiry_reminder', '>', 0)
            ->whereRaw(
                "DATEDIFF(d.expiry_date, ?) BETWEEN 0 AND ?",
                [$todayStr, $expireDays]
            )

            ->tap($applyClientScope)
            ->selectRaw(
                "'expiring' as state,
                 'document' as kind,
                 d.id as id,
                 d.employee_id as employee_id,
                 e.id_cliente as client_id,
                 TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                 e.foto,
               CONCAT(
                    CASE
                        WHEN d.id_opcion IS NOT NULL THEN opt.name
                        ELSE d.nameDocument
                    END,
                    ' - Doc'
                ) as title,

                CONCAT(
                    CASE
                        WHEN d.id_opcion IS NOT NULL THEN opt.name
                        ELSE d.nameDocument
                    END,
                    ' - Doc'
                ) as doc_name,


                 d.expiry_date as expiry_date,
                 DATEDIFF(d.expiry_date, ?) as in_days,
                 COALESCE(NULLIF(d.expiry_reminder,0), ?) as remind_days,
                 NULL as overdue_days",
                [$todayStr, $expireDays]
            );

        $coursesExp = $db->table('cursos_empleados as c')
            ->join('empleados as e', 'e.id', '=', 'c.employee_id')
            ->leftJoin('cursos_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'c.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->whereNotNull('c.expiry_date')
                       ->whereNotNull('c.expiry_reminder')
            ->where('c.expiry_reminder', '>', 0)
            ->whereRaw(
                "DATEDIFF(c.expiry_date, ?) BETWEEN 0 AND ?",
                [$todayStr, $expireDays]
            )

            ->tap($applyClientScope)
            ->selectRaw(
                "'expiring' as state,
                 'course' as kind,
                 c.id as id,
                 c.employee_id as employee_id,
                 e.id_cliente as client_id,
                 TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                   e.foto,
               CONCAT(
                    CASE
                        WHEN c.id_opcion IS NOT NULL THEN opt.name
                        ELSE c.nameDocument
                    END,
                    ' - Curso'
                ) as title,

                CONCAT(
                    CASE
                        WHEN c.id_opcion IS NOT NULL THEN opt.name
                        ELSE c.nameDocument
                    END,
                    ' - Curso'
                    ) as doc_name,


                 c.expiry_date as expiry_date,
                 DATEDIFF(c.expiry_date, ?) as in_days,
                 COALESCE(NULLIF(c.expiry_reminder,0), ?) as remind_days,
                 NULL as overdue_days",
                [$todayStr, $expireDays]
            );

        $examsExp = $db->table('exams_empleados as x')
            ->join('empleados as e', 'e.id', '=', 'x.employee_id')
            ->leftJoin('exams_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'x.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->whereNotNull('x.expiry_date')
            ->whereNotNull('x.expiry_reminder')
            ->where('x.expiry_reminder', '>', 0)
            ->whereRaw(
                "DATEDIFF(x.expiry_date, ?) BETWEEN 0 AND ?",
                [$todayStr, $expireDays]
            )

            ->tap($applyClientScope)
            ->selectRaw(
                "'expiring' as state,
                'exam' as kind,
                x.id as id,
                x.employee_id as employee_id,
                e.id_cliente as client_id,
                TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                e.foto,
                CONCAT(
                    CASE
                        WHEN x.id_candidato IS NOT NULL THEN x.name
                        WHEN x.id_opcion IS NOT NULL THEN opt.name
                        ELSE x.nameDocument
                    END,
                    ' - Exa'
                ) as title,
                CONCAT(
                    CASE
                        WHEN x.id_candidato IS NOT NULL THEN x.name
                        WHEN x.id_opcion IS NOT NULL THEN opt.name
                        ELSE x.nameDocument
                    END,
                    ' - Exa'
                ) as doc_name,
                x.expiry_date as expiry_date,
                DATEDIFF(x.expiry_date, ?) as in_days,
                COALESCE(NULLIF(x.expiry_reminder,0), ?) as remind_days,
                NULL as overdue_days",
                [$todayStr, $expireDays]
            );

        $expUnion = $docsExp->unionAll($coursesExp)->unionAll($examsExp);

        $expCount = $db->query()->fromSub($expUnion, 'u')->count();
        $expItems = $db->query()->fromSub($expUnion, 'u')
            ->orderBy('expiry_date')
            ->limit(50)
            ->get()
            ->map(fn($r) => (array) $r)
            ->all();

        // ==================================================
        // EXPIRED (ya vencidos)
        // ==================================================
        $docsOld = $db->table('documents_empleado as d')
            ->join('empleados as e', 'e.id', '=', 'd.employee_id')
            ->leftJoin('document_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'd.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })

            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->where('d.status', 1)
            ->whereNotNull('d.expiry_date')
            ->whereNotNull('d.expiry_reminder')
            ->where('d.expiry_reminder', '>', 0)
            ->whereRaw("DATEDIFF(?, d.expiry_date) BETWEEN 1 AND ?", [$todayStr, $expiredDays])
            ->tap($applyClientScope)
            ->selectRaw(
                "'expired' as state,'document' as kind,d.id as id,d.employee_id as employee_id,
                 e.id_cliente as client_id,
                 TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                   e.foto,
              CONCAT(
                    CASE
                        WHEN d.id_opcion IS NOT NULL THEN opt.name
                        ELSE d.nameDocument
                    END,
                    ' - Doc'
                ) as title,

                CONCAT(
                    CASE
                        WHEN d.id_opcion IS NOT NULL THEN opt.name
                        ELSE d.nameDocument
                    END,
                    ' - Doc'
                ) as doc_name,

                 d.expiry_date as expiry_date,
                 -DATEDIFF(?, d.expiry_date) as in_days,
                 NULL as remind_days,
                 DATEDIFF(?, d.expiry_date) as overdue_days",
                [$todayStr, $todayStr]
            );

        $coursesOld = $db->table('cursos_empleados as c')
            ->join('empleados as e', 'e.id', '=', 'c.employee_id')
            ->leftJoin('cursos_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'c.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->whereNotNull('c.expiry_date')
            ->whereNotNull('c.expiry_reminder')
            ->where('c.expiry_reminder', '>', 0)
            ->whereRaw("DATEDIFF(?, c.expiry_date) BETWEEN 1 AND ?", [$todayStr, $expiredDays])
            ->tap($applyClientScope)
            ->selectRaw(
                "'expired' as state,'course' as kind,c.id as id,c.employee_id as employee_id,
                 e.id_cliente as client_id,
                 TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                   e.foto,
               CONCAT(
                        CASE
                            WHEN c.id_opcion IS NOT NULL THEN opt.name
                            ELSE c.nameDocument
                        END,
                        ' - Curso'
                    ) as title,

                    CONCAT(
                        CASE
                            WHEN c.id_opcion IS NOT NULL THEN opt.name
                            ELSE c.nameDocument
                        END,
                        ' - Curso'
                    ) as doc_name,

                 c.expiry_date as expiry_date,
                 -DATEDIFF(?, c.expiry_date) as in_days,
                 NULL as remind_days,
                 DATEDIFF(?, c.expiry_date) as overdue_days",
                [$todayStr, $todayStr]
            );

        $examsOld = $db->table('exams_empleados as x')
            ->join('empleados as e', 'e.id', '=', 'x.employee_id')
            ->leftJoin('exams_options as opt', function ($join) use ($portalId) {
                $join->on('opt.id', '=', 'x.id_opcion')
                    ->where(function ($q) use ($portalId) {
                        $q->where('opt.id_portal', $portalId)
                            ->orWhereNull('opt.id_portal');
                    });
            })
            ->where('e.id_portal', $portalId)
            ->where('e.eliminado', 0)->where('e.status', 1)
            ->whereNotNull('x.expiry_date')
            ->whereNotNull('x.expiry_reminder')
            ->where('x.expiry_reminder', '>', 0)
            ->whereRaw("DATEDIFF(?, x.expiry_date) BETWEEN 1 AND ?", [$todayStr, $expiredDays])
            ->tap($applyClientScope)
            ->selectRaw(
                "'expired' as state,'exam' as kind,x.id as id,x.employee_id as employee_id,
                 e.id_cliente as client_id,
                 TRIM(CONCAT_WS(' ',e.nombre,e.paterno,e.materno)) as employee_name,
                   e.foto,
                        CONCAT(
                            CASE
                                WHEN x.id_candidato IS NOT NULL THEN x.name
                                WHEN x.id_opcion IS NOT NULL THEN opt.name
                                ELSE x.nameDocument
                            END,
                            ' - Exa'
                        ) as title,

                        CONCAT(
                            CASE
                                WHEN x.id_candidato IS NOT NULL THEN x.name
                                WHEN x.id_opcion IS NOT NULL THEN opt.name
                                ELSE x.nameDocument
                            END,
                            ' - Exa'
                        ) as doc_name,


                 x.expiry_date as expiry_date,
                 -DATEDIFF(?, x.expiry_date) as in_days,
                 NULL as remind_days,
                 DATEDIFF(?, x.expiry_date) as overdue_days",
                [$todayStr, $todayStr]
            );

        $oldUnion = $docsOld->unionAll($coursesOld)->unionAll($examsOld);

        $oldCount = $db->query()->fromSub($oldUnion, 'u')->count();
        $oldItems = $db->query()->fromSub($oldUnion, 'u')
            ->orderByDesc('expiry_date') // más recientes vencidos primero
            ->limit(50)
            ->get()
            ->map(fn($r) => (array) $r)
            ->all();

        return [
            'expiring_count' => (int) $expCount,
            'expiring_items' => $expItems,
            'expired_count'  => (int) $oldCount,
            'expired_items'  => $oldItems,
        ];
    }
}
