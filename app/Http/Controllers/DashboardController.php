<?php
namespace App\Http\Controllers;

use App\Services\Auth\PermissionService;
use App\Services\Dashboard\Context\ClientScopeResolver;
use App\Services\Dashboard\Context\DateRangeResolver;
use App\Services\Dashboard\ExpiryService;
use App\Services\Dashboard\PrenominaService;
use App\Services\Dashboard\QualityService;
use App\Services\Dashboard\Summary\AiSummaryBuilder;
use App\Services\Dashboard\Widgets\AlertsWidget;
use App\Services\Dashboard\Widgets\BirthdaysWidget;
use App\Services\Dashboard\Widgets\CalendarWidget;
use App\Services\Dashboard\Widgets\EmployeesWidget;
use App\Services\Dashboard\Widgets\TurnoverWidget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * ✅ Conexión donde viven TODAS estas tablas:
     * portal, empleados, usuario_permiso, calendario_eventos,
     * documents_empleado, comentarios_former_empleado, etc.
     */
    private string $conn = 'portal_main';

    private ?PermissionService $permSvc = null;

    private function db()
    {
        return DB::connection($this->conn);
    }

    private function perm(): PermissionService
    {
        return $this->permSvc ??= new PermissionService($this->conn);
    }

    /**
     * GET /api/dashboard/summary?client_id=all|123&days=14&expire_days=30&expired_days=365
     * En LOCAL (sin token) soporta:
     * /api/dashboard/summary?portal_id=1&user_id=999&role_id=1&client_id=all
     */
    public function summary(Request $request)
    {
        
        // =========================================
        // 1) Resolver usuario (Sanctum o modo local)
        // =========================================
        $user = $request->user();

        if (! $user && app()->environment('local', 'production')) {
            $userId   = (int) $request->query('user_id', 0);
            $portalId = (int) $request->query('portal_id', 0);
            $roleId   = (int) $request->query('role_id', 0); // o idRol

            if ($userId > 0 && $portalId > 0) {
                $fakeUser = (object) [
                    'id'        => $userId,
                    'id_portal' => $portalId,
                    'id_rol'    => $roleId,
                ];
                $request->setUserResolver(fn() => $fakeUser);
                $user = $fakeUser;
            }
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // =========================================
        // 2) PortalId (del user o header opcional)
        // =========================================
        $portalId = (int) ($user->id_portal ?? 0);

        if ($portalId <= 0) {
            $portalId = (int) ($request->header('X-Portal-Id') ?? 0);
        }

        if ($portalId <= 0) {
            return response()->json(['message' => 'Missing portal context (user.id_portal or X-Portal-Id)'], 422);
        }

        $today = Carbon::today();

        $periodMonth = (string) $request->query('period_month', $today->format('Y-m'));

        try {
            $periodBase = Carbon::createFromFormat('Y-m', $periodMonth)->startOfMonth();
        } catch (\Throwable $e) {
            $periodBase = $today->copy()->startOfMonth();
        }
        [$rangeStart, $rangeEnd] = DateRangeResolver::resolve($request);

        $scope = ClientScopeResolver::resolve(
            $request,
            $this->conn,
            (int) $user->id
        );

        if (! $scope['hasClients']) {
            return response()->json([
                'meta'    => ['portal_id' => $portalId, 'client_id' => 'all'],
                'kpis'    => [],
                'lists'   => [],
                'charts'  => [],
                'message' => 'User has no clients assigned or invalid scope',
            ]);
        }

        $allowedClients = $scope['allowedClients'];
        $clientId       = $scope['clientId']; // siempre null (whereIn)
        $scopeClientIds = $scope['scopeClientIds'];

        // =========================================
        // 3) Permiso base (MVP)
        // =========================================
        if (! $this->hasPermission($user, 'dashboard.ver', $clientId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // =========================================
        // 6) Parámetros
        // =========================================
        $days        = max(1, (int) $request->query('days', 14));
        $expireDays  = max(1, (int) $request->query('expire_days', 30));
        $expiredDays = max(1, (int) $request->query('expired_days', 365)); // ✅ vencidos hacia atrás

        // =========================================
        // 7) Flags de módulos por portal
        // =========================================
        $portal = $this->db()->table('portal')
            ->select('id', 'reclu', 'pre', 'emp', 'former', 'com', 'com360')
            ->where('id', $portalId)
            ->first();

        if (! $portal) {
            return response()->json(['message' => 'Portal not found'], 404);
        }

        $modules = [
            'reclu'  => (int) $portal->reclu === 1,
            'pre'    => (int) $portal->pre === 1,
            'emp'    => (int) $portal->emp === 1,
            'former' => (int) $portal->former === 1,
            'com'    => (int) $portal->com === 1,
            'com360' => (int) $portal->com360 === 1,
        ];

        // =========================================
        // 8) Cache 60s (incluye rol)
        // =========================================
        $roleId = $this->roleIdOf($user);

        // scope estable (ordenado)
        $scopeSorted   = $allowedClients->values()->sort()->values()->all();
        $scopeHash     = md5(implode(',', $scopeSorted));
        $periodTypeRaw = (string) $request->query('period_type', 'month');

        $periodType = match ($periodTypeRaw) {
            'by-month', 'month' => 'month',
            'last-365' => 'last-365',
            default    => 'month',
        };

        // hash del rango real de fechas
        $rangeHash = md5($rangeStart->toDateString() . '_' . $rangeEnd->toDateString());
        $year      = $request->query('year');
        $year      = $year ? (int) $year : null;

        $cacheKey = "dash:summary:"
            . "p{$portalId}:u{$user->id}:r{$roleId}:scope{$scopeHash}"
            . ":type{$periodType}"
            . ":range{$rangeHash}"
            . ":year" . ($year ?? 'last365')
            . ":d{$days}:e{$expireDays}:x{$expiredDays}";

        $conn = $this->conn;

        return Cache::remember($cacheKey, now()->addSeconds(60), function () use (
            $conn,
            $portalId, $user, $allowedClients, $clientId,
            $days, $expireDays, $expiredDays,
            $today, $modules,
            $periodBase, $periodMonth,
            $rangeStart, $rangeEnd, $periodType,
            $year) {

            $db               = DB::connection($conn);
            $employeesWidget  = new EmployeesWidget($conn);
            $birthdaysWidget  = new BirthdaysWidget($conn);
            $calendarWidget   = new CalendarWidget($conn);
            $alertsWidget     = new AlertsWidget();
            $aiSummaryBuilder = new AiSummaryBuilder();
            $turnoverWidget   = new TurnoverWidget($conn);
            $qualityService   = new QualityService($conn);
            $prenominaService = new PrenominaService($conn);

            // =========================
            // Módulos efectivos por usuario (portal ON + permiso)
            // =========================
            $modulesUser = [
                'reclu'  => $modules['reclu'] && $this->hasPermission($user, 'module.reclutamiento.ver', $clientId),
                'pre'    => $modules['pre'] && $this->hasPermission($user, 'module.pre_empleo.ver', $clientId),
                'emp'    => $modules['emp'] && $this->hasPermission($user, 'module.empleados.ver', $clientId),
                'former' => $modules['former'] && $this->hasPermission($user, 'module.ex_empleados.ver', $clientId),
                'com'    => $modules['com'] && $this->hasPermission($user, 'module.comunicacion.ver', $clientId),
                'com360' => $modules['com360'] && $this->hasPermission($user, 'module.comunicacion360.ver', $clientId),
            ];
            // =====================================================
            // ⭐ RECLUTAMIENTO: Servicio KPIs + gráfica 6 meses
            // =====================================================
            /* ============================
            0) INICIALIZAR estructuras
            ============================ */
            $kpis   = [];
            $lists  = [];
            $charts = [
                // 🔁 ROTACIÓN
                'turnover'             => [
                    'labels' => [],
                    'series' => [],
                ],

                // 🔁 RECLUTAMIENTO
                'recruitment_overview' => [
                    'months'     => [],
                    'created'    => [],
                    'closed'     => [],
                    'in_process' => [],
                ],

                // 📅 INCIDENCIAS
                'incidences'           => [
                    'months' => [],
                    'series' => [],
                ],
                'prenominapayments'    => [
                    'labels' => [],
                    'series' => [],
                ],
            ];

            /* =====================================================
                    ⭐ 1) RECLUTAMIENTO – Servicio KPIs + Chart
                ===================================================== */

            $recruit = new \App\Services\Dashboard\RecruitmentService($conn);

            // KPIs Reclutamiento
            $kpis = array_merge(
                $kpis,
                $recruit->getKpis($portalId, $allowedClients, $clientId, $rangeStart, $rangeEnd)
            );

            // 🟡 Reclutamiento: mantener lógica actual por ahora
            if ($periodType === 'month') {

                $tmp = $recruit->getChartDaily(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $periodBase
                );

                $charts['recruitment_overview'] = [
                    'labels' => $tmp['days'],
                    'series' => [
                        ['name' => 'Creadas', 'data' => $tmp['created']],
                        ['name' => 'Cerradas', 'data' => $tmp['closed']],
                    ],
                ];

            } else {

                $tmp = $recruit->getChartByRange(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $rangeStart,
                    $rangeEnd
                );

                $charts['recruitment_overview'] = [
                    'labels' => $tmp['months'],
                    'series' => [
                        ['name' => 'Creadas', 'data' => $tmp['created']],
                        ['name' => 'Cerradas', 'data' => $tmp['closed']],
                    ],
                ];
            }

            // =========================
            // FORMER / ROTACIÓN (Widget)
            // =========================
            if (
                $modulesUser['emp'] &&
                $modulesUser['former'] &&
                $this->hasPermission($user, 'dashboard.widget.rotacion.ver', $clientId)
            ) {
                $kpis = array_merge(
                    $kpis,
                    $turnoverWidget->kpis(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeStart,
                        $rangeEnd
                    )
                );
                if ($periodType === 'month') {

                    $raw = $turnoverWidget->chartDaily(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $periodBase
                    );

                    $charts['turnover'] = [
                        'labels' => $raw['days'],
                        'series' => [
                            ['name' => 'Altas', 'data' => $raw['hires']],
                            ['name' => 'Bajas', 'data' => $raw['terminations']],
                            ['name' => 'Rotación %', 'data' => $raw['turnover_pct']],
                        ],
                    ];

                } else {

                    $raw = $turnoverWidget->chartLastYear(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeEnd
                    );

                    $charts['turnover'] = [
                        'labels' => $raw['months'],
                        'series' => [
                            ['name' => 'Altas', 'data' => $raw['hires']],
                            ['name' => 'Bajas', 'data' => $raw['terminations']],
                            ['name' => 'Rotación %', 'data' => $raw['turnover_pct']],
                        ],
                    ];
                }

            }

            $clientIds = $allowedClients->values()->all(); // scope actual (ej [12,7])

            $clientsMap = $db->table('cliente') // <-- AJUSTA si tu tabla se llama distinto
                ->select('id', 'nombre')            // <-- AJUSTA campo nombre (nombre/razon_social/etc)
                ->whereIn('id', $clientIds)
                ->orderBy('nombre')
                ->get()
                ->map(fn($r) => ['id' => (int) $r->id, 'name' => (string) $r->nombre])
                ->values()
                ->all();
            $meta = [
                'portal_id'         => $portalId,
                'client_id'         => null,
                'scope_client_ids'  => $allowedClients->values()->all(),
                'today'             => $today->toDateString(),
                'days'              => $days,
                'expire_days'       => $expireDays,
                'expired_days'      => $expiredDays,
                'modules'           => $modules,     // portal
                'modules_effective' => $modulesUser, // usuario
                'allowed_clients'   => $allowedClients->values()->all(),
                'clients'           => $clientsMap,
                'period_month'      => $periodMonth,
                'period_start'      => $rangeStart->toDateString(),
                'period_end'        => $rangeEnd->toDateString(),

                // ✅ para dropdown/validación front
            ];

            // =========================
            // EMPLEADOS (usa módulo efectivo)
            // =========================
            if ($modulesUser['emp'] && $this->hasPermission($user, 'dashboard.widget.empleados.ver', $clientId)) {

                $kpis['employees_active'] = $employeesWidget->activeCount(
                    $portalId,
                    $allowedClients,
                    $clientId
                );

                $kpis['hires_month'] = $employeesWidget->hiresInRange(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $rangeStart,
                    $rangeEnd
                );
                $quality = $qualityService->fetch(
                    $portalId,
                    $allowedClients->all(),
                    $clientId
                );

                $lists['quality_items'] = $quality['items'];

                $kpis['docs_regular_count'] = $quality['count_regular'];
                $kpis['docs_bad_count']     = $quality['count_bad'];

                // Cumpleaños
                if ($this->hasPermission($user, 'dashboard.widget.cumpleanos.ver', $clientId)) {
                    $lists['birthdays'] = $birthdaysWidget->upcoming(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $today,
                        $days
                    );
                }

                // =========================
                // VENCIMIENTOS usando SERVICIO
                // =========================
                if ($this->hasPermission($user, 'dashboard.widget.vencimientos.ver', $clientId)) {

                    $svc = new ExpiryService($this->conn);

                    $exp = $svc->fetch(
                        $portalId,
                        $allowedClients->all(),
                        $clientId,
                        $today,
                        $expireDays,
                        $expiredDays
                    );

                    // ✅ Por vencer (compat con front)
                    $lists['expiring_documents']      = $exp['expiring_items'] ?? [];
                    $kpis['expiring_documents_count'] = (int) ($exp['expiring_count'] ?? 0);

                    $lists['expiring_items']      = $lists['expiring_documents'];
                    $kpis['expiring_items_count'] = $kpis['expiring_documents_count'];

                    // ✅ Vencidos
                    $lists['expired_items']      = $exp['expired_items'] ?? [];
                    $kpis['expired_items_count'] = (int) ($exp['expired_count'] ?? 0);
                }
            }

            // =========================
            // CALENDARIO / EVENTOS (usa módulo efectivo)
            // =========================
            if ($modulesUser['com'] && $this->hasPermission($user, 'dashboard.widget.asistencias.ver', $clientId)) {

                // KPIs de hoy
                $kpis['vacations_today'] = $calendarWidget->countToday(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $today,
                    [1]// vacaciones
                );

                $kpis['absent_today'] = $calendarWidget->countToday(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $today,
                    [2, 3, 4]// incapacidad / permiso / falta
                );

                // =========================
                // 📊 INCIDENCIAS (gráfica)
                // =========================
                if ($periodType === 'month') {

                    $tmp = $calendarWidget->incidencesDaily(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $periodBase->copy()->startOfMonth(),
                        $periodBase->copy()->endOfMonth()
                    );

                    // 🔑 Normalizar a formato ApexCharts
                    $charts['incidences'] = [
                        'labels' => $tmp['days'] ?? [],
                        'series' => $tmp['series'] ?? [],
                    ];

                } else {

                    $tmp = $calendarWidget->incidencesByMonth(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeStart,
                        $rangeEnd
                    );

                    $charts['incidences'] = [
                        'labels' => $tmp['labels'] ?? [],
                        'series' => $tmp['series'] ?? [],
                    ];

                }
            }
            // =========================
            // 💰 PRENÓMINA (gráfica)
            // =========================
            if ($modulesUser['pre']) {

                $charts['prenominapayments'] =
                $prenominaService->chartByPeriod(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $year
                );
            }

            // =========================
            // IA (MVP)
            // =========================
            if ($this->hasPermission($user, 'dashboard.widget.ia_resumen.ver', $clientId)) {
                $lists['ai_summary'] = $aiSummaryBuilder->build($kpis);
            }

            // =========================
            // Alertas (sin nuevas queries)
            // =========================
            $lists['alerts'] = $alertsWidget->build(
                $kpis,
                $lists,
                $days,
                $expireDays,
                $expiredDays
            );
            // =========================
// NORMALIZAR INCIDENCIAS PARA APEXCHARTS
// =========================

            // =========================
            // NORMALIZAR ROTACIÓN PARA APEXCHARTS
            // =========================

            return response()->json([
                'meta'   => $meta,
                'kpis'   => $kpis,
                'lists'  => $lists,
                'charts' => $charts,
            ]);
        });
    }

    private function hasPermission($user, string $key, ?int $clientId = null): bool
    {
        return $this->can($user, $key, $clientId);
    }

    private function roleIdOf($user): int
    {
        // ✅ OJO: soporta fakeUser (id_rol) + modelos que traen id_rol/idRol/role_id
        return (int) ($user->id_rol ?? $user->id_rol ?? $user->idRol ?? $user->role_id ?? 0);
    }

    private function can($user, string $key, ?int $clientId = null): bool
    {
        return $this->perm()->can(
            (int) $user->id,
            $this->roleIdOf($user),
            $key,
            $clientId
        );
    }
}
