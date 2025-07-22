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
        $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! ClienteTalent::where('id', $value)->exists()) {
                        $fail("El cliente con id {$value} no existe en la base de datos.");
                    }
                },
            ],
            // otras validaciones...
        ]);
        $query = PeriodoNomina::where('id_portal', $request->id_portal)
            ->where('id_cliente', $request->id_cliente);

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

        return response()->json($query->orderBy('fecha_inicio', 'desc')->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'id_cliente'   => [
                'required',
                'integer',
                function ($attribute, $value, $fail) {
                    if (! ClienteTalent::where('id', $value)->exists()) {
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

        if ($request->tipo_nomina !== 'extraordinaria') {
            $existe = PeriodoNomina::where('id_cliente', $request->id_cliente)
                ->where('id_portal', $request->id_portal)
                ->where('tipo_nomina', $request->tipo_nomina)
                ->where(function ($query) use ($request) {
                    $query->whereBetween('fecha_inicio', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhereBetween('fecha_fin', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhere(function ($q) use ($request) {
                            $q->where('fecha_inicio', '<=', $request->fecha_inicio)
                                ->where('fecha_fin', '>=', $request->fecha_fin);
                        });
                })
                ->exists();

            if ($existe) {
                return response()->json([
                    'message' => 'Ya existe un periodo que se superpone con estas fechas y tipo de nómina.',
                ], 422);
            }
        }

        $periodo = PeriodoNomina::create([
            'id_portal'             => $request->id_portal,
            'id_cliente'            => $request->id_cliente,
            'id_usuario'            => $request->id_usuario,
            'fecha_inicio'          => $request->fecha_inicio,
            'fecha_fin'             => $request->fecha_fin,
            'fecha_pago'            => $request->fecha_pago,
            'tipo_nomina'           => $request->tipo_nomina,
            'estatus'               => $request->estatus,
            'descripcion'           => $request->descripcion ?? null,
            'periodicidad_objetivo' => $request->periodicidad_objetivo ?? null,
            'creado_por'            => auth()->id() ?? 1,
        ]);

        return response()->json($periodo, 201);
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

        if ($request->tipo_nomina !== 'extraordinaria') {
            $existe = PeriodoNomina::where('id_cliente', $periodo->id_cliente)
                ->where('id_portal', $periodo->id_portal)
                ->where('tipo_nomina', $request->tipo_nomina)
                ->where('id', '!=', $periodo->id)
                ->where(function ($query) use ($request) {
                    $query->whereBetween('fecha_inicio', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhereBetween('fecha_fin', [$request->fecha_inicio, $request->fecha_fin])
                        ->orWhere(function ($q) use ($request) {
                            $q->where('fecha_inicio', '<=', $request->fecha_inicio)
                                ->where('fecha_fin', '>=', $request->fecha_fin);
                        });
                })
                ->exists();

            if ($existe) {
                return response()->json([
                    'message' => 'Ya existe otro periodo que se superpone con estas fechas y tipo de nómina.',
                ], 422);
            }
        }

        $periodo->update([
            'id_usuario'            => $request->id_usuario,
            'fecha_inicio'          => $request->fecha_inicio,
            'fecha_fin'             => $request->fecha_fin,
            'fecha_pago'            => $request->fecha_pago,
            'tipo_nomina'           => $request->tipo_nomina,
            'estatus'               => $request->estatus,
            'descripcion'           => $request->descripcion ?? $periodo->descripcion,
            'periodicidad_objetivo' => $request->periodicidad_objetivo ?? $periodo->periodicidad_objetivo,
        ]);

        return response()->json($periodo);
    }

    public function periodosConPrenomina(Request $request)
    {
        Log::debug('Request recibido en periodosConPrenomina:', $request->all());

        // Obtener el parámetro
        $clientes = $request->input('id_cliente');

        // Log para ver cómo llegó id_cliente
        Log::debug('Valor inicial de id_cliente:', ['id_cliente' => $clientes]);

        // Asegurarse de que sea array
        if (! is_array($clientes)) {
            Log::debug('id_cliente no es array, se convierte a array.');
            $clientes = [$clientes];
        }

        // Validar manualmente
        foreach ($clientes as $clienteId) {
            Log::debug('Validando cliente_id:', ['clienteId' => $clienteId]);

            if (! is_numeric($clienteId)) {
                Log::warning('id_cliente no numérico:', ['clienteId' => $clienteId]);
                return response()->json([
                    'message' => "El id_cliente debe ser numérico.",
                ], 422);
            }

            if (! ClienteTalent::where('id', $clienteId)->exists()) {
                Log::warning('Cliente no encontrado:', ['clienteId' => $clienteId]);
                return response()->json([
                    'message' => "El cliente con id {$clienteId} no existe.",
                ], 422);
            }
        }

        // Construcción del query
        Log::debug('Todos los clientes son válidos. Construyendo query...');

        $query = PeriodoNomina::with('prenominaEmpleados')
            ->where('id_portal', $request->id_portal)
            ->whereIn('id_cliente', $clientes);

        if ($request->filled('estatus')) {
            Log::debug('Filtrando por estatus:', ['estatus' => $request->estatus]);
            $query->where('estatus', $request->estatus);
        }

        if ($request->filled('tipo_nomina')) {
            Log::debug('Filtrando por tipo_nomina:', ['tipo_nomina' => $request->tipo_nomina]);
            $query->where('tipo_nomina', $request->tipo_nomina);
        }

        if ($request->filled('fecha_inicio')) {
            Log::debug('Filtrando por fecha_inicio >=', ['fecha_inicio' => $request->fecha_inicio]);
            $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            Log::debug('Filtrando por fecha_fin <=', ['fecha_fin' => $request->fecha_fin]);
            $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
        }

        $resultados = $query->orderBy('fecha_inicio', 'desc')->get();

        Log::debug('Consulta finalizada. Resultados obtenidos:', ['total' => $resultados->count()]);

        return response()->json($resultados);
    }

}
