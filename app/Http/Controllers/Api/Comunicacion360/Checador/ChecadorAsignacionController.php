<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ChecadorAsignacionController extends Controller
{
    public function empleadosConAcceso(Request $request)
    {
        $idPortal   = (int) $request->query('id_portal');
        $sucursales = $request->query('sucursales', []);

        if (! is_array($sucursales)) {
            $sucursales = [$sucursales];
        }

        $sucursales = collect($sucursales)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        if ($idPortal <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'El portal es obligatorio.',
                'data'    => [],
            ], 422);
        }

        $query = DB::connection('portal_main')
            ->table('empleados as e')
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
                'e.password',
                'e.force_password_change',
                'e.last_login_at',
                'e.password_changed_at',
            ])
            ->where('e.id_portal', $idPortal)
            ->where('e.status', 1)
            ->where('e.eliminado', 0)
            ->whereNotNull('e.password')
            ->where('e.password', '<>', '');

        if (! empty($sucursales)) {
            $query->whereIn('e.id_cliente', $sucursales);
        }

        $empleados = $query
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->orderBy('e.materno')
            ->get();

        $data = $empleados->map(function ($item) {
            $nombreCompleto = trim(collect([
                $item->nombre,
                $item->paterno,
                $item->materno,
            ])->filter()->implode(' '));

            return [
                'id'                        => (int) $item->id,
                'id_empleado'               => $item->id_empleado,
                'nombre'                    => $item->nombre,
                'paterno'                   => $item->paterno,
                'materno'                   => $item->materno,
                'nombre_completo'           => $nombreCompleto,
                'correo'                    => $item->correo,
                'puesto'                    => $item->puesto,
                'departamento'              => $item->departamento,
                'id_cliente'                => (int) $item->id_cliente,
                'nombre_sucursal'           => 'Sucursal ' . (int) $item->id_cliente,
                'status'                    => (int) $item->status,
                'tiene_acceso'              => true,
                'force_password_change'     => (int) ($item->force_password_change ?? 0),
                'last_login_at'             => $item->last_login_at,
                'ultimo_envio_credenciales' => $item->password_changed_at,
            ];
        })->values();

        return response()->json([
            'ok'      => true,
            'message' => 'Empleados con acceso obtenidos correctamente.',
            'data'    => $data,
        ]);
    }

    public function index(Request $request, int $id)
    {
        $idPortal  = (int) $request->query('id_portal');
        $idCliente = (int) $request->query('id_cliente');

        if ($idPortal <= 0 || $idCliente <= 0) {
            return response()->json([
                'ok'      => false,
                'message' => 'Portal y sucursal son obligatorios.',
                'data'    => [],
            ], 422);
        }

        $asignaciones = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_plantilla_checada', $id)
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
            'id_portal'    => ['required', 'integer', 'min:1'],
            'id_cliente'   => ['required', 'integer', 'min:1'],
            'empleados'    => ['array'],
            'empleados.*'  => ['integer', 'min:1'],
            'horarios'     => ['required', 'array', 'min:1'],
            'horarios.*'   => ['integer', 'min:1'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin'    => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
            'prioridad'    => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $idPortal  = (int) $request->input('id_portal');
        $idCliente = (int) $request->input('id_cliente');
        $empleados = collect($request->input('empleados', []))
            ->map(fn($idEmpleado) => (int) $idEmpleado)
            ->filter(fn($idEmpleado) => $idEmpleado > 0)
            ->unique()
            ->values();

        $horarios = collect($request->input('horarios', []))
            ->map(fn($idHorario) => (int) $idHorario)
            ->filter(fn($idHorario) => $idHorario > 0)
            ->unique()
            ->values();

        $fechaInicio = $request->input('fecha_inicio');
        $fechaFin    = $request->input('fecha_fin');
        $prioridad   = (int) $request->input('prioridad', 1);

        DB::connection('portal_main')->transaction(function () use (
            $idPortal,
            $idCliente,
            $id,
            $empleados,
            $horarios,
            $fechaInicio,
            $fechaFin,
            $prioridad
        ) {
            DB::connection('portal_main')
                ->table('checador_asignaciones')
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('id_plantilla_checada', $id)
                ->update([
                    'activa'     => 0,
                    'updated_at' => now(),
                ]);

            $rows = [];

            foreach ($empleados as $idEmpleado) {
                foreach ($horarios as $idHorario) {
                    $rows[] = [
                        'id_portal'            => $idPortal,
                        'id_cliente'           => $idCliente,
                        'id_empleado'          => $idEmpleado,
                        'id_plantilla_horario' => $idHorario,
                        'id_plantilla_checada' => $id,
                        'fecha_inicio'         => $fechaInicio,
                        'fecha_fin'            => $fechaFin,
                        'prioridad'            => $prioridad,
                        'activa'               => 1,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }
            }

            if (! empty($rows)) {
                DB::connection('portal_main')
                    ->table('checador_asignaciones')
                    ->insert($rows);
            }
        });

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
}
