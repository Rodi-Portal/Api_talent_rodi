<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChecadorAsignacionController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {}
    public function empleadosConAcceso(Request $request)
    {
        $data = $request->validate([
            'sucursales'   => [
                'required',
                'array',
                'min:1',
            ],
            'sucursales.*' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                $data['sucursales']
            );

        $empleados = DB::connection('portal_main')
            ->table('empleados as e')
            ->join('cliente as c', function ($join) {
                $join
                    ->on('c.id', '=', 'e.id_cliente')
                    ->on(
                        'c.id_portal',
                        '=',
                        'e.id_portal'
                    );
            })
            ->select([
                'e.id',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.puesto',
                'e.departamento',
                'e.id_cliente',
                'e.status',
                'e.force_password_change',
                'e.last_login_at',
                'e.password_changed_at',
                'c.nombre as nombre_sucursal',
            ])
            ->where(
                'e.id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('e.id_cliente', $clientIds)
            ->where('e.status', 1)
            ->where('e.eliminado', 0)
            ->whereNotNull('e.password')
            ->where('e.password', '<>', '')
            ->orderBy('c.nombre')
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->orderBy('e.materno')
            ->get();

        $result = $empleados
            ->map(function ($item) {
                $nombreCompleto = trim(collect([
                    $item->nombre,
                    $item->paterno,
                    $item->materno,
                ])->filter()->implode(' '));

                return [
                    'id'                        => (int) $item->id,
                    'id_empleado'               =>
                    $item->id_empleado,
                    'nombre'                    => $item->nombre,
                    'paterno'                   => $item->paterno,
                    'materno'                   => $item->materno,
                    'nombre_completo'           =>
                    $nombreCompleto,
                    'correo'                    => $item->correo,
                    'puesto'                    => $item->puesto,
                    'departamento'              =>
                    $item->departamento,
                    'id_cliente'                =>
                    (int) $item->id_cliente,
                    'nombre_sucursal'           =>
                    $item->nombre_sucursal,
                    'status'                    => (int) $item->status,
                    'tiene_acceso'              => true,
                    'force_password_change'     =>
                    (int) (
                        $item->force_password_change ?? 0
                    ),
                    'last_login_at'             =>
                    $item->last_login_at,
                    'ultimo_envio_credenciales' =>
                    $item->password_changed_at,
                ];
            })
            ->values();

        return response()->json([
            'ok'      => true,
            'message' =>
            'Empleados con acceso obtenidos correctamente.',
            'data'    => $result,
        ]);
    }

    public function index(Request $request, int $id)
    {
        $administrator = $this->administrator($request);

        $plantilla = DB::connection('portal_main')
            ->table('checador_checada_plantillas')
            ->select([
                'id',
                'id_portal',
                'id_cliente',
            ])
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no fue encontrada.',
                'data'    => [],
            ], 404);
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $plantilla->id_cliente]
        );

        $asignaciones = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $plantilla->id_cliente
            )
            ->where(
                'id_plantilla_checada',
                (int) $plantilla->id
            )
            ->where('activa', 1)
            ->get();

        return response()->json([
            'ok'      => true,
            'message' => 'Asignaciones obtenidas correctamente.',
            'data'    => $asignaciones,
        ]);
    }

    public function store(Request $request, int $id)
    {
        $validator = Validator::make($request->all(), [
            'empleados'    => ['required', 'array'],
            'empleados.*'  => ['integer', 'min:1'],
            'horarios'     => ['required', 'array', 'min:1'],
            'horarios.*'   => ['integer', 'min:1'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin'    => [
                'nullable',
                'date_format:Y-m-d',
                'after_or_equal:fecha_inicio',
            ],
            'prioridad'    => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $administrator = $this->administrator($request);

        $plantilla = DB::connection('portal_main')
            ->table('checador_checada_plantillas')
            ->select([
                'id',
                'id_portal',
                'id_cliente',
                'nombre',
            ])
            ->where('id', $id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla no fue encontrada.',
            ], 404);
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $plantilla->id_cliente]
        );

        $empleados = collect($validated['empleados'])
            ->map(fn($idEmpleado) => (int) $idEmpleado)
            ->filter(fn($idEmpleado) => $idEmpleado > 0)
            ->unique()
            ->values();

        $horarios = collect($validated['horarios'])
            ->map(fn($idHorario) => (int) $idHorario)
            ->filter(fn($idHorario) => $idHorario > 0)
            ->unique()
            ->values();

        $empleadosValidos = $empleados->isEmpty()
            ? collect()
            : DB::connection('portal_main')
            ->table('empleados')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $plantilla->id_cliente
            )
            ->whereIn('id', $empleados->all())
            ->where('status', 1)
            ->where('eliminado', 0)
            ->pluck('id')
            ->map(fn($idEmpleado) => (int) $idEmpleado);

        if ($empleadosValidos->count() !== $empleados->count()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Uno o más empleados no pertenecen a la sucursal autorizada.',
                'errors'  => [
                    'empleados' => [
                        'La selección contiene empleados no autorizados.',
                    ],
                ],
            ], 422);
        }

        $horariosValidos = DB::connection('portal_main')
            ->table('checador_horario_plantillas')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $plantilla->id_cliente
            )
            ->whereIn('id', $horarios->all())
            ->where('activo', 1)
            ->pluck('id')
            ->map(fn($idHorario) => (int) $idHorario);

        if ($horariosValidos->count() !== $horarios->count()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Uno o más horarios no pertenecen a la sucursal autorizada.',
                'errors'  => [
                    'horarios' => [
                        'La selección contiene horarios no autorizados.',
                    ],
                ],
            ], 422);
        }

        $fechaInicio = $validated['fecha_inicio'];
        $fechaFin    = $validated['fecha_fin'] ?? null;
        $prioridad   = (int) ($validated['prioridad'] ?? 1);

        $conexion = DB::connection('portal_main');

        $asignacionesAnteriores = $conexion
            ->table('checador_asignaciones')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $plantilla->id_cliente
            )
            ->where(
                'id_plantilla_checada',
                (int) $plantilla->id
            )
            ->where('activa', 1)
            ->get([
                'id_empleado',
                'id_plantilla_horario',
                'fecha_inicio',
                'fecha_fin',
                'prioridad',
            ])
            ->map(fn($item) => [
                'id_empleado'          => (int) $item->id_empleado,
                'id_plantilla_horario' =>
                (int) $item->id_plantilla_horario,
                'fecha_inicio'         => $item->fecha_inicio,
                'fecha_fin'            => $item->fecha_fin,
                'prioridad'            => (int) $item->prioridad,
            ])
            ->values()
            ->all();

        $nuevasAsignaciones = [];

        foreach ($empleados as $idEmpleado) {
            foreach ($horarios as $idHorario) {
                $nuevasAsignaciones[] = [
                    'id_empleado'          => (int) $idEmpleado,
                    'id_plantilla_horario' => (int) $idHorario,
                    'fecha_inicio'         => $fechaInicio,
                    'fecha_fin'            => $fechaFin,
                    'prioridad'            => $prioridad,
                ];
            }
        }

        $conexion->transaction(function () use (
            $conexion,
            $administrator,
            $plantilla,
            $nuevasAsignaciones
        ) {
            $conexion
                ->table('checador_asignaciones')
                ->where(
                    'id_portal',
                    (int) $administrator->id_portal
                )
                ->where(
                    'id_cliente',
                    (int) $plantilla->id_cliente
                )
                ->where(
                    'id_plantilla_checada',
                    (int) $plantilla->id
                )
                ->where('activa', 1)
                ->update([
                    'activa'     => 0,
                    'updated_at' => now(),
                ]);

            if ($nuevasAsignaciones === []) {
                return;
            }

            $createdAt = now();

            $rows = collect($nuevasAsignaciones)
                ->map(function ($asignacion) use (
                    $administrator,
                    $plantilla,
                    $createdAt
                ) {
                    return array_merge($asignacion, [
                        'id_portal'            =>
                        (int) $administrator->id_portal,
                        'id_cliente'           =>
                        (int) $plantilla->id_cliente,
                        'id_plantilla_checada' =>
                        (int) $plantilla->id,
                        'activa'               => 1,
                        'created_at'           => $createdAt,
                        'updated_at'           => $createdAt,
                    ]);
                })
                ->all();

            $conexion
                ->table('checador_asignaciones')
                ->insert($rows);
        });

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
            'entidad_tipo'     => 'plantilla_checada_asignaciones',
            'entidad_id'       => (int) $plantilla->id,
            'accion'           => 'actualizar_asignaciones',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Asignaciones de la plantilla de checada actualizadas.',
            'datos_anteriores' => [
                'asignaciones' => $asignacionesAnteriores,
            ],
            'datos_nuevos'     => [
                'plantilla'    => $plantilla->nombre,
                'asignaciones' => $nuevasAsignaciones,
            ],
        ], $request);

        return response()->json([
            'ok'      => true,
            'message' => 'Asignaciones guardadas correctamente.',
        ]);
    }

    public function plantillaEmpleado(Request $request, int $idEmpleado)
    {
        $idPortal  = (int) $request->query('id_portal');
        $idCliente = (int) $request->query('id_cliente');

        if ($idPortal <= 0 || $idCliente <= 0 || $idEmpleado <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Portal, sucursal y empleado son obligatorios.',
                'data'    => null,
            ], 422);
        }

        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'ok'      => true,
            'message' => 'Plantilla del empleado obtenida correctamente.',
            'data'    => [
                'id_empleado'          => $idEmpleado,
                'id_plantilla_checada' => $asignacion
                    ? (int) $asignacion->id_plantilla_checada
                    : null,
                'asignacion'           => $asignacion,
            ],
        ]);
    }

    public function guardarPlantillaEmpleado(Request $request, int $idEmpleado)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'            => ['required', 'integer', 'min:1'],
            'id_cliente'           => ['required', 'integer', 'min:1'],
            'id_plantilla_checada' => ['required', 'integer', 'min:1'],
            'fecha_inicio'         => ['required', 'date'],
            'fecha_fin'            => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'prioridad'            => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $idPortal           = (int) $request->input('id_portal');
        $idCliente          = (int) $request->input('id_cliente');
        $idPlantillaChecada = (int) $request->input('id_plantilla_checada');
        $fechaInicio        = $request->input('fecha_inicio');
        $fechaFin           = $request->input('fecha_fin');
        $prioridad          = (int) $request->input('prioridad', 1);

        $horarios = DB::connection('portal_main')
            ->table('checador_checada_plantilla_horarios')
            ->where('id_plantilla', $idPlantillaChecada)
            ->where('activo', 1)
            ->pluck('id_horario_plantilla')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($horarios->isEmpty()) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla seleccionada no tiene horarios asignados.',
                'code'    => 'TEMPLATE_WITHOUT_SCHEDULES',
            ], 422);
        }

        DB::connection('portal_main')->transaction(function () use (
            $idPortal,
            $idCliente,
            $idEmpleado,
            $idPlantillaChecada,
            $fechaInicio,
            $fechaFin,
            $prioridad,
            $horarios
        ) {
            DB::connection('portal_main')
                ->table('checador_asignaciones')
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('id_empleado', $idEmpleado)
                ->where('activa', 1)
                ->update([
                    'activa'     => 0,
                    'updated_at' => now(),
                ]);

            $rows = [];

            foreach ($horarios as $idHorario) {
                $rows[] = [
                    'id_portal'            => $idPortal,
                    'id_cliente'           => $idCliente,
                    'id_empleado'          => $idEmpleado,
                    'id_plantilla_horario' => $idHorario,
                    'id_plantilla_checada' => $idPlantillaChecada,
                    'fecha_inicio'         => $fechaInicio,
                    'fecha_fin'            => $fechaFin,
                    'prioridad'            => $prioridad,
                    'activa'               => 1,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ];
            }

            DB::connection('portal_main')
                ->table('checador_asignaciones')
                ->insert($rows);
        });

        return response()->json([
            'ok'      => true,
            'message' => 'Plantilla asignada correctamente al empleado.',
        ]);
    }

    public function aprobadoresDisponibles(Request $request)
    {
        $administrator = $this->administrator($request);

        $idPortal = (int) $administrator->id_portal;

        $clientIds = $this->clientScope
            ->permittedClientIds($administrator);

        if ($clientIds === []) {
            return response()->json([
                'ok'   => true,
                'data' => [],
            ]);
        }

        $data = DB::connection('portal_main')
            ->table('empleados as e')
            ->join('cliente as c', function ($join) {
                $join->on('c.id', '=', 'e.id_cliente')
                    ->on('c.id_portal', '=', 'e.id_portal');
            })
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.puesto',
                'e.departamento',
                'c.nombre as nombre_sucursal',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id_cliente', $clientIds)
            ->where('e.status', 1)
            ->where('e.eliminado', 0)
            ->orderBy('c.nombre')
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->get()
            ->map(function ($empleado) {
                return [
                    'id'              => (int) $empleado->id,
                    'id_cliente'      => (int) $empleado->id_cliente,
                    'nombre_sucursal' => $empleado->nombre_sucursal,
                    'nombre_completo' => trim(collect([
                        $empleado->nombre,
                        $empleado->paterno,
                        $empleado->materno,
                    ])->filter()->implode(' ')),
                    'puesto'          => $empleado->puesto,
                    'departamento'    => $empleado->departamento,
                ];
            })
            ->values();

        return response()->json([
            'ok'   => true,
            'data' => $data,
        ]);
    }
    public function tiposEventoDisponibles(Request $request)
    {
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;

        $data = DB::connection('portal_main')
            ->table('eventos_option')
            ->where(function ($query) use ($idPortal) {
                $query->whereNull('id_portal')
                    ->orWhere('id_portal', $idPortal);
            })
            ->select([
                'id',
                'name',
                'color',
            ])

            ->orderByRaw('id_portal IS NULL DESC')
            ->orderBy('name')
            ->get()
            ->map(function ($evento) {
                return [
                    'id'    => (int) $evento->id,
                    'name'  => $evento->name,
                    'color' => $evento->color,
                ];
            })
            ->values();

        return response()->json($data);
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

    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
