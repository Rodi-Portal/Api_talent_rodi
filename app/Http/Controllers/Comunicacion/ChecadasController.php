<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChecadasController extends Controller
{
    private string $CHECADAS_CONN  = 'portal_main'; // ajusta si la conexión se llama distinto
    private string $CHECADAS_TABLE = 'checadas';

    private function checadasQB()
    {
        return DB::connection($this->CHECADAS_CONN)->table($this->CHECADAS_TABLE);
    }

    /**
     * GET /checador/checadas
     * Lista plana de checadas.
     * Params:
     *  - id_portal (req)
     *  - id_cliente (opt: número, csv "6,70" o arreglo id_cliente[]=6&id_cliente[]=70)
     *  - id_empleado (opt: número, csv o arreglo con IDs PK de empleados)
     *  - from, to (opt; default últimos 7 días)
     *  - order=asc|desc (opt; default asc)
     *  - limit (opt; default 500, máx 5000)
     *  - with_empleado=true|false (opt; default true)
     *  - clase, tipo (opt; csv|array|valor único)  ← filtros por evento
     */
    public function listChecadas(Request $req)
    {
        $v = $req->validate([
            'id_portal'     => 'required|integer',
            'id_cliente'    => 'nullable',
            'id_empleado'   => 'nullable', // csv | array | int (PK empleados)
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'order'         => 'nullable|in:asc,desc',
            'limit'         => 'nullable|integer|min:1|max:5000',
            'with_empleado' => 'nullable|boolean',
            'clase'         => 'nullable',
            'tipo'          => 'nullable',
        ]);

        $portalId  = (int) $v['id_portal'];
        $clientIds = $this->normalizeIdClienteParam($req->input('id_cliente'));
        $order     = Arr::get($v, 'order', 'asc');
        $limit     = (int) Arr::get($v, 'limit', 500);
        $withEmp   = filter_var(Arr::get($v, 'with_empleado', true), FILTER_VALIDATE_BOOLEAN);

        [$from, $to] = $this->resolveDateRange(null, Arr::get($v, 'from'), Arr::get($v, 'to'));
        $empFilter   = $this->normalizeIdEmpleadoParam(Arr::get($v, 'id_empleado'));
        $clases      = $this->normalizeCsvParam($req->input('clase'));
        $tipos       = $this->normalizeCsvParam($req->input('tipo'));

        $rows = $this->checadasQB()
            ->select([
                'id', 'id_portal', 'id_cliente', 'id_empleado',
                'fecha', 'check_time', 'tipo', 'clase',
                'dispositivo', 'origen', 'hash', 'creado_en',
            ])
            ->where('id_portal', $portalId)
            ->when(!empty($clientIds), fn($q) => $q->whereIn('id_cliente', $clientIds))
            ->when(!empty($empFilter), fn($q) => $q->whereIn('id_empleado', $empFilter))
            ->when(!empty($clases),    fn($q) => $q->whereIn('clase', $clases))
            ->when(!empty($tipos),     fn($q) => $q->whereIn('tipo',  $tipos))
            ->whereBetween('check_time', [$from, $to])
            ->orderBy('check_time', $order)
            ->limit($limit)
            ->get();

        // Enriquecer con datos del empleado (opcional)
        $empMap = collect();
        if ($withEmp && $rows->count()) {
            $ids    = $rows->pluck('id_empleado')->unique()->values();
            $empMap = $this->cargarEmpleadosMap($portalId, $ids);
        }

        // 👇 id_empleado = número de empleado (catálogo). PK queda en id_empleado_pk
        $out = $rows->map(function ($r) use ($empMap) {
            $emp = $empMap->get((int) $r->id_empleado);
            return [
                'id'              => (int) $r->id,
                'id_portal'       => (int) $r->id_portal,
                'id_cliente'      => (int) $r->id_cliente,
                'id_empleado_pk'  => (int) $r->id_empleado,        // PK interno
                'id_empleado'     => $emp->numero ?? null,         // 👈 número del catálogo
                'empleado'        => $emp->nombre_completo ?? null,
                'cliente_nom'     => $emp->nombre_cliente ?? null,
                'fecha'           => $r->fecha,
                'check_time'      => $r->check_time,
                'tipo'            => $r->tipo,
                'clase'           => $r->clase,
                'dispositivo'     => $r->dispositivo,
                'origen'          => $r->origen,
            ];
        });

        // Conteo por sucursal para meta
        $countByCliente = $rows->groupBy('id_cliente')->map->count();

        return response()->json([
            'ok'   => true,
            'meta' => [
                'range'            => ['from' => $from, 'to' => $to],
                'order'            => $order,
                'rows'             => $rows->count(),
                'id_clientes'      => array_values($clientIds),
                'count_by_cliente' => (object) $countByCliente,
            ],
            'data' => $out,
        ]);
    }

    /**
     * GET /checador/checadas/rango
     * Agrupa checadas por día y por empleado dentro de un periodo.
     */
    public function checadasPorRango(Request $req)
    {
        $v = $req->validate([
            'id_portal'     => 'required|integer',
            'id_cliente'    => 'nullable',
            'id_empleado'   => 'nullable',
            'from'          => 'nullable|date',
            'to'            => 'nullable|date',
            'order'         => 'nullable|in:asc,desc',
            'with_empleado' => 'nullable|boolean',
            'clase'         => 'nullable',
            'tipo'          => 'nullable',
        ]);

        $portalId  = (int) $v['id_portal'];
        $clientIds = $this->normalizeIdClienteParam($req->input('id_cliente'));
        $order     = Arr::get($v, 'order', 'asc');
        $withEmp   = filter_var(Arr::get($v, 'with_empleado', true), FILTER_VALIDATE_BOOLEAN);

        [$from, $to] = $this->resolveDateRange(null, Arr::get($v, 'from'), Arr::get($v, 'to'));
        $empFilter   = $this->normalizeIdEmpleadoParam(Arr::get($v, 'id_empleado'));
        $clases      = $this->normalizeCsvParam($req->input('clase'));
        $tipos       = $this->normalizeCsvParam($req->input('tipo'));

        $rows = $this->checadasQB()
            ->where('id_portal', $portalId)
            ->when(!empty($clientIds), fn($q) => $q->whereIn('id_cliente', $clientIds))
            ->when(!empty($empFilter), fn($q) => $q->whereIn('id_empleado', $empFilter))
            ->when(!empty($clases),    fn($q) => $q->whereIn('clase', $clases))
            ->when(!empty($tipos),     fn($q) => $q->whereIn('tipo',  $tipos))
            ->whereBetween('check_time', [$from, $to])
            ->orderBy('check_time', $order)
            ->get();

        // Datos de empleados (opcional)
        $empMap = collect();
        if ($withEmp && $rows->count()) {
            $ids    = $rows->pluck('id_empleado')->unique()->values();
            $empMap = $this->cargarEmpleadosMap($portalId, $ids);
        }

        // Agrupar: día → empleados → items
        $grouped = [];
        foreach ($rows as $r) {
            $day = substr($r->check_time, 0, 10);
            $eid = (int) $r->id_empleado;

            $grouped[$day] ??= [];
            $grouped[$day][$eid] ??= [
                'id_empleado_pk' => $eid,                               // PK
                'id_empleado'    => $empMap[$eid]->numero ?? null,      // número catálogo
                'empleado'       => $empMap[$eid]->nombre_completo ?? null,
                'cliente_nom'    => $empMap[$eid]->nombre_cliente ?? null,
                'items'          => [],
            ];

            $grouped[$day][$eid]['items'][] = [
                'id'          => (int) $r->id,
                'check_time'  => $r->check_time,
                'tipo'        => $r->tipo,
                'clase'       => $r->clase,
                'dispositivo' => $r->dispositivo,
                'origen'      => $r->origen,
            ];
        }

        ksort($grouped);
        $out = [];
        foreach ($grouped as $day => $byEmp) {
            $byEmp = array_values($byEmp);
            usort($byEmp, fn($a, $b) => strcmp($a['empleado'] ?? '', $b['empleado'] ?? ''));
            $out[] = [
                'date'      => $day,
                'empleados' => $byEmp,
            ];
        }

        $countByCliente = $rows->groupBy('id_cliente')->map->count();

        return response()->json([
            'ok'   => true,
            'meta' => [
                'range'            => ['from' => $from, 'to' => $to],
                'order'            => $order,
                'days'             => count($out),
                'rows'             => $rows->count(),
                'id_clientes'      => array_values($clientIds),
                'count_by_cliente' => (object) $countByCliente,
            ],
            'data' => $out,
        ]);
    }

    /**
     * GET /checador/checadas/dia
     * Checadas de un día (00:00:00–23:59:59) agrupadas por empleado.
     * Devuelve navegación prev/next
     */
    public function checadasPorDia(Request $req)
    {
        $v = $req->validate([
            'id_portal'     => 'required|integer',
            'id_cliente'    => 'nullable',
            'id_empleado'   => 'nullable',
            'date'          => 'required|date',
            'order'         => 'nullable|in:asc,desc',
            'with_empleado' => 'nullable|boolean',
            'clase'         => 'nullable',
            'tipo'          => 'nullable',
        ]);

        $portalId  = (int) $v['id_portal'];
        $clientIds = $this->normalizeIdClienteParam($req->input('id_cliente'));
        $order     = Arr::get($v, 'order', 'asc');
        $withEmp   = filter_var(Arr::get($v, 'with_empleado', true), FILTER_VALIDATE_BOOLEAN);
        $dateStr   = $v['date'];

        [$from, $to] = $this->resolveDateRange($dateStr, null, null);
        $empFilter   = $this->normalizeIdEmpleadoParam(Arr::get($v, 'id_empleado'));
        $clases      = $this->normalizeCsvParam($req->input('clase'));
        $tipos       = $this->normalizeCsvParam($req->input('tipo'));

        $rows = $this->checadasQB()
            ->where('id_portal', $portalId)
            ->when(!empty($clientIds), fn($q) => $q->whereIn('id_cliente', $clientIds))
            ->when(!empty($empFilter), fn($q) => $q->whereIn('id_empleado', $empFilter))
            ->when(!empty($clases),    fn($q) => $q->whereIn('clase', $clases))
            ->when(!empty($tipos),     fn($q) => $q->whereIn('tipo',  $tipos))
            ->whereBetween('check_time', [$from, $to])
            ->orderBy('check_time', $order)
            ->get();

        // Datos de empleado (opcional)
        $empMap = collect();
        if ($withEmp && $rows->count()) {
            $ids    = $rows->pluck('id_empleado')->unique()->values();
            $empMap = $this->cargarEmpleadosMap($portalId, $ids);
        }

        // Agrupar por empleado
        $byEmp = [];
        foreach ($rows as $r) {
            $eid = (int) $r->id_empleado;
            $byEmp[$eid] ??= [
                'id_empleado_pk' => $eid,                              // PK
                'id_empleado'    => $empMap[$eid]->numero ?? null,     // número catálogo
                'empleado'       => $empMap[$eid]->nombre_completo ?? null,
                'cliente_nom'    => $empMap[$eid]->nombre_cliente ?? null,
                'items'          => [],
            ];
            $byEmp[$eid]['items'][] = [
                'id'          => (int) $r->id,
                'check_time'  => $r->check_time,
                'tipo'        => $r->tipo,
                'clase'       => $r->clase,
                'dispositivo' => $r->dispositivo,
                'origen'      => $r->origen,
            ];
        }

        // Navegación prev/next
        $nav = $this->buildDayNav($portalId, $clientIds, $empFilter, $dateStr);

        $countByCliente = $rows->groupBy('id_cliente')->map->count();

        return response()->json([
            'ok'   => true,
            'meta' => [
                'date'             => $dateStr,
                'range'            => ['from' => $from, 'to' => $to],
                'order'            => $order,
                'nav'              => $nav,
                'id_clientes'      => array_values($clientIds),
                'count_by_cliente' => (object) $countByCliente,
            ],
            'data' => array_values($byEmp),
        ]);
    }

    /* ========================= Helpers ========================= */

    private function resolveDateRange(?string $day, ?string $from, ?string $to): array
    {
        if ($day) {
            $d1 = Carbon::parse($day)->startOfDay();
            $d2 = Carbon::parse($day)->endOfDay();
            return [$d1->format('Y-m-d H:i:s'), $d2->format('Y-m-d H:i:s')];
        }

        $d1 = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->subDays(6)->startOfDay();
        $d2 = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();

        if ($d1->gt($d2)) {
            [$d1, $d2] = [$d2, $d1];
        }

        return [$d1->format('Y-m-d H:i:s'), $d2->format('Y-m-d H:i:s')];
    }

    /**
     * Acepta: null | int | "229,230" | [229,230,"231"]  → PK empleados
     */
    private function normalizeIdEmpleadoParam($param): array
    {
        if (is_null($param) || $param === '') return [];
        if (is_array($param))  return collect($param)->map(fn($v) => (int) $v)->filter()->values()->all();
        if (is_string($param)) return collect(explode(',', $param))->map(fn($v) => (int) trim($v))->filter()->values()->all();
        if (is_numeric($param)) return [(int) $param];
        return [];
    }

    /**
     * Acepta: null | int | "6,70" | [6,70]
     */
    private function normalizeIdClienteParam($param): array
    {
        if (is_null($param) || $param === '') return [];
        if (is_array($param))  return collect($param)->flatten()->map(fn($v) => (int) $v)->filter()->values()->all();
        if (is_string($param)) return collect(explode(',', $param))->map(fn($v) => (int) trim($v))->filter()->values()->all();
        if (is_numeric($param)) return [(int) $param];
        return [];
    }

    private function buildDayNav(int $portalId, array $clientIds, array $empIds, string $date): array
    {
        $dayStart = Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd   = Carbon::parse($date)->endOfDay()->format('Y-m-d H:i:s');

        $prevRow = $this->checadasQB()
            ->selectRaw('DATE(check_time) as d')
            ->where('id_portal', $portalId)
            ->when(!empty($clientIds), fn($q) => $q->whereIn('id_cliente', $clientIds))
            ->when(!empty($empIds),    fn($q) => $q->whereIn('id_empleado', $empIds))
            ->where('check_time', '<', $dayStart)
            ->orderBy('check_time', 'desc')
            ->limit(1)
            ->first();

        $nextRow = $this->checadasQB()
            ->selectRaw('DATE(check_time) as d')
            ->where('id_portal', $portalId)
            ->when(!empty($clientIds), fn($q) => $q->whereIn('id_cliente', $clientIds))
            ->when(!empty($empIds),    fn($q) => $q->whereIn('id_empleado', $empIds))
            ->where('check_time', '>', $dayEnd)
            ->orderBy('check_time', 'asc')
            ->limit(1)
            ->first();

        return [
            'prev' => $prevRow->d ?? null,
            'next' => $nextRow->d ?? null,
        ];
    }

    /**
     * Carga empleados en un map por PK (id) con alias:
     * - numero           → id_empleado/num_empleado
     * - nombre_completo  → resuelto según columnas disponibles
     * - nombre_cliente   → si existe o se resuelve por clientes
     */
    private function cargarEmpleadosMap(int $portalId, $ids)
    {
        $ids = collect($ids)->filter()->unique()->values();
        if ($ids->isEmpty()) return collect();

        $conn     = 'portal_main';
        $empTable = 'empleados';
        $cliTable = 'clientes';

        $has = fn($col) => Schema::connection($conn)->hasColumn($empTable, $col);

        // nombre completo
        if ($has('nombre_completo')) {
            $nombreExpr = 'nombre_completo';
        } elseif ($has('nombres') || $has('paterno') || $has('materno')) {
            $n          = $has('nombres') ? 'nombres' : "''";
            $ap         = $has('paterno') ? 'paterno' : "''";
            $am         = $has('materno') ? 'materno' : "''";
            $nombreExpr = DB::raw("CONCAT_WS(' ', $n, $ap, $am) as nombre_completo");
        } elseif ($has('nombre')) {
            $nombreExpr = DB::raw("`nombre` as nombre_completo");
        } else {
            $nombreExpr = DB::raw("NULL as nombre_completo");
        }

        // selects base
        $selects = ['id'];

        // número/legajo
        if ($has('id_empleado')) {
            $selects[] = DB::raw('id_empleado as numero');
        } elseif ($has('num_empleado')) {
            $selects[] = DB::raw('num_empleado as numero');
        } else {
            $selects[] = DB::raw('NULL as numero');
        }

        $selects[] = $nombreExpr;

        // nombre_cliente directo
        if ($has('nombre_cliente')) {
            $selects[] = 'nombre_cliente';
            return DB::connection($conn)->table($empTable)
                ->select($selects)
                ->where('id_portal', $portalId)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');
        }

        // resolver cliente por join (si hay id_cliente en empleados)
        $selectsForJoin = $selects;
        if ($has('id_cliente')) {
            $selectsForJoin[] = 'id_cliente';
            $empRows = DB::connection($conn)->table($empTable)
                ->select($selectsForJoin)
                ->where('id_portal', $portalId)
                ->whereIn('id', $ids)
                ->get();

            $cliNameCol = Schema::connection($conn)->hasColumn($cliTable, 'nombre') ? 'nombre'
                : (Schema::connection($conn)->hasColumn($cliTable, 'razon_social') ? 'razon_social' : null);

            $cliMap = collect();
            if ($cliNameCol) {
                $cliIds = $empRows->pluck('id_cliente')->filter()->unique()->values();
                if ($cliIds->isNotEmpty()) {
                    $cliMap = DB::connection($conn)->table($cliTable)
                        ->select(['id', DB::raw("`$cliNameCol` as nombre")])
                        ->whereIn('id', $cliIds)
                        ->get()
                        ->keyBy('id');
                }
            }

            return $empRows->map(function ($r) use ($cliMap) {
                $r->nombre_cliente = optional($cliMap->get($r->id_cliente))->nombre ?? null;
                unset($r->id_cliente);
                return $r;
            })->keyBy('id');
        }

        // fallback sin cliente
        return DB::connection($conn)->table($empTable)
            ->select($selects)
            ->where('id_portal', $portalId)
            ->whereIn('id', $ids)
            ->get()
            ->map(function ($r) { $r->nombre_cliente = null; return $r; })
            ->keyBy('id');
    }

    /** clase/tipo aceptan csv, array o valor único */
    private function normalizeCsvParam($param): array
    {
        if (is_null($param) || $param === '') return [];
        if (is_array($param)) {
            return collect($param)->map(fn($v) => trim((string)$v))
                ->filter()->values()->all();
        }
        return collect(explode(',', (string)$param))
            ->map(fn($v) => trim($v))
            ->filter()->values()->all();
    }
}
