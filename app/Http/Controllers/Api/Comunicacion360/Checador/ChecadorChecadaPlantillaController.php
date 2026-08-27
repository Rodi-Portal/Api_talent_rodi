<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\Comunicacion360\Checador\ChecadorChecadaPlantilla;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChecadorChecadaPlantillaController extends Controller
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

        $plantillas = ChecadorChecadaPlantilla::with([
            'metodos',
            'ubicaciones',
            'horarios',
            'aprobadores',
        ])
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $clientIds)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => $plantillas,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([

            'id_cliente'                          => ['required', 'integer', 'min:1'],
            'nombre'                              => ['required', 'string', 'max:150'],
            'descripcion'                         => ['nullable', 'string'],
            'regla_fuera_ubicacion'               => ['required', 'in:permitir,advertir,bloquear'],
            'requiere_ubicacion'                  => ['required', 'boolean'],
            'requiere_dispositivo'                => ['required', 'boolean'],
            'permite_offline'                     => ['required', 'boolean'],
            'permite_manual_admin'                => ['required', 'boolean'],
            'metodos'                             => ['nullable', 'array'],
            'metodos.*.id_metodo'                 => ['required_with:metodos', 'integer'],
            'metodos.*.obligatorio'               => ['nullable', 'boolean'],

            'ubicaciones'                         => ['nullable', 'array'],
            'ubicaciones.*.id_ubicacion'          => ['required_with:ubicaciones', 'integer'],
            'ubicaciones.*.obligatorio'           => ['nullable', 'boolean'],
            'horarios'                            => ['nullable', 'array'],
            'horarios.*.id_horario_plantilla'     => ['required_with:horarios', 'integer'],
            'horarios.*.obligatorio'              => ['nullable', 'boolean'],
            'aprobadores'                         => ['nullable', 'array'],
            'aprobadores.*.tipo_evento_id'        => ['required_with:aprobadores', 'integer'],
            'aprobadores.*.id_empleado_aprobador' => ['required_with:aprobadores', 'integer'],
            'aprobadores.*.nivel'                 => ['required_with:aprobadores', 'integer', 'min:1'],
            'aprobadores.*.obligatorio'           => ['nullable', 'boolean'],
            'aprobadores.*.activo'                => ['nullable', 'boolean'],
        ]);
        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                [(int) $data['id_cliente']]
            );

        $idCliente = (int) $clientIds[0];

        $data['id_portal']  = (int) $administrator->id_portal;
        $data['id_cliente'] = $idCliente;

        $this->validateRelatedResources(
            $data,
            $administrator,
            $idCliente
        );
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
            foreach ($data['aprobadores'] ?? [] as $aprobador) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_aprobadores')
                    ->insert([
                        'id_plantilla'          => $plantilla->id,
                        'tipo_evento_id'        => $aprobador['tipo_evento_id'],
                        'id_empleado_aprobador' => $aprobador['id_empleado_aprobador'],
                        'nivel'                 => $aprobador['nivel'],
                        'obligatorio'           => $aprobador['obligatorio'] ?? 1,
                        'activo'                => $aprobador['activo'] ?? 1,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
            }

            return $plantilla;
        });

        $plantilla->load([
            'metodos',
            'ubicaciones',
            'horarios',
            'aprobadores',
        ]);
        $this->auditoria->registrar([
            'id_portal'    =>
            (int) $administrator->id_portal,
            'id_cliente'   =>
            (int) $plantilla->id_cliente,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'plantilla_checada',
            'entidad_id'   => (int) $plantilla->id,
            'accion'       => 'crear',
            'resultado'    => 'exitoso',
            'descripcion'  =>
            'Plantilla de checada creada.',
            'datos_nuevos' => [
                'nombre'                =>
                $plantilla->nombre,
                'id_cliente'            =>
                (int) $plantilla->id_cliente,
                'regla_fuera_ubicacion' =>
                $plantilla->regla_fuera_ubicacion,
                'requiere_ubicacion'    =>
                (bool) $plantilla->requiere_ubicacion,
                'requiere_dispositivo'  =>
                (bool) $plantilla->requiere_dispositivo,
                'permite_offline'       =>
                (bool) $plantilla->permite_offline,
                'permite_manual_admin'  =>
                (bool) $plantilla->permite_manual_admin,
                'metodos'               =>
                $data['metodos'] ?? [],
                'ubicaciones'           =>
                $data['ubicaciones'] ?? [],
                'horarios'              =>
                $data['horarios'] ?? [],
                'aprobadores'           =>
                $data['aprobadores'] ?? [],
            ],
        ], $request);
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
        $administrator = $this->administrator($request);
        $plantilla     = ChecadorChecadaPlantilla::query()
            ->where('id', (int) $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('activo', 1)
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe o está inactiva.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $plantilla->id_cliente]
        );

        $this->validateRelatedResources(
            $data,
            $administrator,
            (int) $plantilla->id_cliente
        );

        $conexion = DB::connection('portal_main');

        $metodosAnteriores = $conexion
            ->table('checador_checada_plantilla_metodos')
            ->where(
                'id_plantilla',
                (int) $plantilla->id
            )
            ->orderBy('orden')
            ->get([
                'id_metodo',
                'obligatorio',
                'orden',
                'activo',
            ])
            ->map(fn($item) => [
                'id_metodo'   =>
                (int) $item->id_metodo,
                'obligatorio' =>
                (bool) $item->obligatorio,
                'orden'       =>
                (int) $item->orden,
                'activo'      =>
                (bool) $item->activo,
            ])
            ->values()
            ->all();
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
        $metodosNuevos = $conexion
            ->table('checador_checada_plantilla_metodos')
            ->where(
                'id_plantilla',
                (int) $plantilla->id
            )
            ->orderBy('orden')
            ->get([
                'id_metodo',
                'obligatorio',
                'orden',
                'activo',
            ])
            ->map(fn($item) => [
                'id_metodo'   =>
                (int) $item->id_metodo,
                'obligatorio' =>
                (bool) $item->obligatorio,
                'orden'       =>
                (int) $item->orden,
                'activo'      =>
                (bool) $item->activo,
            ])
            ->values()
            ->all();

        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $plantilla->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'plantilla_checada',
            'entidad_id'       => (int) $plantilla->id,
            'accion'           => 'actualizar_metodos',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Métodos de la plantilla de checada actualizados.',
            'datos_anteriores' => [
                'metodos' => $metodosAnteriores,
            ],
            'datos_nuevos'     => [
                'metodos' => $metodosNuevos,
            ],
        ], $request);
        return response()->json([
            'ok'      => true,
            'message' => 'Métodos asignados correctamente.',
        ]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->validate([

            'nombre'                              => ['required', 'string', 'max:150'],
            'descripcion'                         => ['nullable', 'string'],

            'regla_fuera_ubicacion'               => ['required', 'in:permitir,advertir,bloquear'],
            'requiere_ubicacion'                  => ['required', 'boolean'],
            'requiere_dispositivo'                => ['required', 'boolean'],
            'permite_offline'                     => ['required', 'boolean'],
            'permite_manual_admin'                => ['required', 'boolean'],

            'metodos'                             => ['nullable', 'array'],
            'metodos.*.id_metodo'                 => ['required_with:metodos', 'integer'],
            'metodos.*.obligatorio'               => ['nullable', 'boolean'],
            'ubicaciones'                         => ['nullable', 'array'],
            'ubicaciones.*.id_ubicacion'          => ['required_with:ubicaciones', 'integer'],
            'ubicaciones.*.obligatorio'           => ['nullable', 'boolean'],
            'horarios'                            => ['nullable', 'array'],
            'horarios.*.id_horario_plantilla'     => ['required_with:horarios', 'integer'],
            'horarios.*.obligatorio'              => ['nullable', 'boolean'],
            'aprobadores'                         => ['nullable', 'array'],
            'aprobadores.*.tipo_evento_id'        => ['required_with:aprobadores', 'integer'],
            'aprobadores.*.id_empleado_aprobador' => ['required_with:aprobadores', 'integer'],
            'aprobadores.*.nivel'                 => ['required_with:aprobadores', 'integer', 'min:1'],
            'aprobadores.*.obligatorio'           => ['nullable', 'boolean'],
            'aprobadores.*.activo'                => ['nullable', 'boolean'],

        ]);
        $administrator = $this->administrator($request);

        $plantilla = ChecadorChecadaPlantilla::query()
            ->where('id', (int) $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $plantilla->id_cliente]
        );

        $data['id_portal'] =
        (int) $administrator->id_portal;
        $data['id_cliente'] =
        (int) $plantilla->id_cliente;

        $this->validateRelatedResources(
            $data,
            $administrator,
            (int) $plantilla->id_cliente
        );

        $datosAnteriores = $plantilla
            ->load([
                'metodos',
                'ubicaciones',
                'horarios',
                'aprobadores',
            ])
            ->toArray();

        DB::connection('portal_main')->transaction(function () use ($plantilla, $data) {
            $plantilla->update([
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

            if (array_key_exists('aprobadores', $data)) {
                DB::connection('portal_main')
                    ->table('checador_checada_plantilla_aprobadores')
                    ->where('id_plantilla', $plantilla->id)
                    ->delete();

                foreach ($data['aprobadores'] as $aprobador) {
                    DB::connection('portal_main')
                        ->table('checador_checada_plantilla_aprobadores')
                        ->insert([
                            'id_plantilla'          => $plantilla->id,
                            'tipo_evento_id'        => $aprobador['tipo_evento_id'],
                            'id_empleado_aprobador' => $aprobador['id_empleado_aprobador'],
                            'nivel'                 => $aprobador['nivel'],
                            'obligatorio'           => $aprobador['obligatorio'] ?? 1,
                            'activo'                => $aprobador['activo'] ?? 1,
                            'created_at'            => now(),
                            'updated_at'            => now(),
                        ]);
                }
            }
        });

        $plantilla = $plantilla->fresh([
            'metodos',
            'ubicaciones',
            'horarios',
            'aprobadores',
        ]);

        $datosNuevos = $plantilla->toArray();
        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $plantilla->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'plantilla_checada',
            'entidad_id'       => (int) $plantilla->id,
            'accion'           => 'actualizar',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Plantilla de checada actualizada.',
            'datos_anteriores' =>
            $datosAnteriores,
            'datos_nuevos'     =>
            $datosNuevos,
        ], $request);
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
        $administrator = $this->administrator($request);
        $plantilla     = ChecadorChecadaPlantilla::query()
            ->where('id', (int) $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no existe.',
            ], 404);
        }
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $plantilla->id_cliente]
        );

        $estadoAnterior = (bool) $plantilla->activo;
        $estadoNuevo    = (bool) $data['activo'];
        $plantilla->update([
            'activo' => $data['activo'] ? 1 : 0,
        ]);

        $plantilla->load([
            'metodos',
            'ubicaciones',
            'horarios',
            'aprobadores',
        ]);
        $this->auditoria->registrar([
            'id_portal'        =>
            (int) $administrator->id_portal,
            'id_cliente'       =>
            (int) $plantilla->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'plantilla_checada',
            'entidad_id'       => (int) $plantilla->id,
            'accion'           => 'cambiar_estado',
            'resultado'        => 'exitoso',
            'descripcion'      => $estadoNuevo
                ? 'Plantilla de checada activada.'
                : 'Plantilla de checada desactivada.',
            'datos_anteriores' => [
                'activo' => $estadoAnterior,
            ],
            'datos_nuevos'     => [
                'activo' => $estadoNuevo,
            ],
        ], $request);
        return response()->json([
            'ok'      => true,
            'message' => $estadoNuevo
                ? 'Plantilla activada correctamente.'
                : 'Plantilla desactivada correctamente.',
            'data'    => $plantilla,
        ]);
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
    private function validateRelatedResources(
        array $data,
        AdministradorAuth $administrator,
        int $idCliente
    ): void {
        $idPortal = (int) $administrator->id_portal;
        $conexion = DB::connection('portal_main');

        $methodIds = collect($data['metodos'] ?? [])
            ->pluck('id_metodo')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($methodIds->isNotEmpty()) {
            $validMethodCount = $conexion
                ->table('checador_metodos')
                ->whereIn('id', $methodIds->all())
                ->where('activo', 1)
                ->where(function ($query) use (
                    $idPortal,
                    $idCliente
                ) {
                    $query
                        ->where(function ($scope) {
                            $scope
                                ->whereNull('id_portal')
                                ->whereNull('id_cliente');
                        })
                        ->orWhere(function ($scope) use (
                            $idPortal,
                            $idCliente
                        ) {
                            $scope
                                ->where('id_portal', $idPortal)
                                ->where(function ($clientScope) use (
                                    $idCliente
                                ) {
                                    $clientScope
                                        ->whereNull('id_cliente')
                                        ->orWhere(
                                            'id_cliente',
                                            $idCliente
                                        );
                                });
                        });
                })
                ->distinct()
                ->count('id');

            if ($validMethodCount !== $methodIds->count()) {
                throw ValidationException::withMessages([
                    'metodos' => [
                        'La selección contiene métodos no autorizados.',
                    ],
                ]);
            }
        }

        $locationIds = collect($data['ubicaciones'] ?? [])
            ->pluck('id_ubicacion')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($locationIds->isNotEmpty()) {
            $validLocationCount = $conexion
                ->table('checador_ubicaciones')
                ->whereIn('id', $locationIds->all())
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('activa', 1)
                ->distinct()
                ->count('id');

            if ($validLocationCount !== $locationIds->count()) {
                throw ValidationException::withMessages([
                    'ubicaciones' => [
                        'La selección contiene ubicaciones no autorizadas.',
                    ],
                ]);
            }
        }

        $scheduleIds = collect($data['horarios'] ?? [])
            ->pluck('id_horario_plantilla')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($scheduleIds->isNotEmpty()) {
            $validScheduleCount = $conexion
                ->table('checador_horario_plantillas')
                ->whereIn('id', $scheduleIds->all())
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('activo', 1)
                ->distinct()
                ->count('id');

            if ($validScheduleCount !== $scheduleIds->count()) {
                throw ValidationException::withMessages([
                    'horarios' => [
                        'La selección contiene horarios no autorizados.',
                    ],
                ]);
            }
        }

        $approverIds = collect($data['aprobadores'] ?? [])
            ->pluck('id_empleado_aprobador')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($approverIds->isNotEmpty()) {
            $permittedClientIds = $this->clientScope
                ->permittedClientIds($administrator);

            $validApproverCount = $permittedClientIds === []
                ? 0
                : $conexion
                ->table('empleados')
                ->whereIn('id', $approverIds->all())
                ->where('id_portal', $idPortal)
                ->whereIn(
                    'id_cliente',
                    $permittedClientIds
                )
                ->where('status', 1)
                ->where('eliminado', 0)
                ->distinct()
                ->count('id');

            if ($validApproverCount !== $approverIds->count()) {
                throw ValidationException::withMessages([
                    'aprobadores' => [
                        'La selección contiene aprobadores no autorizados.',
                    ],
                ]);
            }
        }

        $eventTypeIds = collect($data['aprobadores'] ?? [])
            ->pluck('tipo_evento_id')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($eventTypeIds->isNotEmpty()) {
            $validEventTypeCount = $conexion
                ->table('eventos_option')
                ->whereIn('id', $eventTypeIds->all())
                ->where(function ($query) use ($idPortal) {
                    $query
                        ->whereNull('id_portal')
                        ->orWhere('id_portal', $idPortal);
                })
                ->distinct()
                ->count('id');

            if ($validEventTypeCount !== $eventTypeIds->count()) {
                throw ValidationException::withMessages([
                    'aprobadores' => [
                        'La selección contiene tipos de evento no autorizados.',
                    ],
                ]);
            }
        }
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
