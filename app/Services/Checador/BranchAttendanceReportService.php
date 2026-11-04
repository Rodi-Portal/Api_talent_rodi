<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\ChecadorAsignacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BranchAttendanceReportService
{
    public function __construct(
        private AttendanceReportService $attendanceReportService
    ) {
    }

    public function generarVistaPrevia(
        int $idPortal,
        int $idCliente,
        string $fechaInicio,
        string $fechaFin,
        ?array $empleadoIds = null
    ): array {
        $ids = null;

        $query = DB::connection('portal_main')
            ->table('empleados')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('status', 1)
            ->where('eliminado', 0);

        if ($empleadoIds !== null) {
            $ids = collect($empleadoIds)
                ->map(fn($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                throw ValidationException::withMessages([
                    'empleados' => 'Selecciona al menos un colaborador.',
                ]);
            }

            $query->whereIn('id', $ids);
        }

        $empleados = $query
            ->select([
                'id',
                'id_empleado',
                'nombre',
                'paterno',
                'materno',
                'departamento',
                'puesto',
                'id_cliente',
            ])
            ->orderBy('nombre')
            ->orderBy('paterno')
            ->orderBy('materno')
            ->get();

        if ($ids !== null && $empleados->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'empleados' => 'Uno o mas colaboradores no pertenecen a la sucursal o no estan activos.',
            ]);
        }

        $totales = [
            'dias'        => [
                'laborables'     => 0,
                'con_asistencia' => 0,
                'sin_asistencia' => 0,
            ],
            'horas'       => [
                'normales'        => 0,
                'descanso'        => 0,
                'extra'           => 0,
                'contabilizables' => 0,
            ],
            'incidencias' => [
                'retardos'            => 0,
                'salidas_anticipadas' => 0,
                'sin_salida'          => 0,
            ],
        ];

        $resultados = [];

        // Procesar colaboradores en lotes para evitar
        // cargar simultaneamente todas las asignaciones.
        foreach ($empleados->chunk(100) as $lote) {

            // Una consulta de asignaciones por lote,
            // con carga anticipada de plantillas y detalles.
            $asignacionesPorEmpleado = ChecadorAsignacion::query()
                ->with(['horarioPlantilla.detalles'])
                ->where('id_portal', $idPortal)
                ->whereIn(
                    'id_empleado',
                    $lote->pluck('id')->all()
                )
                ->where('activa', 1)
                ->whereDate('fecha_inicio', '<=', $fechaFin)
                ->where(function ($query) use ($fechaInicio) {
                    $query->whereNull('fecha_fin')
                        ->orWhereDate('fecha_fin', '>=', $fechaInicio);
                })
                ->get()
                ->groupBy('id_empleado');

            foreach ($lote as $empleado) {

                $asignaciones = $asignacionesPorEmpleado
                    ->get((int) $empleado->id, collect());

                $reporte = $this->attendanceReportService
                    ->generarVistaPrevia(
                        $idPortal,
                        (int) $empleado->id,
                        $fechaInicio,
                        $fechaFin,
                        $asignaciones
                    );

                foreach ($totales as $grupo => $campos) {
                    foreach ($campos as $campo => $valor) {
                        $totales[$grupo][$campo] += (int) (
                            $reporte['resumen'][$grupo][$campo] ?? 0
                        );
                    }
                }

                $nombre  = trim(implode(' ', array_filter([
                    $empleado->nombre,
                    $empleado->paterno,
                    $empleado->materno,
                ])));

                $resultados[] = [
                    'id'              => (int) $empleado->id,
                    'id_empleado'     => $empleado->id_empleado,
                    'nombre_completo' => $nombre,
                    'departamento'    => $empleado->departamento,
                    'puesto'          => $empleado->puesto,
                    'id_cliente'      => (int) $empleado->id_cliente,
                    'resumen'         => $reporte['resumen'],
                    'dias'            => $reporte['dias'],
                ];
            } // Fin del empleado

        } // Fin del lote

        return [
            'sucursal'            => [
                'id_cliente' => $idCliente,
            ],
            'periodo'             => [
                'fecha_inicio' => $fechaInicio,
                'fecha_fin'    => $fechaFin,
            ],
            'total_colaboradores' => count($resultados),
            'totales'             => $totales,
            'colaboradores'       => $resultados,
        ];
    }
}
