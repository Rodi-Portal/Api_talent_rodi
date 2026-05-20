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
                'motivo'             => 'El empleado no tiene una asignación activa de checador.',
            ];
        }

        $horario = $this->obtenerHorarioDelDia(
            $asignacion->id_plantilla_horario,
            $now
        );

        if (! $horario || (int) $horario->labora !== 1) {
            return [
                'ok'                 => false,
                'id_asignacion'      => $asignacion->id,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'El empleado no tiene horario laboral configurado para este día.',
            ];
        }
        $validacionSecuencia = $this->validarSecuenciaChecada($data, $now);

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

        if ($this->plantillaRequiereGps($asignacion)) {
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
        return DB::table('checador_asignaciones')
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
        $diaSemana = (int) $fecha->isoWeekday();

        return DB::table('checador_horario_detalles as d')
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

    private function validarHorario($horario, Carbon $checkTime, string $tipo, array $data): array
    {
        if (! in_array($tipo, ['in', 'out'], true)) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'Tipo de checada no válido.',
            ];
        }

        if ($tipo === 'in') {
            $horaBase = $horario->hora_entrada;

            if (! $horaBase) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'motivo'             => 'No hay hora de entrada configurada.',
                ];
            }

            $esPrimeraEntrada = ! DB::table('checadas')
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
                    'motivo'             => 'Entrada intermedia registrada.',
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
                    'motivo'             => 'Entrada registrada con retardo.',
                    'minutos_diferencia' => $checkTime->diffInMinutes($horaProgramada),
                ];
            }

            return [
                'ok'                 => true,
                'estatus_validacion' => 'valida',
                'motivo'             => 'Entrada dentro del horario permitido.',
                'minutos_diferencia' => $checkTime->diffInMinutes($horaProgramada, false),
            ];
        }

        if ($tipo === 'out') {
            $horaBase = $horario->hora_salida;

            if (! $horaBase) {
                return [
                    'ok'                 => false,
                    'estatus_validacion' => 'rechazada',
                    'motivo'             => 'No hay hora de salida configurada.',
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
                    'motivo'             => 'Salida final registrada.',
                    'minutos_diferencia' => $checkTime->diffInMinutes($horaSalida, false),
                ];
            }

            return [
                'ok'                 => true,
                'estatus_validacion' => 'valida',
                'motivo'             => 'Salida intermedia registrada.',
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
                'motivo'             => 'No se recibió ubicación GPS.',
            ];
        }

        $latEmpleado = (float) $data['latitud'];
        $lngEmpleado = (float) $data['longitud'];

        $ubicaciones = DB::table('checador_checada_plantilla_ubicaciones as pu')
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
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'La plantilla no tiene ubicaciones permitidas.',
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
                    'motivo'             => 'Ubicación válida.',
                    'id_ubicacion'       => $ubicacion->id,
                    'distancia_metros'   => round($distancia, 2),
                ];
            }
        }

        return [
            'ok'                 => false,
            'estatus_validacion' => 'rechazada',
            'motivo'             => 'La ubicación está fuera del radio permitido.',
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
        $metodos = DB::table('checador_checada_plantilla_metodos as pm')
            ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
            ->where('pm.id_plantilla', $asignacion->id_plantilla_checada)
            ->where('pm.activo', 1)
            ->where('pm.obligatorio', 1)
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
                'motivo'             => 'La plantilla no tiene métodos obligatorios configurados.',
            ];
        }

        $claves = $metodos->pluck('clave')->values()->all();

        foreach ($metodos as $metodo) {
            if ((int) $metodo->requiere_gps === 1) {
                if (empty($data['latitud']) || empty($data['longitud'])) {
                    return [
                        'ok'                 => false,
                        'estatus_validacion' => 'rechazada',
                        'motivo'             => 'La plantilla requiere geolocalización.',
                        'metodos_requeridos' => $claves,
                    ];
                }
            }

            if ((int) $metodo->requiere_qr === 1) {
                if (empty($data['qr_token'])) {
                    return [
                        'ok'                 => false,
                        'estatus_validacion' => 'rechazada',
                        'motivo'             => 'La plantilla requiere código QR.',
                        'metodos_requeridos' => $claves,
                    ];
                }
            }

            if ((int) $metodo->requiere_foto === 1) {
                if (empty($data['foto_path']) && empty($data['foto_base64'])) {
                    return [
                        'ok'                 => false,
                        'estatus_validacion' => 'rechazada',
                        'motivo'             => 'La plantilla requiere foto de evidencia.',
                        'metodos_requeridos' => $claves,
                    ];
                }
            }
        }

        return [
            'ok'                 => true,
            'estatus_validacion' => 'valida',
            'motivo'             => 'Métodos requeridos completos.',
            'metodos_requeridos' => $claves,
        ];
    }
    private function plantillaRequiereGps($asignacion): bool
    {
        return DB::table('checador_checada_plantilla_metodos as pm')
            ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
            ->where('pm.id_plantilla', $asignacion->id_plantilla_checada)
            ->where('pm.activo', 1)
            ->where('pm.obligatorio', 1)
            ->where('m.activo', 1)
            ->where('m.requiere_gps', 1)
            ->exists();
    }

    private function validarSecuenciaChecada(array $data, Carbon $checkTime): array
    {
        $tipoSolicitado = $data['tipo'] ?? 'in';

        if (! in_array($tipoSolicitado, ['in', 'out'], true)) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'Tipo de checada no válido.',
            ];
        }

        $ultimaChecada = DB::table('checadas')
            ->where('id_portal', $data['id_portal'])
            ->where('id_cliente', $data['id_cliente'])
            ->where('id_empleado', $data['id_empleado'])
            ->whereDate('fecha', $checkTime->toDateString())
            ->orderByDesc('check_time')
            ->first();

        if (! $ultimaChecada && $tipoSolicitado !== 'in') {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => 'Primero debes registrar una entrada.',
            ];
        }

        if ($ultimaChecada && $ultimaChecada->tipo === $tipoSolicitado) {
            return [
                'ok'                 => false,
                'estatus_validacion' => 'rechazada',
                'motivo'             => $tipoSolicitado === 'in'
                    ? 'Ya tienes una entrada registrada. Primero debes registrar una salida.'
                    : 'Ya tienes una salida registrada. Primero debes registrar una entrada.',
            ];
        }

        return [
            'ok'                 => true,
            'estatus_validacion' => 'valida',
            'motivo'             => 'Secuencia válida.',
        ];
    }
}
