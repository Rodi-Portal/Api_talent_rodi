<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Checador\ChecadorHorarioPlantilla;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ChecadorHorarioPlantillaController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $validated['id_cliente']]
            );

        $horarios = ChecadorHorarioPlantilla::with('detalles')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $clientIds)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $horarios,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validar($request, true);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $data['id_cliente']]
            );

        $data['id_portal']  = (int) $administrator->id_portal;
        $data['id_cliente'] = (int) $clientIds[0];

        $horario = DB::connection('portal_main')
            ->transaction(function () use (
                $data,
                $administrator,
                $request
            ) {
                $horario = ChecadorHorarioPlantilla::create([
                    'id_portal'                   => $data['id_portal'],
                    'id_cliente'                  => $data['id_cliente'],
                    'nombre'                      => $data['nombre'],
                    'descripcion'                 => $data['descripcion'] ?? null,
                    'timezone'                    => $data['timezone'] ?? 'America/Mexico_City',
                    'tolerancia_entrada_min'      =>
                    $data['tolerancia_entrada_min'] ?? 0,
                    'tolerancia_salida_min'       =>
                    $data['tolerancia_salida_min'] ?? 0,
                    'permite_descanso'            =>
                    $data['permite_descanso'] ?? false,
                    'minutos_descanso_permitidos' =>
                    $data['minutos_descanso_permitidos'] ?? 60,
                    'activo'                      => 1,
                ]);

                if (empty($horario->codigo)) {
                    $horario->update([
                        'codigo' => 'HOR-' . str_pad(
                            (string) $horario->id,
                            6,
                            '0',
                            STR_PAD_LEFT
                        ),
                    ]);
                }

                $this->guardarDetalles(
                    $horario,
                    $data['detalles'] ?? []
                );

                $horario->load('detalles');

                $this->auditoria->registrar([
                    'id_portal'    =>
                    (int) $administrator->id_portal,
                    'id_cliente'   =>
                    (int) $horario->id_cliente,
                    'actor_tipo'   => 'administrador',
                    'actor_id'     =>
                    (int) $administrator->id,
                    'actor_nombre' =>
                    $this->administratorName($administrator),
                    'modulo'       => 'comunicacion360',
                    'entidad_tipo' => 'horario_checada',
                    'entidad_id'   => (int) $horario->id,
                    'accion'       => 'crear',
                    'resultado'    => 'exitoso',
                    'descripcion'  =>
                    'Horario del checador creado.',
                    'datos_nuevos' => $horario->toArray(),
                ], $request);

                return $horario;
            });

        return response()->json([
            'ok'      => true,
            'message' => 'Horario creado correctamente.',
            'data'    => $horario,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $data          = $this->validar($request, false);
        $administrator = $this->administrator($request);
        $horario       = ChecadorHorarioPlantilla::query()
            ->where('id', (int) $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $horario) {
            return response()->json([
                'ok'      => false,
                'message' => 'El horario no existe.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $horario->id_cliente]
        );

        $datosAnteriores = $horario
            ->load('detalles')
            ->toArray();
        DB::connection('portal_main')->transaction(function () use ($horario, $data) {
            $horario->update([
                'nombre'                      => $data['nombre'],
                'descripcion'                 => $data['descripcion'] ?? null,
                'timezone'                    => $data['timezone'] ?? 'America/Mexico_City',
                'tolerancia_entrada_min'      => $data['tolerancia_entrada_min'] ?? 0,
                'tolerancia_salida_min'       => $data['tolerancia_salida_min'] ?? 0,
                'permite_descanso'            => $data['permite_descanso'] ?? false,
                'minutos_descanso_permitidos' =>
                $data['minutos_descanso_permitidos'] ?? 60,
            ]);

            $horario->detalles()->delete();

            $this->guardarDetalles($horario, $data['detalles'] ?? []);
        });

        $horario = $horario->fresh('detalles');

        $datosNuevos = $horario->toArray();

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $horario->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'horario_checada',
            'entidad_id'       => (int) $horario->id,
            'accion'           => 'actualizar',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Horario del checador actualizado.',
            'datos_anteriores' =>
            $datosAnteriores,
            'datos_nuevos'     =>
            $datosNuevos,
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' => 'Horario actualizado correctamente.',
            'data'    => $horario,
        ]);
    }

    public function cambiarEstado(Request $request, $id)
    {
        $administrator = $this->administrator($request);
        $data          = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        $horario = ChecadorHorarioPlantilla::query()
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $horario) {
            return response()->json([
                'ok'      => false,
                'message' => 'El horario no existe.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $horario->id_cliente]
        );
        $estadoAnterior = (bool) $horario->activo;
        $estadoNuevo    = (bool) $data['activo'];
        $horario->update([
            'activo' => $estadoNuevo,
        ]);

        $horario->load('detalles');
        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $horario->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'horario_checada',
            'entidad_id'       => (int) $horario->id,
            'accion'           => 'cambiar_estado',
            'resultado'        => 'exitoso',
            'descripcion'      => $estadoNuevo
                ? 'Horario de checada activado.'
                : 'Horario de checada desactivado.',
            'datos_anteriores' => [
                'activo' => $estadoAnterior,
            ],
            'datos_nuevos'     => [
                'activo' => $estadoNuevo,
            ],
        ], $request);
        return response()->json([
            'ok'      => true,
            'message' => $data['activo']
                ? 'Horario activado correctamente.'
                : 'Horario desactivado correctamente.',
            'data'    => $horario,
        ]);
    }

    private function validar(
        Request $request,
        bool $requireClient
    ): array {
        return $request->validate([
            'id_cliente'                  => [
                $requireClient ? 'required' : 'sometimes',
                'integer',
                'min:1',
            ],
            'nombre'                      => ['required', 'string', 'max:150'],
            'descripcion'                 => ['nullable', 'string'],
            'timezone'                    => ['nullable', 'string', 'max:80'],
            'tolerancia_entrada_min'      => ['nullable', 'integer', 'min:0'],
            'tolerancia_salida_min'       => ['nullable', 'integer', 'min:0'],
            'permite_descanso'            => ['nullable', 'boolean'],
            'minutos_descanso_permitidos' => [
                'nullable',
                'integer',
                'min:0',
                'max:1440',
            ],
            'detalles'                    => ['required', 'array'],
            'detalles.*.dia_semana'       => ['required', 'integer', 'between:0,6'],
            'detalles.*.labora'           => ['required', 'boolean'],
            'detalles.*.hora_entrada'     => ['nullable', 'date_format:H:i'],
            'detalles.*.hora_salida'      => ['nullable', 'date_format:H:i'],
            'detalles.*.descanso_inicio'  => ['nullable', 'date_format:H:i'],
            'detalles.*.descanso_fin'     => ['nullable', 'date_format:H:i'],
            'detalles.*.orden'            => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function guardarDetalles(ChecadorHorarioPlantilla $horario, array $detalles): void
    {
        foreach ($detalles as $index => $detalle) {
            $labora = (bool) ($detalle['labora'] ?? false);

            $horario->detalles()->create([
                'dia_semana'      => $detalle['dia_semana'],
                'labora'          => $labora ? 1 : 0,
                'hora_entrada'    => $labora ? ($detalle['hora_entrada'] ?? null) : null,
                'hora_salida'     => $labora ? ($detalle['hora_salida'] ?? null) : null,
                'descanso_inicio' => null,
                'descanso_fin'    => null,
                'orden'           => $detalle['orden'] ?? $index,
            ]);
        }
    }

    private function administratorName(
        AdministradorAuth $administrator
    ): string {
        return trim(collect([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])->filter()->implode(' '));
    }

    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
