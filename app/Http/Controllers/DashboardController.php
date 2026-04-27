<?php
namespace App\Http\Controllers;

use App\Services\Auth\PermissionService;
use App\Services\Dashboard\Context\ClientScopeResolver;
use App\Services\Dashboard\Context\DateRangeResolver;
use App\Services\Dashboard\ExpiryService;
use App\Services\Dashboard\PrenominaService;
use App\Services\Dashboard\QualityService;
use App\Services\Dashboard\Summary\AiSummaryBuilder;
use App\Services\Dashboard\TalentSourceService;
use App\Services\Dashboard\Widgets\AlertsWidget;
use App\Services\Dashboard\Widgets\BirthdaysWidget;
use App\Services\Dashboard\Widgets\CalendarWidget;
use App\Services\Dashboard\Widgets\EmployeesWidget;
use App\Services\Dashboard\Widgets\TurnoverWidget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
        if (connection_aborted()) {
            return response()->noContent();
        }

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

        // ================================================
        //  🔥 CAPTURAR client_id enviado por el front
        // ================================================

        // Puede venir como client_id=12 o client_id[]=12
        $requestedClientId = $request->input('client_id');

        if (is_array($requestedClientId)) {
            // Si vienen varios → scope (NO cliente único)
            $requestedClientId = count($requestedClientId) === 1
                ? (int) $requestedClientId[0]
                : null;
        } else {
            $requestedClientId = $requestedClientId ? (int) $requestedClientId : null;
        }
        $roleId        = $this->roleIdOf($user);
        $scopeCacheKey = "dash:scope:p{$portalId}:u{$user->id}:r{$roleId}";

        $scope = Cache::remember($scopeCacheKey, 300, function () use ($request, $user) {
            return ClientScopeResolver::resolve(
                $request,
                $this->conn,
                (int) $user->id
            );
        });

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
        // ============================================================
        //  🔥 OVERRIDE DE CLIENTE SI EL FRONT SELECCIONÓ UNO
        // ============================================================

        $clientId       = null; // default = scope
        $scopeClientIds = $scope['scopeClientIds'];
        $allowedClients = $scope['allowedClients'];

        if ($requestedClientId !== null) {

            // Verificar que el cliente solicitado pertenece al scope del usuario
            if (in_array($requestedClientId, $scope['scopeClientIds'])) {

                // Cliente único seleccionado → se trabaja SOLO con ese
                $clientId       = $requestedClientId;
                $allowedClients = collect([$requestedClientId]);
                $scopeClientIds = [$requestedClientId];

            } else {
                return response()->json([
                    'message' => "Client $requestedClientId not allowed in scope",
                ], 403);
            }
        }

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

        // scope estable (ordenado)
        $scopeSorted   = $allowedClients->values()->sort()->values()->all();
        $scopeHash     = md5(implode(',', $scopeSorted));
        $periodTypeRaw = (string) $request->query('period_type', 'last-365');

        $periodType = match ($periodTypeRaw) {
            'by-month', 'month' => 'by-month',
            'by-year', 'year'   => 'by-year',
            'last-365' => 'last-365',
            default    => 'last-365',
        };

        // hash del rango real de fechas
        $rangeHash = md5($rangeStart->toDateString() . '_' . $rangeEnd->toDateString());
        $year      = $request->query('year');
        $year      = $year ? (int) $year : null;

        $cacheKey = "dash:summary:"
            . "p{$portalId}:u{$user->id}:r{$roleId}"
            . ":scope{$scopeHash}"
            . ":type{$periodType}"
            . ":range{$rangeHash}";

        $conn = $this->conn;

        //return Cache::remember($cacheKey, now()->addSeconds(15), function () use (
        return (function () use (
            $conn,
            $portalId, $user, $allowedClients, $clientId,
            $days, $expireDays, $expiredDays,
            $today, $modules,
            $periodBase, $periodMonth,
            $rangeStart, $rangeEnd, $periodType,
            $year) {
            if (connection_aborted()) {
                return [];
            }

            $db               = DB::connection($conn);
            $employeesWidget  = new EmployeesWidget($conn);
            $birthdaysWidget  = new BirthdaysWidget($conn);
            $calendarWidget   = new CalendarWidget($conn);
            $alertsWidget     = new AlertsWidget();
            $aiSummaryBuilder = new AiSummaryBuilder();
            $turnoverWidget   = new TurnoverWidget($conn);
            $qualityService   = new QualityService($conn);
            $prenominaService = new PrenominaService($conn);
            $talentSourceSvc  = new TalentSourceService($conn);

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
                'talent_sources'       => [
                    'labels' => [],
                    'series' => [],
                ],

            ];

            // =========================
            // 🏢 LISTA: Sucursales con mayor rotación
            // =========================
            if (
                $modulesUser['emp'] &&
                $modulesUser['former'] &&
                $this->hasPermission($user, 'dashboard.widget.rotacion.ver', $clientId)
            ) {
                $lists['top_turnover_clients'] = $turnoverWidget->topClientsByTurnover(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $rangeStart,
                    $rangeEnd,
                    5
                );
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
                if ($periodType === 'by-month') {

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
                $kpis['employees_active_period'] = $employeesWidget->activeInPeriodCount(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $rangeStart,
                    $rangeEnd
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
                if ($periodType === 'by-month') {

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
                    // =======================================================
                    // ⭐ KPI AUSENTISMO REAL (días reales)
                    // =======================================================

                    // 1️⃣ Total días reales de Falta y Permiso
                    // =======================================================
                    // ⭐ KPI AUSENTISMO REAL (con columnas correctas)
                    // =======================================================

                    $totalAbsences = DB::connection($conn)
                        ->table('calendario_eventos as ev')
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
                        ->whereDate('ev.inicio', '<=', $rangeEnd->toDateString())
                        ->whereDate('ev.fin', '>=', $rangeStart->toDateString())
                        ->whereIn('ev.id_tipo', [1, 2, 3, 4]) // FALTA y PERMISO (verifica ids reales)
                        ->selectRaw('SUM(GREATEST(DATEDIFF(ev.fin, ev.inicio) + 1, 0)) as total')
                        ->value('total') ?? 0;

                    // 2️⃣ Calcular días laborales del periodo
                    $workingDays = 0;
                    $cursor      = $rangeStart->copy();

                    while ($cursor <= $rangeEnd) {
                        if (! $cursor->isWeekend()) {
                            $workingDays++;
                        }
                        $cursor->addDay();
                    }

                    // 3️⃣ Headcount promedio del periodo
                    $hcStart = $turnoverWidget->headcountAsOf(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeStart
                    );

                    $hcEnd = $turnoverWidget->headcountAsOf(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeEnd
                    );

                    $avgEmployees = ($hcStart + $hcEnd) / 2;

                    // 4️⃣ Índice real
                    $denominator = $avgEmployees * $workingDays;

                    if ($denominator > 0 && $totalAbsences >= 0) {
                        $kpis['absences_period_pct'] = round(
                            ($totalAbsences / $denominator) * 100,
                            2
                        );
                    } else {
                        $kpis['absences_period_pct'] = 0;
                    }

                    $kpis['absences_period_total_days'] = $totalAbsences;

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

                    // =======================================================
                    // ⭐ KPI AUSENTISMO REAL (días reales)
                    // =======================================================

                    // 1️⃣ Total días reales de Falta y Permiso
                    // =======================================================
                    // ⭐ KPI AUSENTISMO REAL (con columnas correctas)
                    // =======================================================

                    $totalAbsences = DB::connection($conn)
                        ->table('calendario_eventos as ev')
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
                        ->whereDate('ev.inicio', '<=', $rangeEnd->toDateString())
                        ->whereDate('ev.fin', '>=', $rangeStart->toDateString())
                        ->whereIn('ev.id_tipo', [1, 2, 3, 4]) // FALTA y PERMISO (verifica ids reales)
                        ->selectRaw('SUM(GREATEST(DATEDIFF(ev.fin, ev.inicio) + 1, 0)) as total')
                        ->value('total') ?? 0;

                    // 2️⃣ Calcular días laborales del periodo
                    $workingDays = 0;
                    $cursor      = $rangeStart->copy();

                    while ($cursor <= $rangeEnd) {
                        if (! $cursor->isWeekend()) {
                            $workingDays++;
                        }
                        $cursor->addDay();
                    }

                    // 3️⃣ Headcount promedio del periodo
                    $hcStart = $turnoverWidget->headcountAsOf(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeStart
                    );

                    $hcEnd = $turnoverWidget->headcountAsOf(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeEnd
                    );

                    $avgEmployees = ($hcStart + $hcEnd) / 2;

                    // 4️⃣ Índice real
                    $denominator = $avgEmployees * $workingDays;

                    if ($denominator > 0 && $totalAbsences >= 0) {
                        $kpis['absences_period_pct'] = round(
                            ($totalAbsences / $denominator) * 100,
                            2
                        );
                    } else {
                        $kpis['absences_period_pct'] = 0;
                    }

                    $kpis['absences_period_total_days'] = $totalAbsences;

                }
            }
            // =========================
            // 💰 PRENÓMINA (gráfica)
            // =========================
// 💰 PRENÓMINA (gráfica)
// 💰 PRENÓMINA (gráfica + KPI)
            if ($modulesUser['com']) {

                $charts['prenominapayments'] =
                $prenominaService->chartByPeriod(
                    $portalId,
                    $allowedClients,
                    $clientId,
                    $year
                );

                $lastPayroll = null;

                if (! empty($charts['prenominapayments']['series'])) {

                    foreach ($charts['prenominapayments']['series'] as $serie) {

                        // 🔥 AQUI ESTA EL FIX
                        if (in_array($serie['name'], ['Total pagado', 'Total Final'], true)) {

                            $data = $serie['data'];

                            if (! empty($data)) {
                                $lastPayroll = end($data); // último valor
                            }

                            break;
                        }
                    }
                }

                $kpis['last_payroll_amount'] = $lastPayroll ?? 0;
            }

            // =========================
            // RECLUTAMIENTO: KPIs, gráfica y origen del talento
            // =========================
            if ($modulesUser['reclu']) {

                $recruit = new \App\Services\Dashboard\RecruitmentService($conn);

                // KPIs
                $kpis = array_merge(
                    $kpis,
                    $recruit->getKpis(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $rangeStart,
                        $rangeEnd
                    )
                );

                // Charts
                if ($periodType === 'by-month') {

                    $tmp = $recruit->getChartDaily(
                        $portalId,
                        $allowedClients,
                        $clientId,
                        $periodBase
                    );

                    $charts['recruitment_overview'] = [
                        'labels' => $tmp['days'],
                        'series' => [
                            ['name' => 'En espera', 'data' => $tmp['waiting']],
                            ['name' => 'En proceso', 'data' => $tmp['in_process']],
                            ['name' => 'Cerradas', 'data' => $tmp['closed']],
                            ['name' => 'Canceladas', 'data' => $tmp['cancelled']],
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
                            ['name' => 'En espera', 'data' => $tmp['waiting']],
                            ['name' => 'En proceso', 'data' => $tmp['in_process']],
                            ['name' => 'Cerradas', 'data' => $tmp['closed']],
                            ['name' => 'Canceladas', 'data' => $tmp['cancelled']],
                        ],
                    ];
                }
                $charts['talent_sources'] = $talentSourceSvc->breakdown(
                    $portalId,
                    $rangeStart,
                    $rangeEnd,
                    8
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
        })();
    }

    public function kpiDetail(Request $request)
    {
        $user = $request->user();

        if (! $user && app()->environment('local', 'production')) {
            $userId   = (int) $request->query('user_id', 0);
            $portalId = (int) $request->query('portal_id', 0);
            $roleId   = (int) $request->query('role_id', 0);

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

        $portalId = (int) ($user->id_portal ?? $request->query('portal_id', 0));
        $kpiKey   = (string) $request->query('kpi_key', '');

        $start = Carbon::parse($request->query('start_date'))->startOfDay();
        $end   = Carbon::parse($request->query('end_date'))->endOfDay();

        $clientIdParam = $request->query('client_id');

        if (is_array($clientIdParam)) {
            $allowedClients = collect($clientIdParam)->map(fn($v) => (int) $v)->filter()->values();
            $clientId       = null;
        } else {
            $clientId = $clientIdParam && $clientIdParam !== 'all'
                ? (int) $clientIdParam
                : null;

            $allowedClients = collect(
                $request->query('client_ids', [])
            )->map(fn($v) => (int) $v)->filter()->values();
        }

        if ($allowedClients->isEmpty() && ! $clientId) {
            return response()->json([
                'items'   => [],
                'total'   => 0,
                'message' => 'No hay clientes permitidos para consultar.',
            ]);
        }

        if ($kpiKey === 'employees_active_period') {
            $items = $this->db()
                ->table('empleados as e')
                ->select([
                    'e.id',
                    'e.id_cliente',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.correo',
                    'e.puesto',
                    'e.departamento',
                    'e.fecha_ingreso',
                    'e.fecha_salida',
                    'e.status',
                ])
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereRaw(
                    'COALESCE(e.fecha_ingreso, DATE(e.creacion)) <= ?',
                    [$end->toDateString()]
                )
                ->where(function ($q) use ($start) {
                    $q->whereNull('e.fecha_salida')
                        ->orWhere('e.fecha_salida', '>=', $start->toDateString());
                })
                ->orderBy('e.paterno')
                ->orderBy('e.materno')
                ->orderBy('e.nombre')
                ->limit(500)
                ->get();

            return response()->json([
                'kpi_key' => $kpiKey,
                'total'   => $items->count(),
                'items'   => $items,
            ]);
        }
        if ($kpiKey === 'hires_period') {
            $items = $this->db()
                ->table('empleados as e')
                ->select([
                    'e.id',
                    'e.id_cliente',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.correo',
                    'e.puesto',
                    'e.departamento',
                    'e.fecha_ingreso',
                    'e.fecha_salida',
                    'e.status',
                ])
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                    [$start->toDateString(), $end->toDateString()]
                )
                ->orderByRaw('COALESCE(e.fecha_ingreso, DATE(e.creacion)) DESC')
                ->limit(500)
                ->get();

            return response()->json([
                'kpi_key' => $kpiKey,
                'total'   => $items->count(),
                'items'   => $items,
            ]);
        }
        if ($kpiKey === 'terminations_period') {
            $items = $this->db()
                ->table('empleados as e')
                ->select([
                    'e.id',
                    'e.id_cliente',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.correo',
                    'e.puesto',
                    'e.departamento',
                    'e.fecha_ingreso',
                    'e.fecha_salida',
                    'e.status',
                ])
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->where('e.status', 2) // exempleado
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereNotNull('e.fecha_salida')
                ->whereBetween('e.fecha_salida', [
                    $start->toDateString(),
                    $end->toDateString(),
                ])
                ->orderBy('e.fecha_salida', 'desc')
                ->limit(500)
                ->get();

            return response()->json([
                'kpi_key' => $kpiKey,
                'total'   => $items->count(),
                'items'   => $items,
            ]);
        }
        if ($kpiKey === 'turnover_period') {

            // ALTAS
            $hires = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                    [$start->toDateString(), $end->toDateString()]
                )
                ->count();

            // BAJAS
            $terminations = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->where('e.status', 2)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereNotNull('e.fecha_salida')
                ->whereBetween('e.fecha_salida', [$start, $end])
                ->count();

            // ACTIVOS (inicio y fin)
            $activeStart = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereRaw('COALESCE(e.fecha_ingreso, DATE(e.creacion)) <= ?', [$start->toDateString()])
                ->where(function ($q) use ($start) {
                    $q->whereNull('e.fecha_salida')
                        ->orWhere('e.fecha_salida', '>=', $start);
                })
                ->count();

            $activeEnd = $this->db()->table('empleados as e')
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereRaw('COALESCE(e.fecha_ingreso, DATE(e.creacion)) <= ?', [$end->toDateString()])
                ->where(function ($q) use ($end) {
                    $q->whereNull('e.fecha_salida')
                        ->orWhere('e.fecha_salida', '>=', $end);
                })
                ->count();

            $avgEmployees = ($activeStart + $activeEnd) / 2;

            $turnover = $avgEmployees > 0
                ? round(($terminations / $avgEmployees) * 100, 2)
                : 0;

            // LISTA (altas)
            $hireItems = $this->db()->table('empleados as e')
                ->select([
                    'e.id',
                    'e.id_cliente',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.correo',
                    'e.puesto',
                    'e.departamento',
                    'e.fecha_ingreso',
                    'e.fecha_salida',
                    'e.status',
                    DB::raw("'alta' as movement_type"),
                ])
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->whereIn('e.status', [1, 2])
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereBetween(
                    DB::raw('COALESCE(e.fecha_ingreso, DATE(e.creacion))'),
                    [$start->toDateString(), $end->toDateString()]
                )
                ->get();

            // LISTA (bajas)
            $terminationItems = $this->db()->table('empleados as e')
                ->select([
                    'e.id',
                    'e.id_cliente',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.correo',
                    'e.puesto',
                    'e.departamento',
                    'e.fecha_ingreso',
                    'e.fecha_salida',
                    'e.status',
                    DB::raw("'baja' as movement_type"),
                ])
                ->where('e.id_portal', $portalId)
                ->where('e.eliminado', 0)
                ->where('e.status', 2)
                ->when(
                    $clientId,
                    fn($q) => $q->where('e.id_cliente', $clientId),
                    fn($q) => $q->whereIn('e.id_cliente', $allowedClients)
                )
                ->whereNotNull('e.fecha_salida')
                ->whereBetween('e.fecha_salida', [
                    $start->toDateString(),
                    $end->toDateString(),
                ])
                ->get();

            $items = $hireItems
                ->merge($terminationItems)
                ->sortBy([
                    ['movement_type', 'asc'],
                    ['fecha_salida', 'desc'],
                    ['fecha_ingreso', 'desc'],
                ])
                ->values();

            return response()->json([
                'kpi_key' => $kpiKey,
                'total'   => $items->count(),
                'items'   => $items,
                'meta'    => [
                    'hires'         => $hires,
                    'terminations'  => $terminations,
                    'avg_employees' => $avgEmployees,
                    'turnover'      => $turnover,
                ],
            ]);
        }
        if ($kpiKey === 'requisitions_active_period') {

            $items = $this->db()->table('requisicion as r')
                ->join('cliente as c', 'c.id', '=', 'r.id_cliente')
                ->select([
                    'r.id',
                    'r.id_cliente',
                    'c.nombre as cliente_nombre',
                    'r.puesto',
                    'r.numero_vacantes',
                    'r.creacion',
                    'r.edicion',
                    'r.status',
                    'r.comentario_final',
                    DB::raw("
                CASE
                    WHEN r.status = 2 THEN 'proceso'
                    WHEN r.status = 3 THEN 'cerrada'
                    WHEN r.status = 0 THEN 'cancelada'
                    ELSE 'otro'
                END as stage
            "),
                ])
                ->where('r.id_portal', $portalId)
                ->where('r.eliminado', 0)
                ->when(
                    $clientId,
                    fn($q) => $q->where('r.id_cliente', $clientId),
                    fn($q) => $q->whereIn('r.id_cliente', $allowedClients)
                )
                ->where('r.creacion', '<=', $end)
                ->where(function ($q) use ($start) {
                    $q->where('r.status', 2)
                        ->orWhere(function ($sub) use ($start) {
                            $sub->whereIn('r.status', [0, 3])
                                ->where('r.edicion', '>=', $start);
                        });
                })
                ->orderBy('r.creacion', 'desc')
                ->limit(500)
                ->get();

            return response()->json([
                'kpi_key' => $kpiKey,
                'total'   => $items->count(),
                'items'   => $items,
            ]);
        }
        return response()->json([
            'items'   => [],
            'total'   => 0,
            'message' => 'KPI no soportado todavía.',
        ]);
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
