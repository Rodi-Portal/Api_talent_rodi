<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorChecadaPlantilla;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecadorChecadaPlantillaController extends Controller
{
    public function index(Request $request)
    {
        $idPortal  = $request->input('id_portal');
        $idCliente = $request->input('id_cliente');

        $query = ChecadorChecadaPlantilla::with([
            'metodos',
            'ubicaciones',
            'horarios.detalles',
        ]);
        if ($idPortal) {
            $query->where('id_portal', $idPortal);
        }

        if ($idCliente) {

            // 👇 soporta array o valor único
            if (is_array($idCliente)) {
                $query->whereIn('id_cliente', $idCliente);
            } else {
                $query->where('id_cliente', $idCliente);
            }
        }

        $plantillas = $query
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $plantillas,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_portal'                       => ['required', 'integer'],
            'id_cliente'                      => ['required', 'integer'],
            'nombre'                          => ['required', 'string', 'max:150'],
            'descripcion'                     => ['nullable', 'string'],
            'regla_fuera_ubicacion'           => ['required', 'in:permitir,advertir,bloquear'],
            'requiere_ubicacion'              => ['required', 'boolean'],
            'requiere_dispositivo'            => ['required', 'boolean'],
            'permite_offline'                 => ['required', 'boolean'],
            'permite_manual_admin'            => ['required', 'boolean'],
            'metodos'                         => ['nullable', 'array'],
            'metodos.*.id_metodo'             => ['required_with:metodos', 'integer'],
            'metodos.*.obligatorio'           => ['nullable', 'boolean'],

            'ubicaciones'                     => ['nullable', 'array'],
            'ubicaciones.*.id_ubicacion'      => ['required_with:ubicaciones', 'integer'],
            'ubicaciones.*.obligatorio'       => ['nullable', 'boolean'],
            'horarios'                        => ['nullable', 'array'],
            'horarios.*.id_horario_plantilla' => ['required_with:horarios', 'integer'],
            'horarios.*.obligatorio'          => ['nullable', 'boolean'],
        ]);

        $plantilla = DB::connection('portal_main')->transaction(function () use ($data) {
            $plantilla = ChecadorChecadaPlantilla::create([
                'id_portal'             => $data['id_portal'],
                'id_cliente'            => $data['id_cliente'],
                'nombre'                => $data['nombre'],
                'descripcion'           => $data['descripcion'] ?? null,
                'regla_fuera_ubicacion' => $data['regla_fuera_ubicacion'],
                'requiere_ubicacion'    => $data['requiere_ubicacion'],
                'requiere_dispositivo'  => $data['requiere_dispositivo'],
                'permite_offline'       => $data['permite_offline'],
                'permite_manual_admin'  => $data['permite_manual_admin'],
                'activo'                => 1,
            ]);

            foreach ($data['metodos'] ?? [] as $index => $metodo) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_metodos')
                    ->insert([
                        'id_plantilla' => $plantilla->id,
                        'id_metodo'    => $metodo['id_metodo'],
                        'obligatorio'  => $metodo['obligatorio'] ?? 1,
                        'orden'        => $index,
                        'activo'       => 1,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
            }

            foreach ($data['ubicaciones'] ?? [] as $ubicacion) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_ubicaciones')
                    ->insert([
                        'id_plantilla' => $plantilla->id,
                        'id_ubicacion' => $ubicacion['id_ubicacion'],
                        'obligatorio'  => $ubicacion['obligatorio'] ?? 1,
                        'activo'       => 1,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
            }
            foreach ($data['horarios'] ?? [] as $horario) {

                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_horarios')
                    ->insert([
                        'id_plantilla'         => $plantilla->id,
                        'id_horario_plantilla' => $horario['id_horario_plantilla'],
                        'obligatorio'          => $horario['obligatorio'] ?? 1,
                        'activo'               => 1,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ]);
            }

            return $plantilla;
        });

        $plantilla->load([
            'metodos',
            'ubicaciones',
            'horarios.detalles',
        ]);

        return response()->json([
            'ok'      => true,
            'message' => 'Plantilla de checada creada correctamente.',
            'data'    => $plantilla,
        ], 201);
    }

    public function guardarMetodos(Request $request, $id)
    {
        $data = $request->validate([
            'metodos'               => ['required', 'array'],
            'metodos.*.id_metodo'   => ['required', 'integer', 'exists:portal_main.checador_metodos,id'],
            'metodos.*.obligatorio' => ['nullable', 'boolean'],
        ]);

        $plantilla = ChecadorChecadaPlantilla::where('id', $id)
            ->where('activo', 1)
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe o está inactiva.',
            ], 404);
        }

        DB::connection('portal_main')->transaction(function () use ($plantilla, $data) {
            DB::connection('portal_main')
                ->table('checador_checada_plantilla_metodos')
                ->where('id_plantilla', $plantilla->id)
                ->delete();

            foreach ($data['metodos'] as $index => $metodo) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_metodos')
                    ->insert([
                        'id_plantilla' => $plantilla->id,
                        'id_metodo'    => $metodo['id_metodo'],
                        'obligatorio'  => $metodo['obligatorio'] ?? 1,
                        'orden'        => $index,
                        'activo'       => 1,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
            }
        });

        return response()->json([
            'ok'      => true,
            'message' => 'Métodos asignados correctamente.',
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([
            'id_portal'                       => ['required', 'integer'],
            'id_cliente'                      => ['required', 'integer'],

            'nombre'                          => ['required', 'string', 'max:150'],
            'descripcion'                     => ['nullable', 'string'],

            'regla_fuera_ubicacion'           => ['required', 'in:permitir,advertir,bloquear'],
            'requiere_ubicacion'              => ['required', 'boolean'],
            'requiere_dispositivo'            => ['required', 'boolean'],
            'permite_offline'                 => ['required', 'boolean'],
            'permite_manual_admin'            => ['required', 'boolean'],

            'metodos'                         => ['nullable', 'array'],
            'metodos.*.id_metodo'             => ['required_with:metodos', 'integer'],
            'metodos.*.obligatorio'           => ['nullable', 'boolean'],
            'ubicaciones'                     => ['nullable', 'array'],
            'ubicaciones.*.id_ubicacion'      => ['required_with:ubicaciones', 'integer'],
            'ubicaciones.*.obligatorio'       => ['nullable', 'boolean'],
            'horarios'                        => ['nullable', 'array'],
            'horarios.*.id_horario_plantilla' => ['required_with:horarios', 'integer'],
            'horarios.*.obligatorio'          => ['nullable', 'boolean'],
        ]);

        $plantilla = ChecadorChecadaPlantilla::where('id', $id)->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe.',
            ], 404);
        }

        DB::connection('portal_main')->transaction(function () use ($plantilla, $data) {
            $plantilla->update([
                'id_portal'             => $data['id_portal'],
                'id_cliente'            => $data['id_cliente'],
                'nombre'                => $data['nombre'],
                'descripcion'           => $data['descripcion'] ?? null,
                'regla_fuera_ubicacion' => $data['regla_fuera_ubicacion'],
                'requiere_ubicacion'    => $data['requiere_ubicacion'],
                'requiere_dispositivo'  => $data['requiere_dispositivo'],
                'permite_offline'       => $data['permite_offline'],
                'permite_manual_admin'  => $data['permite_manual_admin'],
            ]);

            if (array_key_exists('metodos', $data)) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_metodos')
                    ->where('id_plantilla', $plantilla->id)
                    ->delete();

                foreach ($data['metodos'] as $index => $metodo) {
                    DB::connection('portal_main')
                        ->table('checador_checada_plantilla_metodos')
                        ->insert([
                            'id_plantilla' => $plantilla->id,
                            'id_metodo'    => $metodo['id_metodo'],
                            'obligatorio'  => $metodo['obligatorio'] ?? 1,
                            'orden'        => $index,
                            'activo'       => 1,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                }
            }
            if (array_key_exists('ubicaciones', $data)) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_ubicaciones')
                    ->where('id_plantilla', $plantilla->id)
                    ->delete();

                foreach ($data['ubicaciones'] as $ubicacion) {
                    DB::connection('portal_main')
                        ->table('checador_checada_plantilla_ubicaciones')
                        ->insert([
                            'id_plantilla' => $plantilla->id,
                            'id_ubicacion' => $ubicacion['id_ubicacion'],
                            'obligatorio'  => $ubicacion['obligatorio'] ?? 1,
                            'activo'       => 1,
                            'created_at'   => now(),
                            'updated_at'   => now(),
                        ]);
                }
            }
            if (array_key_exists('horarios', $data)) {

                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_horarios')
                    ->where('id_plantilla', $plantilla->id)
                    ->delete();

                foreach ($data['horarios'] as $horario) {

                    DB::connection('portal_main')
                        ->table('checador_checada_plantilla_horarios')
                        ->insert([
                            'id_plantilla'         => $plantilla->id,
                            'id_horario_plantilla' => $horario['id_horario_plantilla'],
                            'obligatorio'          => $horario['obligatorio'] ?? 1,
                            'activo'               => 1,
                            'created_at'           => now(),
                            'updated_at'           => now(),
                        ]);
                }
            }
        });

        $plantilla->load([
            'metodos',
            'ubicaciones',
            'horarios.detalles',
        ]);
        return response()->json([
            'ok'      => true,
            'message' => 'Plantilla actualizada correctamente.',
            'data'    => $plantilla,
        ]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $data = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $plantilla = ChecadorChecadaPlantilla::where('id', $id)->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe.',
            ], 404);
        }

        $plantilla->update([
            'activo' => $data['activo'] ? 1 : 0,
        ]);

        $plantilla->load([
            'metodos',
            'ubicaciones',
            'horarios.detalles',
        ]);
        return response()->json([
            'ok'      => true,
            'message' => $data['activo']
                ? 'Plantilla activada correctamente.'
                : 'Plantilla desactivada correctamente.',
            'data'    => $plantilla,
        ]);
    }

}
