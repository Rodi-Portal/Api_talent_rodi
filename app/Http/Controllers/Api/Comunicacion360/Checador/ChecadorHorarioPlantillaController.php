<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecadorHorarioPlantillaController extends Controller
{
    public function index(Request $request)
    {
        $query = ChecadorHorarioPlantilla::with('detalles');

        if ($request->filled('id_portal')) {
            $query->where('id_portal', $request->input('id_portal'));
        }

        if ($request->filled('id_cliente')) {
            $query->where('id_cliente', $request->input('id_cliente'));
        }

        $horarios = $query
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $horarios,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request);

        $horario = DB::connection('portal_main')->transaction(function () use ($data) {
            $horario = ChecadorHorarioPlantilla::create([
                'id_portal' => $data['id_portal'],
                'id_cliente' => $data['id_cliente'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'timezone' => $data['timezone'] ?? 'America/Mexico_City',
                'tolerancia_entrada_min' => $data['tolerancia_entrada_min'] ?? 0,
                'tolerancia_salida_min' => $data['tolerancia_salida_min'] ?? 0,
                'permite_descanso' => $data['permite_descanso'] ?? false,
                'activo' => 1,
            ]);

            $this->guardarDetalles($horario, $data['detalles'] ?? []);

            return $horario;
        });

        $horario->load('detalles');

        return response()->json([
            'ok' => true,
            'message' => 'Horario creado correctamente.',
            'data' => $horario,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $data = $this->validar($request);

        $horario = ChecadorHorarioPlantilla::where('id', $id)->first();

        if (! $horario) {
            return response()->json([
                'ok' => false,
                'message' => 'El horario no existe.',
            ], 404);
        }

        DB::connection('portal_main')->transaction(function () use ($horario, $data) {
            $horario->update([
                'id_portal' => $data['id_portal'],
                'id_cliente' => $data['id_cliente'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'timezone' => $data['timezone'] ?? 'America/Mexico_City',
                'tolerancia_entrada_min' => $data['tolerancia_entrada_min'] ?? 0,
                'tolerancia_salida_min' => $data['tolerancia_salida_min'] ?? 0,
                'permite_descanso' => $data['permite_descanso'] ?? false,
            ]);

            $horario->detalles()->delete();

            $this->guardarDetalles($horario, $data['detalles'] ?? []);
        });

        $horario->load('detalles');

        return response()->json([
            'ok' => true,
            'message' => 'Horario actualizado correctamente.',
            'data' => $horario,
        ]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $horario = ChecadorHorarioPlantilla::where('id', $id)->first();

        if (! $horario) {
            return response()->json([
                'ok' => false,
                'message' => 'El horario no existe.',
            ], 404);
        }

        $horario->update([
            'activo' => $data['activo'] ? 1 : 0,
        ]);

        $horario->load('detalles');

        return response()->json([
            'ok' => true,
            'message' => $data['activo']
                ? 'Horario activado correctamente.'
                : 'Horario desactivado correctamente.',
            'data' => $horario,
        ]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'id_portal' => ['required', 'integer'],
            'id_cliente' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'tolerancia_entrada_min' => ['nullable', 'integer', 'min:0'],
            'tolerancia_salida_min' => ['nullable', 'integer', 'min:0'],
            'permite_descanso' => ['nullable', 'boolean'],

            'detalles' => ['required', 'array'],
            'detalles.*.dia_semana' => ['required', 'integer', 'between:0,6'],
            'detalles.*.labora' => ['required', 'boolean'],
            'detalles.*.hora_entrada' => ['nullable', 'date_format:H:i'],
            'detalles.*.hora_salida' => ['nullable', 'date_format:H:i'],
            'detalles.*.descanso_inicio' => ['nullable', 'date_format:H:i'],
            'detalles.*.descanso_fin' => ['nullable', 'date_format:H:i'],
            'detalles.*.orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function guardarDetalles(ChecadorHorarioPlantilla $horario, array $detalles): void
    {
        foreach ($detalles as $index => $detalle) {
            $labora = (bool) ($detalle['labora'] ?? false);

            $horario->detalles()->create([
                'dia_semana' => $detalle['dia_semana'],
                'labora' => $labora ? 1 : 0,
                'hora_entrada' => $labora ? ($detalle['hora_entrada'] ?? null) : null,
                'hora_salida' => $labora ? ($detalle['hora_salida'] ?? null) : null,
                'descanso_inicio' => null,
                'descanso_fin' => null,
                'orden' => $detalle['orden'] ?? $index,
            ]);
        }
    }
}