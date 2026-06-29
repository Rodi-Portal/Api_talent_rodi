<?php
namespace App\Services\Checador;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ChecadaValidationService
{
    public function validar(array $data): array
    {
        $now = Carbon::parse($data['check_time'] ?? now());

        $asignacion = $this->obtenerAsignacionActiva(
            $data['id_portal'],
            $data['id_cliente'],
            $data['id_empleado'],
            $now
        );

        if (! $asignacion) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'checker_assignment_not_found',
                'motivo'             => 'checker_assignment_not_found',
            ];
        }

        $horarioAplicable = $this->resolverHorarioAplicable(
            $asignacion->id_plantilla_horario,
            $now
        );

        $horario      = $horarioAplicable['horario'];
        $fechaJornada = $horarioAplicable['fecha_jornada'];
        if (! $horario || (int) $horario->labora !== 1) {
            return [
                'ok'                 => false,
                'id_asignacion'      => $asignacion->id,
                'estatus_validacion' => 'rechazada',
                'code'               => 'non_working_day',
                'motivo'             => 'non_working_day',
            ];
        }
        $validacionSecuencia = $this->validarSecuenciaChecada(
            $data,
            $now,
            $fechaJornada
        );
        if (! $validacionSecuencia['ok']) {
            return array_merge($validacionSecuencia, [
                'id_asignacion' => $asignacion->id,
            ]);
        }
        $validacionHorario = $this->validarHorario(
            $horario,
            $now,
            $data['tipo'] ?? 'in',
            $data
        );
        if (! $validacionHorario['ok']) {
            return array_merge($validacionHorario, [
                'id_asignacion' => $asignacion->id,
            ]);
        }

        $validacionMetodos = $this->validarMetodosRequeridos($asignacion, $data);

        if (! $validacionMetodos['ok']) {
            return array_merge($validacionMetodos, [
                'id_asignacion' => $asignacion->id,
            ]);
        }
        $validacionUbicacion = [
            'ok'               => true,
            'id_ubicacion'     => null,
            'distancia_metros' => null,
        ];

        if (
            ($data['origen'] ?? 'geoloc') === 'geoloc'
            && $this->plantillaRequiereGps($asignacion)
            && ! empty($data['latitud'])
            && ! empty($data['longitud'])
        ) {
            $validacionUbicacion = $this->validarUbicacion($asignacion, $data);

            if (! $validacionUbicacion['ok']) {
                return array_merge($validacionUbicacion, [
                    'id_asignacion' => $asignacion->id,
                ]);
            }
        }
        return [
            'ok'                 => true,
            'id_asignacion'      => $asignacion->id,
            'estatus_validacion' => $validacionHorario['estatus_validacion'],
            'motivo'             => $validacionHorario['motivo'],
            'minutos_diferencia' => $validacionHorario['minutos_diferencia'] ?? null,
            'horario'            => $horario,
            'asignacion'         => $asignacion,
            'metodos_requeridos' => $validacionMetodos['metodos_requeridos'] ?? [],
            'id_ubicacion'       => $validacionUbicacion['id_ubicacion'] ?? null,
            'distancia_metros'   => $validacionUbicacion['distancia_metros'] ?? null,
            'latitud'            => $data['latitud'] ?? null,
            'longitud'           => $data['longitud'] ?? null,
            'precision_metros'   => $data['precision_metros'] ?? null,
        ];
    }

    private function obtenerAsignacionActiva(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $fecha
    ) {
        return DB::connection('portal_main')->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fecha->toDateString())
            ->where(function ($q) use ($fecha) {
                $q->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha->toDateString());
            })
            ->orderByDesc('prioridad')
            ->first();
    }

    private function obtenerHorarioDelDia(int $idPlantillaHorario, Carbon $fecha)
    {
        $diaSemana = (int) $fecha->dayOfWeek;

        return DB::connection('portal_main')->table('checador_horario_detalles as d')
            ->join('checador_horario_plantillas as p', 'p.id', '=', 'd.id_plantilla')
            ->where('d.id_plantilla', $idPlantillaHorario)
            ->where('d.dia_semana', $diaSemana)
            ->select(
                'd.*',
                'p.tolerancia_entrada_min',
                'p.tolerancia_salida_min',
                'p.permite_descanso'
            )
            ->first();
    }
    private function resolverFechaJornada(
        int $idPlantillaHorario,
        Carbon $checkTime
    ): Carbon {
        $fechaActual   = $checkTime->copy();
        $fechaAnterior = $checkTime->copy()->subDay();

        $horarioAnterior = $this->obtenerHorarioDelDia(
            $idPlantillaHorario,
            $fechaAnterior
        );

        if (
            $horarioAnterior
            && (int) $horarioAnterior->labora === 1
            && $horarioAnterior->hora_entrada
            && $horarioAnterior->hora_salida
        ) {
            $entradaAnterior = Carbon::parse(
                $fechaAnterior->toDateString() . ' ' . $horarioAnterior->hora_entrada
            );

            $salidaAnterior = Carbon::parse(
                $fechaAnterior->toDateString() . ' ' . $horarioAnterior->hora_salida
            );

            if ($salidaAnterior->lessThanOrEqualTo($entradaAnterior)) {
                $salidaAnterior->addDay();

                if (
                    $checkTime->greaterThanOrEqualTo($entradaAnterior)
                    && $checkTime->lessThanOrEqualTo($salidaAnterior->copy()->addHours(4))
                ) {
                    return $fechaAnterior;
                }
            }
        }

        return $fechaActual;
    }
    private function validarHorario($horario, Carbon $checkTime, string $tipo, array $data): array
    {
        if (! in_array($tipo, ['in', 'out'], true)) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'invalid_check_type',
                'motivo'             => 'invalid_check_type',
            ];
        }

        if ($tipo === 'in') {
            $horaBase = $horario->hora_entrada;

            if (! $horaBase) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'checkin_time_not_configured',
                    'motivo'             => 'checkin_time_not_configured',
                ];
            }

            $esPrimeraEntrada = ! DB::connection('portal_main')->table('checadas')
                ->where('id_portal', $data['id_portal'])
                ->where('id_cliente', $data['id_cliente'])
                ->where('id_empleado', $data['id_empleado'])
                ->whereDate('fecha', $checkTime->toDateString())
                ->where('tipo', 'in')
                ->exists();

            // Si NO es la primera entrada, se considera movimiento intermedio.
            if (! $esPrimeraEntrada) {
                return [
                    'ok'                 => true,
                    'estatus_validacion' => 'valida',
                    'code'               => 'intermediate_checkin_registered',
                    'motivo'             => 'intermediate_checkin_registered',
                    'minutos_diferencia' => null,
                ];
            }

            $tolerancia          = (int) ($horario->tolerancia_entrada_min ?? 0);
            $horaProgramada      = Carbon::parse($checkTime->toDateString() . ' ' . $horaBase);
            $limiteConTolerancia = $horaProgramada->copy()->addMinutes($tolerancia);

            if ($checkTime->gt($limiteConTolerancia)) {
                return [
                    'ok'                 => true,
                    'estatus_validacion' => 'advertida',
                    'code'               => 'late_checkin_registered',
                    'motivo'             => 'late_checkin_registered',
                    'minutos_diferencia' => $checkTime->diffInMinutes($horaProgramada),
                ];
            }

            return [
                'ok'                 => true,
                'estatus_validacion' => 'valida',
                'code'               => 'checkin_within_allowed_time',
                'motivo'             => 'checkin_within_allowed_time',
                'minutos_diferencia' => $checkTime->diffInMinutes($horaProgramada, false),
            ];
        }

        if ($tipo === 'out') {
            $horaBase = $horario->hora_salida;

            if (! $horaBase) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'checkout_time_not_configured',
                    'motivo'             => 'checkout_time_not_configured',
                ];
            }

            $horaSalida = Carbon::parse($checkTime->toDateString() . ' ' . $horaBase);

            // Margen para considerar que ya es salida final.
            $margenSalidaFinalMin     = 60;
            $inicioVentanaSalidaFinal = $horaSalida->copy()->subMinutes($margenSalidaFinalMin);

            if ($checkTime->gte($inicioVentanaSalidaFinal)) {
                return [
                    'ok'                 => true,
                    'estatus_validacion' => 'valida',
                    'code'               => 'final_checkout_registered',
                    'motivo'             => 'final_checkout_registered',
                    'minutos_diferencia' => $checkTime->diffInMinutes($horaSalida, false),
                ];
            }

            return [
                'ok'                 => true,
                'estatus_validacion' => 'valida',
                'code'               => 'intermediate_checkout_registered',
                'motivo'             => 'intermediate_checkout_registered',
                'minutos_diferencia' => null,
            ];
        }
    }

    private function validarUbicacion($asignacion, array $data): array
    {
        if (empty($data['latitud']) || empty($data['longitud'])) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'gps_location_missing',
                'motivo'             => 'gps_location_missing',
            ];
        }

        $latEmpleado = (float) $data['latitud'];
        $lngEmpleado = (float) $data['longitud'];

        $ubicaciones = DB::connection('portal_main')->table('checador_checada_plantilla_ubicaciones as pu')
            ->join('checador_ubicaciones as u', 'u.id', '=', 'pu.id_ubicacion')
            ->where('pu.id_plantilla', $asignacion->id_plantilla_checada)
            ->where('pu.activo', 1)
            ->where('u.activa', 1)
            ->select(
                'u.id',
                'u.nombre',
                'u.tipo_zona',
                'u.latitud',
                'u.longitud',
                'u.radio_metros'
            )
            ->get();

        if ($ubicaciones->isEmpty()) {
            return [
                'ok'                 => true,
                'estatus_validacion' => 'advertida',
                'code'               => 'no_allowed_locations_but_free_allowed',
                'message'            => 'no_allowed_locations_but_free_allowed',
                'motivo'             => 'no_allowed_locations_but_free_allowed',
                'extra'              => [
                    'warnings' => [
                        'no_allowed_locations_but_free_allowed',
                    ],
                ],
            ];
        }

        $ubicacionMasCercana = null;
        $distanciaMenor      = null;

        foreach ($ubicaciones as $ubicacion) {
            if ($ubicacion->tipo_zona !== 'circle') {
                continue;
            }

            $distancia = $this->calcularDistanciaMetros(
                $latEmpleado,
                $lngEmpleado,
                (float) $ubicacion->latitud,
                (float) $ubicacion->longitud
            );

            if ($distanciaMenor === null || $distancia < $distanciaMenor) {
                $distanciaMenor      = $distancia;
                $ubicacionMasCercana = $ubicacion;
            }

            if ($distancia <= (float) $ubicacion->radio_metros) {
                return [
                    'ok'                 => true,
                    'estatus_validacion' => 'valida',
                    'code'               => 'valid_location',
                    'motivo'             => 'valid_location',
                    'id_ubicacion'       => $ubicacion->id,
                    'distancia_metros'   => round($distancia, 2),
                ];
            }
        }

        return [
            'ok'                 => false,
            'estatus_validacion' => 'rechazada',
            'code'               => 'outside_allowed_location',
            'motivo'             => 'outside_allowed_location',
            'id_ubicacion'       => $ubicacionMasCercana->id ?? null,
            'distancia_metros'   => $distanciaMenor !== null ? round($distanciaMenor, 2) : null,
        ];
    }
    private function calcularDistanciaMetros(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $radioTierra = 6371000;

        $lat1Rad  = deg2rad($lat1);
        $lat2Rad  = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) * sin($deltaLat / 2)
         + cos($lat1Rad) * cos($lat2Rad)
         * sin($deltaLng / 2) * sin($deltaLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $radioTierra * $c;
    }

    private function validarMetodosRequeridos($asignacion, array $data): array
    {
        $metodos = DB::connection('portal_main')->table('checador_checada_plantilla_metodos as pm')
            ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
            ->where('pm.id_plantilla', $asignacion->id_plantilla_checada)
            ->where('pm.activo', 1)
            ->where('m.activo', 1)
            ->select(
                'm.id',
                'm.clave',
                'm.nombre',
                'm.requiere_gps',
                'm.requiere_qr',
                'm.requiere_foto'
            )
            ->get();

        if ($metodos->isEmpty()) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'attendance_methods_not_configured',
                'motivo'             => 'attendance_methods_not_configured',
            ];
        }

        $metodosPermitidos = $metodos->pluck('clave')->values()->all();

        $origen = $data['origen'] ?? 'geoloc';

        $metodoClave = match ($origen) {
            'geoloc'     => 'gps',
            'biometrico' => 'biometrico',
            'reloj'      => 'biometrico',
            'manual'     => 'manual',
            'api'        => 'biometrico',
            'libre'      => 'libre',
            default      => $origen,
        };

        $metodo = $metodos->firstWhere('clave', $metodoClave);

        if ($metodoClave === 'libre') {
            if (! $metodo) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'free_check_not_allowed',
                    'motivo'             => 'free_check_not_allowed',
                    'metodos_permitidos' => $metodosPermitidos,
                    'metodo_solicitado'  => 'libre',
                ];
            }

            return [
                'ok'                 => true,
                'estatus_validacion' => 'advertida',
                'code'               => 'free_check_registered',
                'motivo'             => 'free_check_registered',
                'metodos_requeridos' => ['libre'],
            ];
        }

        if (! $metodo) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'attendance_method_not_allowed',
                'motivo'             => 'attendance_method_not_allowed',
                'metodos_permitidos' => $metodosPermitidos,
                'metodo_solicitado'  => $metodoClave,
            ];
        }

        if ((int) $metodo->requiere_gps === 1) {
            $permiteLibre = in_array('libre', $metodosPermitidos, true);

            if ((empty($data['latitud']) || empty($data['longitud'])) && ! $permiteLibre) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'gps_required',
                    'motivo'             => 'gps_required',
                    'metodos_permitidos' => $metodosPermitidos,
                    'metodo_solicitado'  => $metodoClave,
                ];
            }

            if ((empty($data['latitud']) || empty($data['longitud'])) && $permiteLibre) {
                return [
                    'ok'                 => true,
                    'estatus_validacion' => 'advertida',
                    'code'               => 'gps_missing_but_free_allowed',
                    'motivo'             => 'gps_missing_but_free_allowed',
                    'metodos_requeridos' => ['gps', 'libre'],
                ];
            }
        }

        if ((int) $metodo->requiere_qr === 1) {
            if (empty($data['qr_token'])) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'qr_required',
                    'motivo'             => 'qr_required',
                    'metodos_permitidos' => $metodosPermitidos,
                    'metodo_solicitado'  => $metodoClave,
                ];
            }
        }

        $metodosValidados = [$metodoClave];

        // Foto de evidencia: solo aplica como complemento del GPS / MiPortal.
        $fotoActiva = $metodos->firstWhere('clave', 'foto');
        if (
            $metodoClave === 'gps'
            && $fotoActiva
            && ($data['clase'] ?? 'work') === 'work'
        ) {
            if (empty($data['foto_path']) && empty($data['foto_base64'])) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'code'               => 'photo_required_for_gps',
                    'motivo'             => 'photo_required_for_gps',
                    'metodos_permitidos' => $metodosPermitidos,
                    'metodo_solicitado'  => $metodoClave,
                ];
            }

            $metodosValidados[] = 'foto';
        }

        return [
            'ok'                 => true,
            'estatus_validacion' => 'valida',
            'code'               => 'attendance_method_allowed',
            'motivo'             => 'attendance_method_allowed',
            'metodos_requeridos' => $metodosValidados,
        ];
    }
    private function plantillaRequiereGps($asignacion): bool
    {
        return DB::connection('portal_main')->table('checador_checada_plantilla_metodos as pm')
            ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
            ->where('pm.id_plantilla', $asignacion->id_plantilla_checada)
            ->where('pm.activo', 1)
            ->where('pm.obligatorio', 1)
            ->where('m.activo', 1)
            ->where('m.requiere_gps', 1)
            ->exists();
    }

    private function validarSecuenciaChecada(
        array $data,
        Carbon $checkTime,
        Carbon $fechaJornada
    ): array {
        $tipoSolicitado = $data['tipo'] ?? 'in';

        if (! in_array($tipoSolicitado, ['in', 'out'], true)) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'Tipo de checada no válido.',
            ];
        }

        $inicioBusqueda = $checkTime->copy()->subHours(16);
        $finBusqueda    = $checkTime->copy()->addHours(4);

        $ultimaChecada = DB::connection('portal_main')->table('checadas')
            ->where('id_portal', $data['id_portal'])
            ->where('id_cliente', $data['id_cliente'])
            ->where('id_empleado', $data['id_empleado'])
            ->whereIn('clase', ['work', 'meal', 'break', 'personal', 'transfer'])
            ->whereBetween('check_time', [
                $inicioBusqueda->format('Y-m-d H:i:s'),
                $finBusqueda->format('Y-m-d H:i:s'),
            ])
            ->where('check_time', '<=', $checkTime->format('Y-m-d H:i:s'))
            ->orderByDesc('check_time')
            ->orderByDesc('id')
            ->first();
        $claseSolicitada = $data['clase'] ?? 'work';
        if ($tipoSolicitado === 'in') {
            $entradaWorkAbiertaAnterior = DB::connection('portal_main')->table('checadas as entrada')
                ->where('entrada.id_portal', $data['id_portal'])
                ->where('entrada.id_cliente', $data['id_cliente'])
                ->where('entrada.id_empleado', $data['id_empleado'])
                ->where('entrada.tipo', 'in')
                ->where('entrada.clase', 'work')
                ->whereDate('entrada.fecha', '<', $checkTime->toDateString())
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('checadas as salida')
                        ->whereColumn('salida.id_portal', 'entrada.id_portal')
                        ->whereColumn('salida.id_cliente', 'entrada.id_cliente')
                        ->whereColumn('salida.id_empleado', 'entrada.id_empleado')
                        ->whereColumn('salida.fecha', 'entrada.fecha')
                        ->where('salida.tipo', 'out')
                        ->where('salida.clase', 'work')
                        ->whereColumn('salida.check_time', '>', 'entrada.check_time');
                })
                ->orderByDesc('entrada.check_time')
                ->first();

            if ($entradaWorkAbiertaAnterior) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'pendiente',
                    'code'               => 'previous_movement_open',
                    'motivo'             => 'previous_movement_open',
                    'extra'              => [
                        'requires_previous_checkout' => true,
                        'pending_movement'           => [
                            'id'         => $entradaWorkAbiertaAnterior->id,
                            'fecha'      => $entradaWorkAbiertaAnterior->fecha,
                            'check_time' => $entradaWorkAbiertaAnterior->check_time,
                            'tipo'       => $entradaWorkAbiertaAnterior->tipo,
                            'clase'      => $entradaWorkAbiertaAnterior->clase,
                        ],
                    ],
                ];
            }
        }
        if ($ultimaChecada) {
            $fechaUltimaChecada = Carbon::parse($ultimaChecada->check_time)->toDateString();
            $fechaActual        = $checkTime->toDateString();

            $movimientoAnteriorAbierto =
                ($ultimaChecada->clase === 'work' && $ultimaChecada->tipo === 'in')
                || ($ultimaChecada->clase !== 'work' && $ultimaChecada->tipo === 'out');

            if (
                $movimientoAnteriorAbierto
                && $fechaUltimaChecada !== $fechaActual
            ) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'pendiente',
                    'code'               => 'previous_movement_open',
                    'motivo'             => 'previous_movement_open',
                    'extra'              => [
                        'requires_previous_checkout' => true,
                        'pending_movement'           => [
                            'id'         => $ultimaChecada->id,
                            'fecha'      => $fechaUltimaChecada,
                            'check_time' => $ultimaChecada->check_time,
                            'tipo'       => $ultimaChecada->tipo,
                            'clase'      => $ultimaChecada->clase,
                        ],
                    ],
                ];
            }
        }
        if (! $ultimaChecada && $tipoSolicitado !== 'in') {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => 'checkin_required_first',
                'motivo'             => 'checkin_required_first',
            ];
        }

        if ($ultimaChecada && $ultimaChecada->tipo === $tipoSolicitado) {
            $fechaUltimaChecada = Carbon::parse($ultimaChecada->check_time)->toDateString();
            $fechaActual        = $checkTime->toDateString();

            if ($tipoSolicitado === 'in' && $fechaUltimaChecada !== $fechaActual) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'pendiente',
                    'code'               => 'previous_checkin_open',
                    'motivo'             => 'previous_checkin_open',
                    'extra'              => [
                        'requires_previous_checkout' => true,
                        'pending_checkin'            => [
                            'id'         => $ultimaChecada->id,
                            'fecha'      => $fechaUltimaChecada,
                            'check_time' => $ultimaChecada->check_time,
                            'tipo'       => $ultimaChecada->tipo,
                            'clase'      => $ultimaChecada->clase,
                        ],
                    ],
                ];
            }

            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'code'               => $tipoSolicitado === 'in'
                    ? 'checkin_already_registered'
                    : 'checkout_already_registered',
                'motivo'             => $tipoSolicitado === 'in'
                    ? 'checkin_already_registered'
                    : 'checkout_already_registered',
            ];
        }

        return [
            'ok'                 => true,
            'estatus_validacion' => 'valida',
            'code'               => 'valid_sequence',
            'motivo'             => 'valid_sequence',
        ];
    }
    private function resolverHorarioAplicable(
        int $idPlantillaHorario,
        Carbon $checkTime
    ): array {
        $candidatos = [
            $checkTime->copy(),
            $checkTime->copy()->subDay(),
        ];

        foreach ($candidatos as $fechaCandidata) {
            $horario = $this->obtenerHorarioDelDia(
                $idPlantillaHorario,
                $fechaCandidata
            );

            if (
                ! $horario ||
                (int) $horario->labora !== 1 ||
                ! $horario->hora_entrada ||
                ! $horario->hora_salida
            ) {
                continue;
            }

            $inicio = Carbon::parse(
                $fechaCandidata->toDateString() . ' ' . $horario->hora_entrada
            );

            $fin = Carbon::parse(
                $fechaCandidata->toDateString() . ' ' . $horario->hora_salida
            );

            if ($fin->lessThanOrEqualTo($inicio)) {
                $fin->addDay();
            }

            $inicioVentana = $inicio->copy()->subHours(4);
            $finVentana    = $fin->copy()->addHours(4);

            if (
                $checkTime->greaterThanOrEqualTo($inicioVentana)
                && $checkTime->lessThanOrEqualTo($finVentana)
            ) {
                return [
                    'fecha_jornada' => $fechaCandidata,
                    'horario'       => $horario,
                ];
            }
        }

        return [
            'fecha_jornada' => $checkTime->copy(),
            'horario'       => null,
        ];
    }
}
