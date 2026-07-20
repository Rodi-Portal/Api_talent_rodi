<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\CalendarioEvento;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmpleadoIncidenciasController extends Controller
{

    private function getBasePath()
    {
        if (app()->environment('local')) {
            return config('paths.local_images');
        }

        return config('paths.prod_images');
    }

    public function store(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        /* =========================
           VALIDACIÓN
        ========================= */

        $data = $request->validate([
            'tipo_evento_id' => 'required|integer|exists:portal_main.eventos_option,id',
            'fechaInicio'    => 'required|date',
            'fechaFin'       => 'required|date|after_or_equal:fechaInicio',
            'comentario'     => 'nullable|string',

            'evidencia'      => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        DB::connection($conn)->beginTransaction();
        try {

            /* =========================
               SUBIR EVIDENCIA
            ========================= */

            $archivo = null;

            if ($request->hasFile('evidencia')) {

                $file = $request->file('evidencia');

                $portalId   = $employee->id_portal ?? 0;
                $clienteId  = $employee->id_cliente ?? 0;
                $empleadoId = $employee->id;
                $basePath   = rtrim(
                    str_replace('\\', '/', $this->getBasePath()),
                    '/'
                );

                $calendarBasePath = $basePath . '/_archivo_calendario';

                $relativePath =
                    "portals/{$portalId}/clientes/{$clienteId}/" .
                    "empleados/{$empleadoId}/incidencias/";

                $fullPath = $calendarBasePath . '/' . $relativePath;

                if (! file_exists($fullPath)) {
                    if (! is_dir($fullPath)) {
                        mkdir($fullPath, 0775, true);
                    }
                }

                $fileName = Str::uuid() . '.' . $file->getClientOriginalExtension();

                $file->move($fullPath, $fileName);

                $archivo = $relativePath . $fileName;

            }

            /* =========================
        DIAS DE DESCANSO EMPLEADO
        ========================= */

            $diasDescanso = [];
            /* =========================
            FESTIVOS
            ========================= */

            $festivos = []; // de momento vacío hasta definir política de festivos

            /* =========================
            GENERAR BLOQUES LABORALES
            ========================= */

            $bloques = $this->generarBloquesLaborales(
                $data['fechaInicio'],
                $data['fechaFin'],
                $diasDescanso,
                $festivos
            );

            if (empty($bloques)) {
                throw new \Exception('Las fechas solicitadas caen completamente en días de descanso.');
            }
            /* =========================
               CREAR EVENTO
            ========================= */
            $eventos = [];

            foreach ($bloques as $bloque) {

                $dias = Carbon::parse($bloque['inicio'])
                    ->diffInDays(Carbon::parse($bloque['fin'])) + 1;

                $eventoId = DB::connection($conn)->table('calendario_eventos')->insertGetId([

                    'id_empleado'         => $employee->id,
                    'id_usuario'          => $employee->id,
                    'id_portal'           => (int) $employee->id_portal,
                    'id_cliente'          => (int) $employee->id_cliente,
                    'inicio'              => $bloque['inicio'],
                    'fin'                 => $bloque['fin'],
                    'dias_evento'         => $dias,
                    'descripcion'         => $data['comentario'],
                    'archivo'             => $archivo,
                    'id_tipo'             => (int) $data['tipo_evento_id'],
                    'estado_aprobacion'   => 'pendiente',
                    'requiere_aprobacion' => 1,
                    'origen_evento'       => 'checador',
                    'estado'              => 1,
                    'eliminado'           => 0,
                    'created_at'          => now(),
                    'updated_at'          => now(),

                ]);

                $eventos[] = $eventoId;
            }

            /* =========================
               REGISTRAR APROBADORES
            ========================= */

            $nivel      = 1;
            $asignacion = DB::connection($conn)
                ->table('checador_asignaciones')
                ->where('id_portal', (int) $employee->id_portal)
                ->where('id_cliente', (int) $employee->id_cliente)
                ->where('id_empleado', (int) $employee->id)
                ->where('activa', 1)
                ->orderByDesc('prioridad')
                ->orderByDesc('id')
                ->first();

            $aprobadoresPlantilla = DB::connection($conn)
                ->table('checador_checada_plantilla_aprobadores')
                ->where('id_plantilla', (int) $asignacion->id_plantilla_checada)
                ->where('tipo_evento_id', (int) $data['tipo_evento_id'])
                ->where('activo', 1)
                ->orderBy('nivel')
                ->get();

            if ($aprobadoresPlantilla->isEmpty()) {
                throw new \Exception('No hay aprobadores configurados para este tipo de evento.');
            }foreach ($eventos as $eventoId) {

                $nivel = 1;

                foreach ($aprobadoresPlantilla as $aprobador) {
                    DB::connection($conn)->table('checador_evento_aprobaciones')->insert([
                        'id_portal'               => (int) $employee->id_portal,
                        'id_cliente'              => (int) $employee->id_cliente,
                        'id_evento'               => $eventoId,
                        'tipo_evento_id'          => (int) $data['tipo_evento_id'],
                        'id_empleado_solicitante' => (int) $employee->id,
                        'id_empleado_aprobador'   => (int) $aprobador->id_empleado_aprobador,
                        'nivel'                   => (int) $aprobador->nivel,
                        'estatus'                 => 'pendiente',
                        'created_at'              => now(),
                        'updated_at'              => now(),
                    ]);
                }
            }

            DB::connection($conn)->commit();

            return response()->json([
                'success' => true,
                'eventos' => $eventos,
                'archivo' => $archivo,
            ]);

        } catch (\Exception $e) {

            DB::connection($conn)->rollBack();
            return response()->json([
                'error'   => true,
                'message' => $e->getMessage(),
            ], 500);

        }

    }
    public function index(Request $request)
    {
        $employee = $request->user();
        if (! $employee) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }
        $eventos = CalendarioEvento::with('tipo:id,name,color')
            ->where('id_empleado', $employee->id)
            ->where('eliminado', 0)
            ->orderBy('inicio', 'desc')
            ->get();

        $data = $eventos->map(function ($evento) {

            return [
                'id'                  => $evento->id,
                'tipo_evento_id'      => $evento->id_tipo,
                'tipo'                => $evento->tipo?->name,
                'color'               => $evento->tipo?->color,
                'fecha'               => $evento->inicio,
                'fechaFin'            => $evento->fin,
                'estado'              => $evento->estado_aprobacion ?: $this->mapEstado($evento->estado),
                'estado_operativo'    => $this->mapEstado($evento->estado),
                'estado_aprobacion'   => $evento->estado_aprobacion,
                'requiere_aprobacion' => (bool) $evento->requiere_aprobacion,
                'origen_evento'       => $evento->origen_evento,
                'comentario'          => $evento->descripcion,
                'archivo'             => $evento->archivo,
                'created_at'          => $evento->created_at,
            ];

        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
    private function mapEstado($estado)
    {
        return match ($estado) {
            1       => 'pendiente',
            2       => 'aprobado',
            3       => 'rechazado',
            default => 'pendiente',
        };
    }
    private function generarBloquesLaborales($inicio, $fin, $diasDescanso, $festivos)
    {
        $bloques = [];

        $fecha = Carbon::parse($inicio);
        $fin   = Carbon::parse($fin);

        $bloqueInicio = null;

        while ($fecha <= $fin) {

            $dow  = $fecha->dayOfWeek;
            $date = $fecha->toDateString();

            $esDescanso = in_array($dow, $diasDescanso, true);
            $esFestivo  = in_array($date, $festivos, true);

            if (! $esDescanso && ! $esFestivo) {

                if (! $bloqueInicio) {
                    $bloqueInicio = $date;
                }

            } else {

                if ($bloqueInicio) {

                    $bloques[] = [
                        'inicio' => $bloqueInicio,
                        'fin'    => $fecha->copy()->subDay()->toDateString(),
                    ];

                    $bloqueInicio = null;
                }

            }

            $fecha->addDay();
        }

        if ($bloqueInicio) {

            $bloques[] = [
                'inicio' => $bloqueInicio,
                'fin'    => $fin->toDateString(),
            ];
        }

        return $bloques;
    }
    private function getDiasDescansoEmpleado($employee)
    {
        $laborales = $employee->laborales;

        if (! $laborales) {
            return [];
        }

        $dias = $laborales->dias_descanso ?? [];

        $map = [
            'Domingo'   => 0,
            'Lunes'     => 1,
            'Martes'    => 2,
            'Miércoles' => 3,
            'Jueves'    => 4,
            'Viernes'   => 5,
            'Sábado'    => 6,
        ];

        $numeros = [];

        foreach ($dias as $d) {
            if (isset($map[$d])) {
                $numeros[] = $map[$d];
            }
        }

        return array_values(array_unique($numeros));
    }

    public function contexto(Request $request)
    {
        $conn = 'portal_main';

        $employee = $request->user();

        if (! $employee) {
            return response()->json([
                'ok'      => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $idEmpleado = (int) $employee->id;
        $idPortal   = (int) $employee->id_portal;
        $idCliente  = (int) $employee->id_cliente;

        $asignacion = DB::connection($conn)
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion) {
            return response()->json([
                'ok'   => true,
                'data' => [
                    'eventos'     => [],
                    'aprobadores' => [],
                ],
            ]);
        }

        $tipoEventoIds = DB::connection($conn)
            ->table('checador_checada_plantilla_aprobadores')
            ->where('id_plantilla', (int) $asignacion->id_plantilla_checada)
            ->where('activo', 1)
            ->pluck('tipo_evento_id')
            ->unique()
            ->values();

        $eventos = DB::connection($conn)
            ->table('eventos_option')
            ->whereIn('id', $tipoEventoIds)
            ->where(function ($query) use ($idPortal) {
                $query->whereNull('id_portal')
                    ->orWhere('id_portal', $idPortal);
            })
            ->select([
                'id',
                'name',
                'color',
                'id_crol',
            ])
            ->orderBy('name')
            ->get();

        $aprobadores = DB::connection($conn)
            ->table('checador_checada_plantilla_aprobadores as pa')
            ->join('empleados as e', 'e.id', '=', 'pa.id_empleado_aprobador')
            ->where('pa.id_plantilla', (int) $asignacion->id_plantilla_checada)
            ->where('pa.activo', 1)
            ->select([
                'pa.id',
                'pa.tipo_evento_id',
                'pa.id_empleado_aprobador',
                'pa.nivel',
                'pa.obligatorio',
                'e.nombre',
                'e.paterno',
                'e.materno',
            ])
            ->orderBy('pa.tipo_evento_id')
            ->orderBy('pa.nivel')
            ->get();

        return response()->json([
            'ok'   => true,
            'data' => [
                'eventos'     => $eventos,
                'aprobadores' => $aprobadores,
            ],
        ]);
    }
}
