<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\PoliticaAsistencia; // ⬅️ festivos
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PoliticasAsistenciaController extends Controller
{
    /* ============================================================
     * GET /api/politicas-asistencia
     * Filtros: id_portal (req), id_cliente (opt), estado (opt), q (opt), per_page (opt)
     * include=pivots → devuelve ids_empleados / ids_clientes por cada fila.
     * ============================================================ */
    public function index(Request $request)
    {
        $data = $request->validate([
            'id_portal'       => ['required', 'integer'],
            'id_cliente'      => ['nullable', 'integer'],
            'id_departamento' => ['nullable', 'integer'], // ⬅️ NUEVO
            'estado'          => ['nullable', Rule::in(['borrador', 'publicada'])],
            'q'               => ['nullable', 'string', 'max:120'],
            'per_page'        => ['nullable', 'integer', 'min:1', 'max:200'],
            'include'         => ['nullable', 'string'], // e.g. 'pivots'
        ]);

        $perPage = $data['per_page'] ?? 20;

        $q = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->when(isset($data['id_cliente']), fn($qq) => $qq->where('id_cliente', $data['id_cliente']))
            ->when(isset($data['id_departamento']), function ($qq) use ($data) { // ⬅️ NUEVO
                $qq->whereExists(function ($sub) use ($data) {
                    $sub->from('politica_asistencia_departamento as pad')
                        ->whereColumn('pad.id_politica_asistencia', 'politica_asistencia.id')
                        ->where('pad.id_departamento', $data['id_departamento']);
                });
            })
            ->when(isset($data['estado']), fn($qq) => $qq->where('estado', $data['estado']))
            ->when(isset($data['q']), fn($qq) => $qq->where('nombre', 'like', '%' . $data['q'] . '%'))
            ->orderBy('id_cliente')
            ->orderByDesc('actualizado_en');

        $result = $q->paginate($perPage)->appends($request->query());
        $items  = $result->items();

        // ¿Incluir ids de pivote por fila?
        if (($data['include'] ?? null) === 'pivots' && ! empty($items)) {
            $conn  = $this->pivotConn();
            $idsPa = array_map(fn($r) => $r->id, $items);

            // empleados
            $empRows = $conn->table('politica_asistencia_empleado')
                ->whereIn('id_politica_asistencia', $idsPa)
                ->get(['id_politica_asistencia', 'id_empleado']);

            // clientes
            $cliRows = $conn->table('politica_asistencia_cliente')
                ->whereIn('id_politica_asistencia', $idsPa)
                ->get(['id_politica_asistencia', 'id_cliente']);
            // departamentos (NUEVO)
            $depRows = $conn->table('politica_asistencia_departamento')
                ->whereIn('id_politica_asistencia', $idsPa)
                ->get(['id_politica_asistencia', 'id_departamento']);

            $empMap = [];
            foreach ($empRows as $r) {
                $empMap[$r->id_politica_asistencia][] = (string) $r->id_empleado;
            }
            $cliMap = [];
            foreach ($cliRows as $r) {
                $cliMap[$r->id_politica_asistencia][] = (int) $r->id_cliente;
            }
            $depMap = [];
            foreach ($depRows as $r) {
                $depMap[$r->id_politica_asistencia][] = (int) $r->id_departamento;
            }
            foreach ($items as $row) {
                $row->ids_empleados     = $empMap[$row->id] ?? [];
                $row->ids_clientes      = $cliMap[$row->id] ?? [];
                $row->ids_departamentos = $depMap[$row->id] ?? []; // NUEVO

            }
        }

        return response()->json([
            'ok'         => true,
            'data'       => $items,
            'pagination' => [
                'total'        => $result->total(),
                'per_page'     => $result->perPage(),
                'current_page' => $result->currentPage(),
                'last_page'    => $result->lastPage(),
            ],
        ]);
    }

    /* ============================================================
     * GET /api/politicas-asistencia/{id}
     * Devuelve la política + ids_empleados / ids_clientes
     * ============================================================ */
    public function show(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $conn = $this->pivotConn();

        $idsEmps = $conn->table('politica_asistencia_empleado')
            ->where('id_politica_asistencia', $politica->id)
            ->pluck('id_empleado')
            ->map(fn($v) => (string) $v)
            ->values()
            ->all();

        $idsClis = $conn->table('politica_asistencia_cliente')
            ->where('id_politica_asistencia', $politica->id)
            ->pluck('id_cliente')
            ->map(fn($v) => (int) $v)
            ->values()
            ->all();

        // NUEVO: departamentos
        $idsDeps = $conn->table('politica_asistencia_departamento')
            ->where('id_politica_asistencia', $politica->id)
            ->pluck('id_departamento')
            ->map(fn($v) => (int) $v)
            ->values()
            ->all();

        return response()->json([
            'ok'   => true,
            'data' => array_merge($politica->toArray(), [
                'ids_empleados'     => $idsEmps,
                'ids_clientes'      => $idsClis,
                'ids_departamentos' => $idsDeps,
            ]),
        ]);
    }

    /* ============================================================
     * POST /api/politicas-asistencia
     * Crea y sincroniza pivotes según scope (con reasignación de empleados)
     * ============================================================ */
    public function store(Request $request)
    {
        $v = $this->validatedData($request);

        // ⬅️ nombre único por portal (ajusta si quieres por scope)
        $this->assertUniqueNameOr409($v);

        DB::connection($this->modelConnName())->beginTransaction();
        try {
            $politica = PoliticaAsistencia::create($this->mapFillable($v));
            $this->syncPivots($politica, $v);

            DB::connection($this->modelConnName())->commit();

            return response()->json([
                'ok'      => true,
                'message' => 'Política creada correctamente.',
                'data'    => $this->resource($politica),
            ], 201);

        } catch (\Throwable $e) {
            DB::connection($this->modelConnName())->rollBack();
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /* ============================================================
     * PUT /api/politicas-asistencia/{id}
     * Actualiza y sincroniza pivotes según scope (con reasignación de empleados)
     * ============================================================ */
    public function update(Request $request, int $id)
    {
        $v = $this->validatedData($request);

        // ⬅️ nombre único por portal excluyendo el propio id
        $this->assertUniqueNameOr409($v, $id);

        $politica = PoliticaAsistencia::query()
            ->delPortal($v['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        DB::connection($this->modelConnName())->beginTransaction();
        try {
            $politica->fill($this->mapFillable($v));
            $politica->save();

            $this->syncPivots($politica, $v);

            DB::connection($this->modelConnName())->commit();

            return response()->json([
                'ok'      => true,
                'message' => 'Política actualizada.',
                'data'    => $this->resource($politica),
            ]);

        } catch (\Throwable $e) {
            DB::connection($this->modelConnName())->rollBack();
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /* ============================================================
     * DELETE /api/politicas-asistencia/{id}
     * Elimina la política y también sus pivotes (por código y/o por FK)
     * ============================================================ */
    public function destroy(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $conn = $this->pivotConn();

        DB::connection($this->modelConnName())->beginTransaction();
        try {
            // Borrar pivotes (incluye departamentos - NUEVO)
            $conn->table('politica_asistencia_empleado')->where('id_politica_asistencia', $politica->id)->delete();
            $conn->table('politica_asistencia_cliente')->where('id_politica_asistencia', $politica->id)->delete();
            $conn->table('politica_asistencia_departamento')->where('id_politica_asistencia', $politica->id)->delete(); // NUEVO

            // Borrar principal
            $politica->delete();

            DB::connection($this->modelConnName())->commit();

            return response()->json(['ok' => true, 'message' => 'Política eliminada.']);

        } catch (\Throwable $e) {
            DB::connection($this->modelConnName())->rollBack();
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /* ============================================================
     * FESTIVOS
     * ============================================================ */

    /**
     * GET /api/politicas-asistencia/{id}/festivos
     * Params: id_portal (req), year (opt, YYYY)
     */
    public function listHolidays(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
            'year'      => ['nullable', 'digits:4'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $conn = DB::connection($this->modelConnName());

        $q = $conn->table('politica_festivos')
            ->where('id_politica_asistencia', $politica->id)
            ->orderBy('fecha', 'asc');

        if (! empty($data['year'])) {
            $q->whereYear('fecha', (int) $data['year']);
        }

        $rows = $q->get(['id', 'fecha', 'nombre', 'es_laborado']);

        return response()->json([
            'ok'       => true,
            'politica' => ['id' => $politica->id, 'nombre' => $politica->nombre],
            'festivos' => $rows,
        ]);
    }

    /**
     * PUT /api/politicas-asistencia/{id}/festivos
     * Body:
     *  - id_portal: int (req)
     *  - festivos: array<{fecha: Y-m-d, nombre?: string, es_laborado?: bool}> (req)
     *  - year: YYYY (opt) Si viene, reemplaza SOLO los festivos de ese año; si no, reemplaza TODOS.
     */
    public function saveHolidays(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal'              => ['required', 'integer'],
            'festivos'               => ['required', 'array', 'min:0'],
            'festivos.*.fecha'       => ['required', 'date'],
            'festivos.*.nombre'      => ['nullable', 'string', 'max:120'],
            'festivos.*.es_laborado' => ['nullable', 'boolean'],
            'year'                   => ['nullable', 'digits:4'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $conn  = DB::connection($this->modelConnName());
        $table = $conn->table('politica_festivos');

        $conn->beginTransaction();
        try {
            $festivos = $data['festivos'] ?? [];
            $year     = $data['year'] ?? null;

            // Borrado selectivo por año o completo
            $qb = $table->where('id_politica_asistencia', $politica->id);
            if ($year) {
                $qb->whereYear('fecha', (int) $year)->delete();
            } else {
                $qb->delete();
            }

            // Insert masivo (si hay)
            if (! empty($festivos)) {
                $now  = now();
                $rows = [];
                foreach ($festivos as $f) {
                    $rows[] = [
                        'id_politica_asistencia' => $politica->id,
                        'fecha'                  => $f['fecha'],
                        'nombre'                 => $f['nombre'] ?? null,
                        'es_laborado'            => (int) ! empty($f['es_laborado']),
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ];
                }
                $table->insert($rows);
            }

            $conn->commit();

            return response()->json([
                'ok'       => true,
                'message'  => 'Festivos guardados.',
                'replaced' => $year ? "Año {$year}" : 'Todos',
                'count' => count($festivos),
            ]);
        } catch (\Throwable $e) {
            $conn->rollBack();
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
    public function destroyHoliday(Request $request, int $id, int $festivoId)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (! $politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $conn = DB::connection($this->modelConnName());

        // valida que el festivo pertenezca a esta política
        $exists = $conn->table('politica_festivos')
            ->where('id', $festivoId)
            ->where('id_politica_asistencia', $politica->id)
            ->exists();

        if (! $exists) {
            return response()->json(['ok' => false, 'message' => 'Festivo no encontrado.'], 404);
        }

        $conn->table('politica_festivos')->where('id', $festivoId)->delete();

        return response()->json(['ok' => true, 'message' => 'Festivo eliminado.']);
    }

    /* ============================================================
     * Helpers privados
     * ============================================================ */

    /** Nombre de conexión del modelo (para transacciones) */
    protected function modelConnName(): string
    {
        return (new PoliticaAsistencia)->getConnectionName() ?? config('database.default');
    }

    /** Conexión para tablas pivote (misma que el modelo) */
    protected function pivotConn()
    {
        return DB::connection($this->modelConnName());
    }

    /** Reubica empleados al crear/editar: borra su asignación en otras políticas del mismo portal y los asigna a $pa */
    protected function reassignEmployeesToPolicy(PoliticaAsistencia $pa, array $empIds): void
    {
        if (empty($empIds)) {
            return;
        }

        $conn   = $this->pivotConn();
        $empIds = array_values(array_unique(array_map('strval', $empIds)));

        // borra asignaciones previas de esos empleados en el MISMO portal
        $conn->table('politica_asistencia_empleado as pae')
            ->join('politica_asistencia as p', 'p.id', '=', 'pae.id_politica_asistencia')
            ->where('p.id_portal', $pa->id_portal)
            ->whereIn('pae.id_empleado', $empIds)
            ->delete();

        // inserta nuevas filas hacia esta política
        $rows = [];
        foreach ($empIds as $emp) {
            $rows[] = [
                'id_politica_asistencia' => (int) $pa->id,
                'id_empleado'            => (string) $emp,
            ];
        }
        if ($rows) {
            $conn->table('politica_asistencia_empleado')->insert($rows);
        }
    }

    /** Valida + normaliza (match con payload del front) */
    protected function validatedData(Request $req): array
    {
        $v = $req->validate([
            'id_portal'                    => ['required', 'integer'],
            'scope'                        => ['required', Rule::in(['PORTAL', 'SUCURSAL', 'EMPLEADO', 'DEPARTAMENTO'])],
            'nombre'                       => ['required', 'string', 'max:120'],

            // legado “unitario”
            'id_cliente'                   => ['nullable', 'integer'],
            'id_empleado'                  => ['nullable', 'string', 'max:64'],
            'id_departamento'              => ['nullable', 'integer'], // NUEVO (una sola)

            // múltiples
            'ids_clientes'                 => ['nullable', 'array'],
            'ids_clientes.*'               => ['integer'],
            'ids_empleados'                => ['nullable', 'array'],
            'ids_empleados.*'              => ['string', 'max:64'],
            'ids_departamentos'            => ['nullable', 'array'], // NUEVO
            'ids_departamentos.*'          => ['integer'],           // NUEVO

            'vigente_desde'                => ['nullable', 'date'],
            'vigente_hasta'                => ['nullable', 'date'],
            'timezone'                     => ['nullable', 'string', 'max:64'],

            'hora_entrada'                 => ['required', 'date_format:H:i:s'],
            'hora_salida'                  => ['required', 'date_format:H:i:s'],
            'trabaja_sabado'               => ['nullable', 'boolean'],
            'trabaja_domingo'              => ['nullable', 'boolean'],

            'tolerancia_minutos'           => ['required', 'integer', 'min:0', 'max:600'],
            'retardos_por_falta'           => ['required', 'integer', 'min:1', 'max:50'],
            'contar_salida_temprano'       => ['nullable', 'boolean'],

            'descuento_retardo_modo'       => ['required', Rule::in(['ninguno', 'por_evento', 'por_minuto'])],
            'descuento_retardo_valor'      => ['required', 'numeric', 'min:0', 'max:100'],

            'usar_descuento_falta_laboral' => ['nullable', 'boolean'],
            'descuento_falta_modo'         => ['nullable', Rule::in(['ninguno', 'porcentaje_dia', 'fijo'])],
            'descuento_falta_valor'        => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'calcular_extras'              => ['nullable', 'boolean'],
            'criterio_extra'               => ['nullable', Rule::in(['sobre_salida', 'sobre_horas_dia'])],
            'horas_dia_empleado'           => ['required', 'numeric', 'min:0', 'max:24'],
            'minutos_gracia_extra'         => ['required', 'integer', 'min:0', 'max:300'],
            'tope_horas_extra'             => ['required', 'numeric', 'min:0', 'max:24'],

            'estado'                       => ['required', Rule::in(['borrador', 'publicada'])],
        ]);

        // Defaults
        $v['trabaja_sabado']               = (bool) ($v['trabaja_sabado'] ?? false);
        $v['trabaja_domingo']              = (bool) ($v['trabaja_domingo'] ?? false);
        $v['contar_salida_temprano']       = (bool) ($v['contar_salida_temprano'] ?? false);
        $v['calcular_extras']              = (bool) ($v['calcular_extras'] ?? false);
        $v['criterio_extra']               = $v['criterio_extra'] ?? 'sobre_salida';
        $v['timezone']                     = $v['timezone'] ?? 'America/Mexico_City';
        $v['estado']                       = $v['estado'] ?? 'publicada';
        $v['usar_descuento_falta_laboral'] = (bool) ($v['usar_descuento_falta_laboral'] ?? false);
        $v['descuento_falta_modo']         = $v['descuento_falta_modo'] ?? 'ninguno';
        $v['descuento_falta_valor']        = (float) ($v['descuento_falta_valor'] ?? 0);

        // Normalizar arrays
        $v['ids_clientes']      = array_values(array_unique(array_map('intval', $v['ids_clientes'] ?? [])));
        $v['ids_empleados']     = array_values(array_unique(array_map('strval', $v['ids_empleados'] ?? [])));
        $v['ids_departamentos'] = array_values(array_unique(array_map('intval', $v['ids_departamentos'] ?? []))); // NUEVO

        // Compat: si vino solo el “legado” unitario
        if ($v['scope'] === 'EMPLEADO' && empty($v['ids_empleados']) && ! empty($v['id_empleado'])) {
            $v['ids_empleados'] = [(string) $v['id_empleado']];
        }
        if ($v['scope'] === 'SUCURSAL' && empty($v['ids_clientes']) && isset($v['id_cliente'])) {
            $v['ids_clientes'] = [(int) $v['id_cliente']];
        }
        if ($v['scope'] === 'DEPARTAMENTO' && empty($v['ids_departamentos']) && isset($v['id_departamento'])) {
            $v['ids_departamentos'] = [(int) $v['id_departamento']];
        }

        return $v;
    }

    /** Campos de la tabla principal (no pivotes) */
    protected function mapFillable(array $v): array
    {
        return [
            'id_portal'                    => $v['id_portal'],
            'scope'                        => $v['scope'],
            'nombre'                       => $v['nombre'],
            'vigente_desde'                => $v['vigente_desde'] ?? null,
            'vigente_hasta'                => $v['vigente_hasta'] ?? null,
            'timezone'                     => $v['timezone'],

            'hora_entrada'                 => $v['hora_entrada'],
            'hora_salida'                  => $v['hora_salida'],
            'trabaja_sabado'               => $v['trabaja_sabado'],
            'trabaja_domingo'              => $v['trabaja_domingo'],

            'tolerancia_minutos'           => (int) $v['tolerancia_minutos'],
            'retardos_por_falta'           => (int) $v['retardos_por_falta'],
            'contar_salida_temprano'       => $v['contar_salida_temprano'],

            'descuento_retardo_modo'       => $v['descuento_retardo_modo'],
            'descuento_retardo_valor'      => (float) $v['descuento_retardo_valor'],

            'usar_descuento_falta_laboral' => $v['usar_descuento_falta_laboral'],
            'descuento_falta_modo'         => $v['descuento_falta_modo'],
            'descuento_falta_valor'        => (float) $v['descuento_falta_valor'],

            'calcular_extras'              => $v['calcular_extras'],
            'criterio_extra'               => $v['criterio_extra'],
            'horas_dia_empleado'           => (float) $v['horas_dia_empleado'],
            'minutos_gracia_extra'         => (int) $v['minutos_gracia_extra'],
            'tope_horas_extra'             => (float) $v['tope_horas_extra'],

            'estado'                       => $v['estado'],

            // legado (se ajustan después según pivote/scope)
            'id_cliente'                   => $v['id_cliente'] ?? null,
            'id_empleado'                  => $v['id_empleado'] ?? null,
            'id_departamento'              => $v['id_departamento'] ?? null, // NUEVO
        ];
    }

    /** Sincroniza pivotes y deja consistentes id_cliente/id_empleado “legado” */
    protected function syncPivots(PoliticaAsistencia $pa, array $v): void
    {
        $conn = $this->pivotConn();

        // Limpiar pivotes previos de ESTA política (soporta cambio de scope)
        $conn->table('politica_asistencia_empleado')->where('id_politica_asistencia', $pa->id)->delete();
        $conn->table('politica_asistencia_cliente')->where('id_politica_asistencia', $pa->id)->delete();
        $conn->table('politica_asistencia_departamento')->where('id_politica_asistencia', $pa->id)->delete(); // NUEVO

        if ($v['scope'] === 'EMPLEADO') {
            // Reubicar empleados: quitar de otras políticas del mismo portal y asignar a esta
            $this->reassignEmployeesToPolicy($pa, $v['ids_empleados']);

            // Consistencia legado
            $pa->id_cliente      = null;
            $pa->id_departamento = null; // NUEVO
            $pa->id_empleado     = count($v['ids_empleados']) === 1 ? $v['ids_empleados'][0] : null;
            $pa->save();

        } elseif ($v['scope'] === 'SUCURSAL') {
            $rows = [];
            foreach ($v['ids_clientes'] as $cid) {
                $rows[] = [
                    'id_politica_asistencia' => (int) $pa->id,
                    'id_cliente'             => (int) $cid,
                ];
            }
            if ($rows) {
                $conn->table('politica_asistencia_cliente')->insert($rows);
            }

            // Consistencia legado
            $pa->id_empleado     = null;
            $pa->id_departamento = null; // NUEVO
            $pa->id_cliente      = count($v['ids_clientes']) === 1 ? $v['ids_clientes'][0] : null;
            $pa->save();

        } elseif ($v['scope'] === 'DEPARTAMENTO') {
            $rows = [];
            foreach ($v['ids_departamentos'] as $did) {
                $rows[] = [
                    'id_politica_asistencia' => (int) $pa->id,
                    'id_departamento'        => (int) $did,
                ];
            }
            if ($rows) {
                $conn->table('politica_asistencia_departamento')->insert($rows);
            }

            // Consistencia legado
            $pa->id_empleado     = null;
            $pa->id_cliente      = null;
            $pa->id_departamento = count($v['ids_departamentos']) === 1 ? $v['ids_departamentos'][0] : null;
            $pa->save();

        } else { // PORTAL
            $pa->id_empleado     = null;
            $pa->id_cliente      = null;
            $pa->id_departamento = null; // NUEVO
            $pa->save();
        }
    }

    /** Recurso con arrays pivote para reflejar lo guardado */
    protected function resource(PoliticaAsistencia $pa): array
    {
        $conn = $this->pivotConn();

        $idsEmps = $conn->table('politica_asistencia_empleado')
            ->where('id_politica_asistencia', $pa->id)
            ->pluck('id_empleado')->map(fn($x) => (string) $x)->values()->all();

        $idsClis = $conn->table('politica_asistencia_cliente')
            ->where('id_politica_asistencia', $pa->id)
            ->pluck('id_cliente')->map(fn($x) => (int) $x)->values()->all();

        // NUEVO: departamentos
        $idsDeps = $conn->table('politica_asistencia_departamento')
            ->where('id_politica_asistencia', $pa->id)
            ->pluck('id_departamento')->map(fn($x) => (int) $x)->values()->all();

        return array_merge($pa->fresh()->toArray(), [
            'ids_empleados'     => $idsEmps,
            'ids_clientes'      => $idsClis,
            'ids_departamentos' => $idsDeps, // NUEVO
        ]);
    }

    /** 409 si ya existe otra política con el mismo nombre en el mismo portal */
    protected function assertUniqueNameOr409(array $v, ?int $excludeId = null): void
    {
        $q = PoliticaAsistencia::query()
            ->where('id_portal', $v['id_portal'])
            ->where('nombre', $v['nombre']);

        if ($excludeId) {
            $q->where('id', '!=', $excludeId);
        }

        if ($q->exists()) {
            abort(Response::HTTP_CONFLICT, 'Ya existe una política con ese nombre en este portal.');
        }
    }
}
