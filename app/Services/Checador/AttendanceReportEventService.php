<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class AttendanceReportEventService
{
    private const EVENTOS = [
        'Vacaciones'       => [
            'codigo'  => 'vacaciones',
            'pagable' => true,
        ],
        'Incapacidad'      => [
            'codigo'  => 'incapacidad',
            'pagable' => true,
        ],
        'Permiso con goce' => [
            'codigo'  => 'permiso_con_goce',
            'pagable' => true,
        ],
        'Permiso sin goce' => [
            'codigo'  => 'permiso_sin_goce',
            'pagable' => false,
        ],
        'Día Festivo'      => [
            'codigo'  => 'dia_festivo',
            'pagable' => true,
        ],
    ];

    public function resolverPeriodo(
        int $idPortal,
        int $idEmpleado,
        string $fechaInicio,
        string $fechaFin
    ): array {
        $empleado = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $idEmpleado)
            ->where('id_portal', $idPortal)
            ->first([
                'id',
                'id_cliente',
            ]);

        if (! $empleado) {
            return [];
        }

        $idCliente = $empleado->id_cliente !== null
            ? (int) $empleado->id_cliente
            : null;

        $eventos = DB::connection('portal_main')
            ->table('calendario_eventos as ce')
            ->join('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->where('ce.id_empleado', $idEmpleado)
            ->where('ce.eliminado', 0)
            ->where('ce.estado', 2)
            ->whereIn('eo.name', array_keys(self::EVENTOS))
            ->whereDate('ce.inicio', '<=', $fechaFin)
            ->whereDate('ce.fin', '>=', $fechaInicio)
            ->where(function ($query) use ($idPortal, $idCliente) {
                $query
                    ->where(function ($actual) use ($idPortal, $idCliente) {
                        $actual->where('ce.id_portal', $idPortal);

                        if ($idCliente !== null) {
                            $actual->where('ce.id_cliente', $idCliente);
                        }
                    })
                    ->orWhere(function ($legacy) {
                        $legacy
                            ->whereNull('ce.id_portal')
                            ->whereNull('ce.id_cliente');
                    });
            })
            ->whereIn('ce.estado_aprobacion', [
                'no_requiere',
                'aprobado',
            ])
            ->orderBy('ce.inicio')
            ->orderBy('ce.id')
            ->get([
                'ce.id',
                'ce.inicio',
                'ce.fin',
                'ce.id_tipo',
                'ce.requiere_aprobacion',
                'ce.estado_aprobacion',
                'eo.name as tipo_nombre',
            ]);

        $resultado = [];

        foreach ($eventos as $evento) {
            $configuracion = self::EVENTOS[$evento->tipo_nombre] ?? null;

            if (! $configuracion) {
                continue;
            }

            $inicio = Carbon::parse($evento->inicio);

            if ($inicio->lt(Carbon::parse($fechaInicio))) {
                $inicio = Carbon::parse($fechaInicio);
            }

            $fin = Carbon::parse($evento->fin);

            if ($fin->gt(Carbon::parse($fechaFin))) {
                $fin = Carbon::parse($fechaFin);
            }

            foreach (CarbonPeriod::create($inicio, $fin) as $fecha) {
                $fechaString = $fecha->toDateString();

                if (isset($resultado[$fechaString])) {
                    continue;
                }

                $resultado[$fechaString] = [
                    'evento_id'         => (int) $evento->id,
                    'codigo'            => $configuracion['codigo'],
                    'nombre'            => $evento->tipo_nombre,
                    'pagable'           => (bool) $configuracion['pagable'],
                    'requiere_checadas' => false,
                ];
            }
        }

        ksort($resultado);

        return $resultado;
    }
}
