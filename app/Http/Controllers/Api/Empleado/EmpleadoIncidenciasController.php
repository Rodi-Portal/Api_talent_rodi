<?php
namespace App\Http\Controllers\Api\Empleado;
use App\Http\Controllers\Controller;
use App\Models\CalendarioEvento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

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

        $employee->load('laborales');

        /* =========================
           VALIDACIÓN
        ========================= */

        $data = $request->validate([
            'tipo'          => 'required|string',
            'fechaInicio'   => 'required|date',
            'fechaFin'      => 'required|date|after_or_equal:fechaInicio',
            'comentario'    => 'nullable|string',
            'aprobadores'   => 'required|array',
            'aprobadores.*' => 'integer|exists:portal_main.empleados,id',
            'evidencia'     => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        DB::beginTransaction();

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

                $basePath = $this->getBasePath();

                $relativePath = "portals/$portalId/clientes/$clienteId/empleados/$empleadoId/incidencias/";

                $fullPath = $basePath . '/' . $relativePath;

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

            $diasDescanso = $this->getDiasDescansoEmpleado($employee);

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

                    'id_empleado' => $employee->id,
                    'id_usuario'  => $employee->id,
                    'inicio'      => $bloque['inicio'],
                    'fin'         => $bloque['fin'],
                    'dias_evento' => $dias,
                    'descripcion' => $data['comentario'],
                    'archivo'     => $archivo,
                    'id_tipo'     => 1,
                    'estado'      => 1,
                    'eliminado'   => 0,
                    'created_at'  => now(),
                    'updated_at'  => now(),

                ]);

                $eventos[] = $eventoId;
            }

            /* =========================
               REGISTRAR APROBADORES
            ========================= */

            $nivel       = 1;
            $aprobadores = array_unique($data['aprobadores']);
            foreach ($eventos as $eventoId) {

                $nivel = 1;

                foreach ($aprobadores as $aprobadorId) {

                    DB::connection($conn)->table('evento_aprobaciones')->insert([

                        'evento_id'    => $eventoId,
                        'id_aprobador' => $aprobadorId,
                        'nivel'        => $nivel,
                        'estado'       => 1,
                        'created_at'   => now(),

                    ]);

                    $nivel++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'eventos' => $eventos,
                'archivo' => $archivo,
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

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
        $employee->load('laborales');
        $eventos = CalendarioEvento::with('tipo:id,name')
            ->where('id_empleado', $employee->id)
            ->where('eliminado', 0)
            ->orderBy('inicio', 'desc')
            ->get();

        $data = $eventos->map(function ($evento) {

            return [
                'id'         => $evento->id,
                'tipo'       => $evento->tipo?->name,
                'fecha'      => $evento->inicio,
                'fechaFin'   => $evento->fin,
                'estado'     => $this->mapEstado($evento->estado),
                'comentario' => $evento->descripcion,
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
}
