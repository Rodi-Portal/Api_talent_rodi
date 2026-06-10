<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Services\ChecadorHorasExtraService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EmpleadoHorasExtraController extends Controller
{
    private const TIPO_HORAS_EXTRA = 9;

    public function colaboradores(Request $request)
    {
        $conn = 'portal_main';

        $aprobador = $request->user();

        if (! $aprobador) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $items = DB::connection($conn)
            ->table('checador_asignaciones as a')
            ->join('checador_checada_plantilla_aprobadores as pa', function ($join) use ($aprobador) {
                $join->on('pa.id_plantilla', '=', 'a.id_plantilla_checada')
                    ->where('pa.tipo_evento_id', self::TIPO_HORAS_EXTRA)
                    ->where('pa.id_empleado_aprobador', (int) $aprobador->id)
                    ->where('pa.activo', 1);
            })
            ->join('empleados as e', 'e.id', '=', 'a.id_empleado')
            ->where('a.activa', 1)
            ->where(function ($query) {
                $query->whereNull('a.fecha_fin')
                    ->orWhereDate('a.fecha_fin', '>=', now()->toDateString());
            })
            ->whereDate('a.fecha_inicio', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('e.eliminado')
                    ->orWhere('e.eliminado', 0);
            })
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.departamento',
                'e.puesto',

                'a.id as asignacion_id',
                'a.id_plantilla_checada',
                'pa.nivel',
            ])
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->get()
            ->unique('id')
            ->values()
            ->map(function ($item) {
                return [
                    'id'                => $item->id,
                    'id_portal'         => $item->id_portal,
                    'id_cliente'        => $item->id_cliente,
                    'clave'             => $item->id_empleado,
                    'nombre'            => trim(collect([
                        $item->nombre,
                        $item->paterno,
                        $item->materno,
                    ])->filter()->implode(' ')),
                    'correo'            => $item->correo,
                    'departamento'      => $item->departamento,
                    'puesto'            => $item->puesto,
                    'asignacion_id'     => $item->asignacion_id,
                    'plantilla_checada' => $item->id_plantilla_checada,
                    'nivel_aprobador'   => $item->nivel,
                ];
            });

        return response()->json([
            'ok'   => true,
            'data' => $items,
        ]);
    }

    public function store(Request $request)
    {
        $conn = 'portal_main';

        $aprobador = $request->user();

        if (! $aprobador) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'id_empleado'       => ['required', 'integer'],
            'fecha'             => ['required', 'date_format:Y-m-d'],
            'hora_inicio'       => ['required', 'date_format:H:i'],
            'hora_fin'          => ['required', 'date_format:H:i'],
            'minutos_aprobados' => ['required', 'integer', 'min:1'],
            'descripcion'       => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $empleado = DB::connection($conn)
            ->table('checador_asignaciones as a')
            ->join('checador_checada_plantilla_aprobadores as pa', function ($join) use ($aprobador) {
                $join->on('pa.id_plantilla', '=', 'a.id_plantilla_checada')
                    ->where('pa.tipo_evento_id', 9)
                    ->where('pa.id_empleado_aprobador', (int) $aprobador->id)
                    ->where('pa.activo', 1);
            })
            ->join('empleados as e', 'e.id', '=', 'a.id_empleado')
            ->where('a.activa', 1)
            ->where('e.id', (int) $data['id_empleado'])
            ->whereDate('a.fecha_inicio', '<=', $data['fecha'])
            ->where(function ($query) use ($data) {
                $query->whereNull('a.fecha_fin')
                    ->orWhereDate('a.fecha_fin', '>=', $data['fecha']);
            })
            ->select([
                'e.*',
            ])
            ->first();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'No puedes registrar horas extra para este colaborador.',
            ], 403);
        }

        try {
            $resultado = app(ChecadorHorasExtraService::class)
                ->registrar([
                    'id_usuario'                 => $aprobador->id_usuario,
                    'id_portal'                  => $empleado->id_portal,
                    'id_cliente'                 => $empleado->id_cliente,

                    'fecha'                      => $data['fecha'],
                    'hora_inicio'                => $data['hora_inicio'],
                    'hora_fin'                   => $data['hora_fin'],
                    'minutos_aprobados'          => (int) $data['minutos_aprobados'],

                    'descripcion'                => $data['descripcion'] ?? null,

                    'modo_aprobacion'            => 'flujo_aprobadores',

                    'impacta_prenomina'          => true,

                    'registrado_por'             => 'empleado_aprobador',
                    'registrado_desde'           => 'miportal',
                    'registrado_por_empleado_id' => (int) $aprobador->id,
                    'origen_evento'              => 'manual',

                    'auto_aprobar_solicitante'   => true,
                    'solicitante_empleado_id'    => (int) $aprobador->id,
                ], $empleado);

            return response()->json([
                'ok'                  => true,
                'message'             => 'Solicitud de horas extra creada correctamente.',
                'evento_id'           => $resultado['evento_id'],
                'estado_aprobacion'   => $resultado['estado_aprobacion'],
                'aprobadores_creados' => $resultado['aprobadores_creados'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
