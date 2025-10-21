<?php

namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\PoliticaAsistencia;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PoliticasAsistenciaController extends Controller
{
    /**
     * GET /api/politicas-asistencia
     * Lista políticas con filtros por portal/sucursal/estado y paginación.
     *
     * Query params:
     * - id_portal (int, requerido)
     * - id_cliente (int|null)
     * - estado (borrador|publicada)
     * - q (string - busca por nombre)
     * - per_page (int)
     */
    public function index(Request $request)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
            'id_cliente' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(['borrador', 'publicada'])],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = $data['per_page'] ?? 20;

        $query = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->when(isset($data['id_cliente']), fn($q) => $q->where(function ($qq) use ($data) {
                // si envías id_cliente: muestra primero la política de esa sucursal; si no hay, puedes incluir globales con otro endpoint
                $qq->where('id_cliente', $data['id_cliente']);
            }))
            ->when(!isset($data['id_cliente']), fn($q) => $q->orderByRaw('id_cliente IS NULL ASC')) // primero sucursales, luego globales
            ->when(isset($data['estado']), fn($q) => $q->where('estado', $data['estado']))
            ->when(isset($data['q']), fn($q) => $q->where('nombre', 'like', '%' . $data['q'] . '%'))
            ->orderBy('id_cliente')                // agrupa por sucursal / global
            ->orderByDesc('actualizado_en');

        $result = $query->paginate($perPage)->appends($request->query());

        return response()->json([
            'ok' => true,
            'data' => $result->items(),
            'pagination' => [
                'total' => $result->total(),
                'per_page' => $result->perPage(),
                'current_page' => $result->currentPage(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/politicas-asistencia/{id}
     * Muestra una política por ID (validando que pertenezca al portal).
     *
     * Query param:
     * - id_portal (int, requerido para asegurar multitenancy)
     */
    public function show(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (!$politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        return response()->json(['ok' => true, 'data' => $politica]);
    }

    /**
     * POST /api/politicas-asistencia
     * Crea una política nueva.
     *
     * Body JSON requerido (campos clave):
     * - id_portal (int), nombre (string), hora_entrada (HH:ii:ss), hora_salida (HH:ii:ss)
     * - id_cliente (int|null)
     * - demás campos son opcionales con defaults
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
            'id_cliente' => ['nullable', 'integer'],
            'nombre' => ['required', 'string', 'max:120'],

            'hora_entrada' => ['required', 'date_format:H:i:s'],
            'hora_salida'  => ['required', 'date_format:H:i:s'],

            'trabaja_sabado'  => ['nullable', 'boolean'],
            'trabaja_domingo' => ['nullable', 'boolean'],

            'tolerancia_minutos' => ['nullable', 'integer', 'min:0', 'max:600'],
            'retardos_por_falta' => ['nullable', 'integer', 'min:1', 'max:50'],
            'contar_salida_temprano' => ['nullable', 'boolean'],

            'descuento_retardo_modo'  => ['nullable', Rule::in(['ninguno','por_evento','por_minuto'])],
            'descuento_retardo_valor' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'descuento_falta_modo'  => ['nullable', Rule::in(['ninguno','porcentaje_dia','fijo'])],
            'descuento_falta_valor' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'calcular_extras'       => ['nullable', 'boolean'],
            'criterio_extra'        => ['nullable', Rule::in(['sobre_salida','sobre_horas_dia'])],
            'horas_dia_empleado'    => ['nullable', 'numeric', 'min:1', 'max:24'],
            'minutos_gracia_extra'  => ['nullable', 'integer', 'min:0', 'max:300'],
            'tope_horas_extra'      => ['nullable', 'numeric', 'min:0', 'max:24'],

            'estado' => ['nullable', Rule::in(['borrador', 'publicada'])],
        ]);

        // Valores por omisión (por si no vienen)
        $data = array_merge([
            'trabaja_sabado' => false,
            'trabaja_domingo' => false,
            'tolerancia_minutos' => 10,
            'retardos_por_falta' => 3,
            'contar_salida_temprano' => false,

            'descuento_retardo_modo' => 'ninguno',
            'descuento_retardo_valor' => 0.00,
            'descuento_falta_modo' => 'ninguno',
            'descuento_falta_valor' => 0.00,

            'calcular_extras' => false,
            'criterio_extra' => 'sobre_salida',
            'horas_dia_empleado' => 8.00,
            'minutos_gracia_extra' => 0,
            'tope_horas_extra' => 0.00,

            'estado' => 'publicada',
        ], $data);

        $politica = PoliticaAsistencia::create($data);

        return response()->json([
            'ok' => true,
            'message' => 'Política creada correctamente.',
            'data' => $politica,
        ], 201);
    }

    /**
     * PUT /api/politicas-asistencia/{id}
     * (Opcional) Actualiza una política existente.
     */
    public function update(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'], // para verificar pertenencia
            'id_cliente' => ['nullable', 'integer'],
            'nombre' => ['nullable', 'string', 'max:120'],

            'hora_entrada' => ['nullable', 'date_format:H:i:s'],
            'hora_salida'  => ['nullable', 'date_format:H:i:s'],

            'trabaja_sabado'  => ['nullable', 'boolean'],
            'trabaja_domingo' => ['nullable', 'boolean'],

            'tolerancia_minutos' => ['nullable', 'integer', 'min:0', 'max:600'],
            'retardos_por_falta' => ['nullable', 'integer', 'min:1', 'max:50'],
            'contar_salida_temprano' => ['nullable', 'boolean'],

            'descuento_retardo_modo'  => ['nullable', Rule::in(['ninguno','por_evento','por_minuto'])],
            'descuento_retardo_valor' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'descuento_falta_modo'  => ['nullable', Rule::in(['ninguno','porcentaje_dia','fijo'])],
            'descuento_falta_valor' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],

            'calcular_extras'       => ['nullable', 'boolean'],
            'criterio_extra'        => ['nullable', Rule::in(['sobre_salida','sobre_horas_dia'])],
            'horas_dia_empleado'    => ['nullable', 'numeric', 'min:1', 'max:24'],
            'minutos_gracia_extra'  => ['nullable', 'integer', 'min:0', 'max:300'],
            'tope_horas_extra'      => ['nullable', 'numeric', 'min:0', 'max:24'],

            'estado' => ['nullable', Rule::in(['borrador', 'publicada'])],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (!$politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $politica->fill($data);
        $politica->save();

        return response()->json(['ok' => true, 'message' => 'Política actualizada.', 'data' => $politica]);
    }

    /**
     * DELETE /api/politicas-asistencia/{id}
     * (Opcional) Eliminación lógica/física; aquí física por simplicidad.
     */
    public function destroy(Request $request, int $id)
    {
        $data = $request->validate([
            'id_portal' => ['required', 'integer'],
        ]);

        $politica = PoliticaAsistencia::query()
            ->delPortal($data['id_portal'])
            ->where('id', $id)
            ->first();

        if (!$politica) {
            return response()->json(['ok' => false, 'message' => 'Política no encontrada.'], 404);
        }

        $politica->delete();

        return response()->json(['ok' => true, 'message' => 'Política eliminada.']);
    }
}
