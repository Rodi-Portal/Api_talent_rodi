<?php
namespace App\Http\Controllers;

use App\Models\ClienteTalent;
use App\Models\PeriodoNomina;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

// asegúrate de importar esto

class PeriodoNominaController extends Controller
{
    public function index(Request $request)
    {
        // Normalizar id_cliente
        $idClientesRaw = $request->input('id_cliente');
        $idClientes    = [];

        if (is_string($idClientesRaw)) {
            $decoded = json_decode($idClientesRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $idClientes = $decoded;
            } elseif (! empty($idClientesRaw)) {
                $idClientes = [$idClientesRaw];
            }
        } elseif (is_array($idClientesRaw)) {
            $idClientes = $idClientesRaw;
        }

        $idClientes = array_filter(array_map('intval', $idClientes));
        $request->merge(['id_cliente' => $idClientes]);
        $request->merge(['id_portal' => (int) $request->input('id_portal')]);

        // Validación
        $request->validate([
            'id_cliente'   => ['array'],
            'id_cliente.*' => ['integer', function ($attribute, $value, $fail) {
                if (! ClienteTalent::where('id', $value)->exists()) {
                    $fail("El cliente con id {$value} no existe.");
                }
            }],
            'estatus'      => ['nullable', 'string'],
            'tipo_nomina'  => ['nullable', 'string'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin'    => ['nullable', 'date'],
            'id_portal'    => ['required', 'integer'],
        ]);

        // Filtros base para periodos
        $aplicarFiltros = function ($query) use ($request) {
            $query->where('id_portal', $request->id_portal);

            if ($request->filled('estatus')) {
                $query->where('estatus', $request->estatus);
            }
            if ($request->filled('tipo_nomina')) {
                $query->where('tipo_nomina', $request->tipo_nomina);
            }
            if ($request->filled('fecha_inicio')) {
                $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
            }
            if ($request->filled('fecha_fin')) {
                $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
            }

            $query->orderBy('fecha_inicio', 'desc');
        };

        $clientes = collect();

        if (! empty($idClientes)) {
            // Clientes seleccionados con periodos filtrados
            $clientes = ClienteTalent::whereIn('id', $idClientes)
                ->select('id', 'nombre')
                ->with(['periodos' => function ($query) use ($aplicarFiltros) {
                    $aplicarFiltros($query);
                    $query->select('*')->without('creacion'); // no necesario pero ilustrativo
                }])
                ->get();
        }

        // Periodos generales (id_cliente = null)
        $periodosGenerales = PeriodoNomina::whereNull('id_cliente')
            ->where('id_portal', $request->id_portal)
            ->when($request->filled('estatus'), fn($q) => $q->where('estatus', $request->estatus))
            ->when($request->filled('tipo_nomina'), fn($q) => $q->where('tipo_nomina', $request->tipo_nomina))
            ->when($request->filled('fecha_inicio'), fn($q) => $q->whereDate('fecha_inicio', '>=', $request->fecha_inicio))
            ->when($request->filled('fecha_fin'), fn($q) => $q->whereDate('fecha_fin', '<=', $request->fecha_fin))
            ->orderBy('fecha_inicio', 'desc')
            ->get()
            ->map(function ($periodo) {
                unset($periodo->creacion);
                return $periodo;
            });

        // Incluir cliente virtual 'General' si hay periodos generales
        if ($periodosGenerales->isNotEmpty()) {
            $clientes->push([
                'id'       => null,
                'nombre'   => 'General',
                'periodos' => $periodosGenerales,
            ]);
        }

        return response()->json($clientes);
    }
    private function detectarPeriodicidad($inicio, $fin): string
    {
        $ini  = \Carbon\Carbon::parse($inicio);
        $fin  = \Carbon\Carbon::parse($fin);
        $diff = $ini->diffInDays($fin);

        // 01 Diario
        if ($ini->equalTo($fin)) {
            return '01';
        }

        // 02 Semanal (7 días exactos)
        if ($diff == 6) {
            return '02';
        }

        // 03 Catorcenal (14 días exactos)
        if ($diff == 13) {
            return '03';
        }

        // 04 Quincenal
        if ($ini->month === $fin->month && $ini->year === $fin->year) {

            // Primera quincena 1–15
            if ($ini->day == 1 && $fin->day == 15) {
                return '04';
            }

            // Segunda quincena 16–último día
            if ($ini->day == 16 && $fin->isSameDay($ini->copy()->endOfMonth())) {
                return '04';
            }
        }

        // 05 Mensual
        if ($ini->day == 1 && $fin->isSameDay($ini->copy()->endOfMonth())) {
            return '05';
        }

        // Si no entra en ninguna: "99 Otro"
        return '99';
    }
    private function calcularPeriodos($inicio, $fin, $periodicidad): array
    {
        $ini = \Carbon\Carbon::parse($inicio);
        $fin = \Carbon\Carbon::parse($fin);

        // 🔹 Para quincenal: periodo específico
        if ($periodicidad === '04') {
            $periodo = ($ini->month - 1) * 2 + ($ini->day <= 15 ? 1 : 2);
            return [$periodo];
        }

        // 🔹 Para mensual: siempre devolver 2 periodos
        if ($periodicidad === '05') {
            $base = ($ini->month - 1) * 2;
            return [$base + 1, $base + 2];
        }

        // 🔹 Semanal → asignamos según el día del mes
        if ($periodicidad === '02') {
            $periodo = ($ini->month - 1) * 2 + 1; // SEMANA ENTRA AL PRIMER PERIODO DEL MES
            return [$periodo];
        }

        // 🔹 Diario / catorcenal / 99 otros → caen en un solo periodo
        $periodo = ($ini->month - 1) * 2 + ($ini->day <= 15 ? 1 : 2);
        return [$periodo];
    }

    public function store(Request $request)
    {
        $request->validate([
            'id_portal'    => 'required|integer',
            'id_cliente'   => 'present|array',
            'id_cliente.*' => [
                'nullable',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! is_null($value) && ! ClienteTalent::where('id', $value)->exists()) {
                        $fail("El cliente con id {$value} no existe en la base de datos.");
                    }
                },
            ],
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'fecha_pago'   => 'required|date',
            'tipo_nomina'  => 'required|in:ordinaria,extraordinaria',
            'estatus'      => 'required|in:pendiente,cerrado,cancelado',
        ]);

        $clientes = $request->id_cliente;

        if (empty($clientes)) {
            $clientes = [null];
        }

        $creados = [];

        foreach ($clientes as $id_cliente) {

            // 1️⃣ Detectar periodicidad basada en fechas
            $periodicidad = $this->detectarPeriodicidad($request->fecha_inicio, $request->fecha_fin);

            // 2️⃣ Calcular periodos (siempre arreglo)
            $periodos = $this->calcularPeriodos(
                $request->fecha_inicio,
                $request->fecha_fin,
                $periodicidad
            );

            // 3️⃣ Validación de traslapes
            if ($request->tipo_nomina !== 'extraordinaria') {
                $existe = PeriodoNomina::where('id_portal', $request->id_portal)
                    ->where('tipo_nomina', $request->tipo_nomina)
                    ->where(function ($q) use ($id_cliente) {
                        $q->where('id_cliente', $id_cliente)
                            ->orWhereNull('id_cliente');
                    })
                    ->where(function ($query) use ($request) {
                        $query->where('fecha_inicio', '<=', $request->fecha_fin)
                            ->where('fecha_fin', '>=', $request->fecha_inicio);
                    })
                    ->exists();

                if ($existe) {
                    return response()->json([
                        'message' => "Ya existe un periodo {$request->tipo_nomina} que se superpone con estas fechas.",
                    ], 422);
                }
            }

            // 4️⃣ Crear el periodo
            $periodo = PeriodoNomina::create([
                'id_portal'             => $request->id_portal,
                'id_cliente'            => $id_cliente,
                'id_usuario'            => $request->id_usuario,
                'fecha_inicio'          => $request->fecha_inicio,
                'fecha_fin'             => $request->fecha_fin,
                'fecha_pago'            => $request->fecha_pago,
                'tipo_nomina'           => $request->tipo_nomina,
                'estatus'               => $request->estatus,
                'descripcion'           => $request->descripcion ?? null,

                // 🎯 Guardamos periodicidad detectada
                'periodicidad_objetivo' => $periodicidad,

                // 🎯 Guardamos arreglo de periodos
                'periodo_num'           => json_encode($periodos),

                'creado_por'            => auth()->id() ?? 1,
            ]);

            $creados[] = $periodo;
        }

        return response()->json([
            'message' => 'Periodos creados correctamente.',
            'data'    => $creados,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'fecha_pago'   => 'required|date',
            'tipo_nomina'  => 'required|in:ordinaria,extraordinaria',
            'estatus'      => 'required|in:pendiente,cerrado,cancelado',
        ]);

        $periodo = PeriodoNomina::findOrFail($id);

        // 🔹 Detectar periodicidad automáticamente con tus reglas
        $periodicidad = $this->detectarPeriodicidad(
            $request->fecha_inicio,
            $request->fecha_fin
        );

        // 🔹 Calcular arreglo de periodos
        $periodo_num = $this->calcularPeriodos(
            $request->fecha_inicio,
            $request->fecha_fin,
            $periodicidad
        );

        // 🔹 Validar traslapes si NO es extraordinaria
        if ($request->tipo_nomina !== 'extraordinaria') {

            $existe = PeriodoNomina::where('id_portal', $periodo->id_portal)
                ->where('id_cliente', $periodo->id_cliente)
                ->where('tipo_nomina', $request->tipo_nomina)
                ->where('id', '!=', $periodo->id)
                ->where(function ($query) use ($request) {
                    $query->where('fecha_inicio', '<=', $request->fecha_fin)
                        ->where('fecha_fin', '>=', $request->fecha_inicio);
                })
                ->exists();

            if ($existe) {
                return response()->json([
                    'message' => 'Ya existe otro periodo que se superpone con estas fechas.',
                ], 422);
            }
        }

        // 🔹 Guardar cambios
        $periodo->update([
            'id_usuario'            => $request->id_usuario,
            'fecha_inicio'          => $request->fecha_inicio,
            'fecha_fin'             => $request->fecha_fin,
            'fecha_pago'            => $request->fecha_pago,
            'tipo_nomina'           => $request->tipo_nomina,
            'estatus'               => $request->estatus,
            'descripcion'           => $request->descripcion ?? $periodo->descripcion,
            'periodicidad_objetivo' => $periodicidad,
            'periodo_num'           => json_encode($periodo_num),
        ]);

        return response()->json($periodo);
    }

    public function periodosConPrenomina(Request $request)
    {
        //Log::debug('Request recibido en periodosConPrenomina:', $request->all());

        $clientes = $request->input('id_cliente');
        Log::debug('Valor inicial de id_cliente:', ['id_cliente' => $clientes]);

        if (! is_array($clientes)) {
            $clientes = [$clientes];
        }

        foreach ($clientes as $clienteId) {
            if (! is_numeric($clienteId)) {
                return response()->json([
                    'message' => "El id_cliente debe ser numérico.",
                ], 422);
            }

            if (! ClienteTalent::where('id', $clienteId)->exists()) {
                return response()->json([
                    'message' => "El cliente con id {$clienteId} no existe.",
                ], 422);
            }
        }

        $query = PeriodoNomina::with('prenominaEmpleados', 'cliente')
            ->where('id_portal', $request->id_portal)
            ->where('estatus', 'pendiente')

            ->where(function ($q) use ($clientes) {
                $q->whereIn('id_cliente', $clientes)
                    ->orWhereNull('id_cliente'); // ✅ incluir sin cliente
            });

        if ($request->filled('estatus')) {
            $query->where('estatus', $request->estatus);
        }

        if ($request->filled('tipo_nomina')) {
            $query->where('tipo_nomina', $request->tipo_nomina);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
        }

        $resultados = $query->orderBy('fecha_inicio', 'desc')->get();

        // ✅ Agregar nombre del cliente o GENERAL si no tiene cliente
        $resultados = $resultados->map(function ($p) {
            $p->cliente_nombre = $p->cliente->nombre ?? 'GENERAL';
            unset($p->cliente);
            return $p;
        });

        return response()->json($resultados);
    }

    public function obtenerPeriodosPendientes(Request $request)
    {
        //  Log::debug('Request recibido en periodosConPrenomina:', $request->all());
        $idPortal      = (int) $request->query('id_portal');
        $idClientesRaw = $request->query('id_cliente', []);
        $idClientes    = [];

        // Normalizar id_cliente a array de enteros
        if (is_string($idClientesRaw)) {
            $decoded = json_decode($idClientesRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $idClientes = $decoded;
            } elseif (! empty($idClientesRaw)) {
                $idClientes = [$idClientesRaw];
            }
        } elseif (is_array($idClientesRaw)) {
            $idClientes = $idClientesRaw;
        }

        $idClientes = array_filter(array_map('intval', $idClientes));

        // Base query
        $query = PeriodoNomina::with('cliente')
            ->where('id_portal', $idPortal)
            ->where('estatus', 'pendiente');

        // Filtro según cantidad de clientes
        if (count($idClientes) === 1) {
            $clienteUnico = $idClientes[0];
            $query->where(function ($q) use ($clienteUnico) {
                $q->whereNull('id_cliente')
                    ->orWhere('id_cliente', $clienteUnico);
            });
        } else {
            // Si son varios, solo traer periodos generales
            $query->whereNull('id_cliente');
        }

        $periodos = $query->orderBy('fecha_inicio', 'desc')->get();

        // Agregar nombre del cliente o 'GENERAL'
        $periodos = $periodos->map(function ($p) {
            $p->cliente_nombre = $p->cliente->nombre ?? 'GENERAL';
            unset($p->cliente); // opcional
            return $p;
        });

        return response()->json($periodos);
    }

}
