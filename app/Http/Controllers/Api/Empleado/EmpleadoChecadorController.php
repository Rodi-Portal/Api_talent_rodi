<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\Checada;
use App\Services\Checador\ChecadaRegistroService;
use App\Services\Checador\ChecadaValidationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EmpleadoChecadorController extends Controller
{
    public function contexto(Request $request)
    {
        $empleado = $request->user();

        $idEmpleado = (int) $empleado->id;
        $idPortal   = (int) $empleado->id_portal;
        $idCliente  = (int) $empleado->id_cliente;

        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        $plantilla = null;

        if ($asignacion && $asignacion->id_plantilla_checada) {
            $plantilla = DB::connection('portal_main')
                ->table('checador_checada_plantillas')
                ->where('id', (int) $asignacion->id_plantilla_checada)
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('activo', 1)
                ->first();
        }

        $metodos = [];

        if ($plantilla) {
            $metodos = DB::connection('portal_main')
                ->table('checador_checada_plantilla_metodos as pm')
                ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
                ->where('pm.id_plantilla', (int) $plantilla->id)
                ->where('pm.activo', 1)
                ->where('m.activo', 1)
                ->orderBy('pm.orden')
                ->select([
                    'm.id',
                    'm.clave',
                    'm.nombre',
                    'm.descripcion',
                    'm.requiere_gps',
                    'm.requiere_qr',
                    'm.requiere_foto',
                    'pm.obligatorio',
                    'pm.orden',
                ])
                ->get();
        }
        $ubicaciones = [];

        if ($plantilla) {
            $ubicaciones = DB::connection('portal_main')
                ->table('checador_checada_plantilla_ubicaciones as pu')
                ->join('checador_ubicaciones as u', 'u.id', '=', 'pu.id_ubicacion')
                ->where('pu.id_plantilla', (int) $plantilla->id)
                ->where('pu.activo', 1)
                ->where('u.activa', 1)
                ->select([
                    'u.id',
                    'u.nombre',
                    'u.descripcion',
                    'u.tipo_zona',
                    'u.latitud',
                    'u.longitud',
                    'u.radio_metros',
                    'u.polygon_json',
                    'u.direccion',
                    'u.referencia',
                    'pu.obligatorio',
                ])
                ->get();
        }
        $horarios = [];

        if ($plantilla) {
            $horarios = DB::connection('portal_main')
                ->table('checador_checada_plantilla_horarios as ph')
                ->join('checador_horario_plantillas as h', 'h.id', '=', 'ph.id_horario_plantilla')
                ->where('ph.id_plantilla', (int) $plantilla->id)
                ->where('ph.activo', 1)
                ->where('h.activo', 1)
                ->select([
                    'h.id',
                    'h.nombre',
                    'h.descripcion',
                    'h.timezone',
                    'h.tolerancia_entrada_min',
                    'h.tolerancia_salida_min',
                    'h.permite_descanso',
                    'h.activo',
                    'ph.obligatorio',
                ])
                ->get()
                ->map(function ($horario) {
                    $horario->detalles = DB::connection('portal_main')
                        ->table('checador_horario_detalles')
                        ->where('id_plantilla', (int) $horario->id)
                        ->orderBy('dia_semana')
                        ->orderBy('orden')
                        ->select([
                            'id',
                            'id_plantilla',
                            'dia_semana',
                            'labora',
                            'hora_entrada',
                            'hora_salida',
                            'descanso_inicio',
                            'descanso_fin',
                            'orden',
                        ])
                        ->get();

                    return $horario;
                });
        }
        $this->cerrarJornadaPendienteSiAplica(
            $idPortal,
            $idCliente,
            $idEmpleado,
            Carbon::now('America/Mexico_City')
        );

        $horarioAsignado   = null;
        $timezoneOperativo = config('app.timezone', 'UTC');

        if ($asignacion && ! empty($asignacion->id_plantilla_horario)) {
            $horarioAsignado = DB::connection('portal_main')
                ->table('checador_horario_plantillas')
                ->where('id', (int) $asignacion->id_plantilla_horario)
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('activo', 1)
                ->first();

            if ($horarioAsignado && ! empty($horarioAsignado->timezone)) {
                $timezoneOperativo = $horarioAsignado->timezone;
            }
        }

        $hoy           = Carbon::now($timezoneOperativo)->toDateString();
        $ultimaChecada = DB::connection('portal_main')
            ->table('checadas')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->whereDate('fecha', $hoy)
            ->orderByDesc('check_time')
            ->first();

        $movimientosPermitidos = $this->resolverMovimientosPermitidos($ultimaChecada, $horarios);
        return response()->json([
            'ok'      => true,
            'message' => 'Contexto de checador obtenido correctamente.',
            'data'    => [
                'empleado'               => [
                    'id'         => $idEmpleado,
                    'nombre'     => $empleado->nombre,
                    'paterno'    => $empleado->paterno,
                    'materno'    => $empleado->materno,
                    'id_portal'  => $idPortal,
                    'id_cliente' => $idCliente,
                ],
                'asignacion'             => $asignacion,
                'plantilla'              => $plantilla,
                'metodos'                => $metodos,
                'ubicaciones'            => $ubicaciones,
                'horarios'               => $horarios,
                'ultima_checada'         => $ultimaChecada,
                'movimientos_permitidos' => $movimientosPermitidos,
            ],
        ]);
    }

    public function historialHoy(Request $request)
    {
        $empleado = $request->user();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no autenticado.',
            ], 401);
        }

        $idEmpleado = (int) $empleado->id;
        $idPortal   = (int) $empleado->id_portal;
        $idCliente  = (int) $empleado->id_cliente;

        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        $timezoneOperativo = config('app.timezone', 'UTC');

        if ($asignacion && ! empty($asignacion->id_plantilla_horario)) {
            $horarioAsignado = DB::connection('portal_main')
                ->table('checador_horario_plantillas')
                ->where('id', (int) $asignacion->id_plantilla_horario)
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('activo', 1)
                ->first();

            if ($horarioAsignado && ! empty($horarioAsignado->timezone)) {
                $timezoneOperativo = $horarioAsignado->timezone;
            }
        }

        $hoy      = Carbon::now($timezoneOperativo)->toDateString();
        $checadas = Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $hoy)
            ->orderBy('check_time')
            ->get([
                'id',
                'fecha',
                'check_time',
                'tipo',
                'clase',
                'metodo_validacion',
                'estatus_validacion',
                'distancia_metros',
                'precision_metros',
                'observacion',
                'id_ubicacion',
            ]);
        $ultima       = $checadas->last();
        $estadoActual = $this->resolverEstadoActualEmpleado($ultima);
        return response()->json([
            'ok'      => true,
            'message' => 'Historial de checadas obtenido correctamente.',
            'data'    => [
                'fecha'         => $hoy,
                'checadas'      => $checadas,
                'estado_actual' => $estadoActual,
            ],
        ]);
    }
    private function resolverEstadoActualEmpleado($ultimaChecada): array
    {
        if (! $ultimaChecada) {
            return [
                'clave'   => 'not_arrived',
                'label'   => 'No ha llegado',
                'variant' => 'muted',
            ];
        }

        if ($ultimaChecada->tipo === 'in') {
            return [
                'clave'   => 'working',
                'label'   => 'Trabajando',
                'variant' => 'success',
            ];
        }

        if ($ultimaChecada->tipo === 'out') {
            if ($ultimaChecada->clase === 'meal') {
                return [
                    'clave'   => 'meal',
                    'label'   => 'En comida',
                    'variant' => 'warning',
                ];
            }

            if ($ultimaChecada->clase === 'break') {
                return [
                    'clave'   => 'break',
                    'label'   => 'En descanso',
                    'variant' => 'warning',
                ];
            }

            if ($ultimaChecada->clase === 'personal') {
                return [
                    'clave'   => 'personal',
                    'label'   => 'Salida personal',
                    'variant' => 'warning',
                ];
            }

            if ($ultimaChecada->clase === 'work') {
                return [
                    'clave'   => 'finished',
                    'label'   => 'Jornada finalizada',
                    'variant' => 'neutral',
                ];
            }
        }

        return [
            'clave'   => 'unknown',
            'label'   => 'Estado desconocido',
            'variant' => 'muted',
        ];
    }

    public function registrar(Request $request)
    {

        $empleado = $request->user();
        $ip       = $request->ip();
        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no autenticado.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'tipo'             => 'required|string|in:in,out',
            'clase'            => 'required|string|in:work,meal,break,personal,transfer,other',
            'metodo'           => 'required|string',

            'check_time'       => 'required|date',

            'latitud'          => 'nullable|numeric',
            'longitud'         => 'nullable|numeric',
            'precision_metros' => 'nullable|numeric',

            'qr_token'         => 'nullable|string',
            'foto'             => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'metadata'         => 'nullable|string',

            'timezone'         => 'nullable|string',
            'device_info'      => 'nullable|string',
        ]);
        $validationService = app(ChecadaValidationService::class);
        $registroService   = app(ChecadaRegistroService::class);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $idEmpleado = (int) $empleado->id;
        $idPortal   = (int) $empleado->id_portal;
        $idCliente  = (int) $empleado->id_cliente;

        $asignacion = DB::connection('portal_main')
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
                'ok'      => false,
                'message' => 'No tienes una plantilla de checada asignada.',
            ], 422);
        }
        $plantilla = DB::connection('portal_main')
            ->table('checador_checada_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_checada)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->first();

        if (! $plantilla) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla de checada asignada no está activa o no existe.',
            ], 422);
        }

        $horario = DB::connection('portal_main')
            ->table('checador_horario_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_horario)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->select([
                'id',
                'timezone',
                'tolerancia_entrada_min',
                'tolerancia_salida_min',
                'permite_descanso',
            ])
            ->first();

        if (! $horario || empty($horario->timezone)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla asignada no tiene zona horaria configurada.',
            ], 422);
        }

        $timezone = $horario->timezone;

        $fechaHora = Carbon::parse($request->check_time)
            ->setTimezone($timezone);

        $this->cerrarJornadaPendienteSiAplica(
            $idPortal,
            $idCliente,
            $idEmpleado,
            $fechaHora
        );
        $this->registrarFaltaSiAplica(
            $idPortal,
            $idCliente,
            $idEmpleado,
            $fechaHora->copy()->subDay()
        );

        $ultimaChecada = Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $fechaHora->toDateString())
            ->orderByDesc('check_time')
            ->first();

        if (! $ultimaChecada && $request->tipo !== 'in') {
            return response()->json([
                'ok'      => false,
                'message' => 'Primero debes registrar una entrada.',
            ], 422);
        }

        if ($ultimaChecada && $ultimaChecada->tipo === $request->tipo) {
            return response()->json([
                'ok'      => false,
                'message' => $request->tipo === 'in'
                    ? 'Ya tienes una entrada registrada. Primero debes registrar una salida.'
                    : 'Ya tienes una salida registrada. Primero debes registrar una entrada.',
            ], 422);
        }

        $detalleHorario = DB::connection('portal_main')
            ->table('checador_horario_detalles')
            ->where('id_plantilla', (int) $horario->id)
            ->where('dia_semana', (int) $fechaHora->dayOfWeek)
            ->where('labora', 1)
            ->orderBy('orden')
            ->first();

        $ubicacionesPermitidas = DB::connection('portal_main')
            ->table('checador_checada_plantilla_ubicaciones as pu')
            ->join('checador_ubicaciones as u', 'u.id', '=', 'pu.id_ubicacion')
            ->where('pu.id_plantilla', (int) $plantilla->id)
            ->where('pu.activo', 1)
            ->where('u.activa', 1)
            ->select([
                'u.id',
                'u.nombre',
                'u.tipo_zona',
                'u.latitud',
                'u.longitud',
                'u.radio_metros',
                'u.polygon_json',
                'pu.obligatorio',
            ])
            ->get();

        $ubicacionDetectada  = null;
        $distanciaDetectada  = null;
        $estaDentroGeocerca  = false;
        $ubicacionMasCercana = null;
        $distanciaMenor      = null;

        foreach ($ubicacionesPermitidas as $ubicacion) {

            if ($ubicacion->tipo_zona !== 'circle') {
                continue;
            }

            if (
                $ubicacion->latitud === null ||
                $ubicacion->longitud === null ||
                $ubicacion->radio_metros === null
            ) {
                continue;
            }
            $distancia = $this->calcularDistanciaMetros(
                (float) $request->latitud,
                (float) $request->longitud,
                (float) $ubicacion->latitud,
                (float) $ubicacion->longitud
            );

            if ($distanciaMenor === null || $distancia < $distanciaMenor) {
                $distanciaMenor      = $distancia;
                $ubicacionMasCercana = $ubicacion;
            }

            if ($distancia <= (float) $ubicacion->radio_metros) {
                $estaDentroGeocerca = true;

                $ubicacionDetectada = $ubicacion;

                $distanciaDetectada = round($distancia, 2);

                break;
            }
        }

        if (! $estaDentroGeocerca && $distanciaMenor !== null) {
            $distanciaDetectada = round($distanciaMenor, 2);
            $ubicacionDetectada = $ubicacionMasCercana;
        }

        $estatusValidacion   = 'valida';
        $observacionGeocerca = null;

        if ((int) $plantilla->requiere_ubicacion === 1 && ! $estaDentroGeocerca) {
            if ($plantilla->regla_fuera_ubicacion === 'bloquear') {
                return response()->json([
                    'ok'      => false,
                    'message' => 'No estás dentro de una ubicación permitida para registrar tu asistencia.',
                ], 422);
            }

            if ($plantilla->regla_fuera_ubicacion === 'advertir') {
                $estatusValidacion   = 'advertida';
                $observacionGeocerca = 'Checada registrada fuera de ubicación permitida.';
            }

            if ($plantilla->regla_fuera_ubicacion === 'permitir') {
                $estatusValidacion   = 'valida';
                $observacionGeocerca = 'Checada registrada fuera de ubicación permitida, permitida por regla de plantilla.';
            }
        }
        $payloadValidacion = [
            'id_portal'        => $idPortal,
            'id_cliente'       => $idCliente,
            'id_empleado'      => $idEmpleado,

            'tipo'             => $request->tipo,
            'clase'            => $request->clase,

            'check_time'       => $fechaHora->format('Y-m-d H:i:s'),

            'latitud'          => $request->latitud,
            'longitud'         => $request->longitud,
            'precision_metros' => $request->precision_metros,

            'qr_token'         => $request->qr_token,
            'foto_base64'      => $request->hasFile('foto') ? 'archivo_recibido' : null,
            'timezone'         => $timezone,

            'origen'           => 'geoloc',

            'metodo'           => $request->metodo,
        ];
        $resultadoValidacion = $validationService->validar($payloadValidacion);

        if (! $resultadoValidacion['ok']) {
            return response()->json([
                'ok'                 => false,
                'message'            => $resultadoValidacion['motivo'],
                'estatus_validacion' => $resultadoValidacion['estatus_validacion'] ?? 'rechazada',
            ], 422);
        }

        $minutosRetardo = 0;

        $esPrimeraEntradaLaboral = ! Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $fechaHora->toDateString())
            ->where('tipo', 'in')
            ->where('clase', 'work')
            ->exists();

        if (
            $detalleHorario &&
            $request->tipo === 'in' &&
            $request->clase === 'work' &&
            $esPrimeraEntradaLaboral &&
            ! empty($detalleHorario->hora_entrada)
        ) {
            $horaEntrada = Carbon::parse(
                $fechaHora->toDateString() . ' ' . $detalleHorario->hora_entrada,
                $timezone
            );

            $limiteEntrada = $horaEntrada->copy()->addMinutes(
                (int) ($horario->tolerancia_entrada_min ?? 0)
            );

            if ($fechaHora->greaterThan($limiteEntrada)) {
                $minutosRetardo = $limiteEntrada->diffInMinutes($fechaHora);
            }
        }
        if ($request->clase === 'break' && (int) $horario->permite_descanso !== 1) {
            return response()->json([
                'ok'      => false,
                'message' => 'El horario asignado no permite registrar descansos.',
            ], 422);
        }

        $requiereFoto = DB::connection('portal_main')
            ->table('checador_checada_plantilla_metodos as pm')
            ->join('checador_metodos as m', 'm.id', '=', 'pm.id_metodo')
            ->where('pm.id_plantilla', (int) $plantilla->id)
            ->where('pm.activo', 1)
            ->where('m.activo', 1)
            ->where(function ($query) {
                $query->where('m.clave', 'foto')
                    ->orWhere('m.requiere_foto', 1);
            })
            ->exists();

        $requiereFotoParaMovimiento = $requiereFoto && $request->clase === 'work';

        if ($requiereFotoParaMovimiento && ! $request->hasFile('foto')) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla asignada requiere fotografía de evidencia.',
            ], 422);
        }
        $evidenciaFoto = $this->guardarSelfieFile(
            $request,
            $idPortal,
            $idCliente,
            $idEmpleado,
            $fechaHora->toDateString()
        );

        $metadata = null;

        if (! empty($request->metadata)) {
            $metadata = json_decode($request->metadata, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($metadata)) {
                $metadata = [];
            }
        } else {
            $metadata = [];
        }

        $metadata['timezone_operativo']       = $timezone;
        $metadata['utc_offset_operativo']     = $fechaHora->format('P');
        $metadata['check_time_iso_operativo'] = $fechaHora->toIso8601String();
        $metadata['check_time_utc_recibido']  = $request->check_time;
        $metadata['timezone_dispositivo']     = $request->timezone;

        $payloadValidacion['dispositivo']       = 'web';
        $payloadValidacion['origen']            = 'geoloc';
        $payloadValidacion['metodo_validacion'] = $request->metodo;
        $payloadValidacion['foto_path']         = $evidenciaFoto;
        $payloadValidacion['qr_token']          = $request->qr_token;
        $payloadValidacion['device_info']       = $request->device_info;

        $idChecada = $registroService->insertar(
            $payloadValidacion,
            $resultadoValidacion,
            $metadata
        );

        $checada         = Checada::find($idChecada);
        $eventoRetardoId = null;

        if ($minutosRetardo > 0) {
            $eventoRetardoId = DB::connection('portal_main')
                ->table('calendario_eventos')
                ->insertGetId([
                    'id_usuario'           => null,
                    'id_empleado'          => $idEmpleado,
                    'id_portal'            => $idPortal,
                    'id_cliente'           => $idCliente,
                    'inicio'               => $fechaHora->toDateString(),
                    'fin'                  => $fechaHora->toDateString(),
                    'dias_evento'          => 1,
                    'descripcion'          => 'Retardo de ' . $minutosRetardo . ' minutos.',
                    'archivo'              => null,
                    'id_tipo'              => 5,
                    'tipo_incapacidad_sat' => null,
                    'eliminado'            => 0,
                    'estado'               => 2,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);

            $observaciones = [];

            if (! empty($checada->observacion)) {
                $observaciones[] = $checada->observacion;
            }

            $observaciones[] = 'Retardo de ' . $minutosRetardo . ' minutos. Evento calendario #' . $eventoRetardoId;

            $checada->observacion        = implode(' | ', $observaciones);
            $checada->estatus_validacion = 'advertida';
            $checada->save();
        }

        $warnings = [];

        if (! empty($observacionGeocerca)) {
            $warnings[] = $observacionGeocerca;
        }

        if ($minutosRetardo > 0) {
            $warnings[] = 'Retardo de ' . $minutosRetardo . ' minutos.';
        }
        return response()->json([
            'ok'       => true,
            'message'  => count($warnings) > 0
                ? 'Asistencia registrada con observaciones.'
                : 'Asistencia registrada correctamente.',
            'warnings' => $warnings,
            'data'     => [
                'id'                => $checada->id,
                'tipo'              => $checada->tipo,
                'clase'             => $checada->clase,
                'metodo'            => $checada->metodo_validacion,
                'fecha'             => $checada->fecha,
                'check_time'        => $checada->check_time,
                'timezone'          => $checada->timezone,
                'evidencia_foto'    => $checada->evidencia_foto,
                'minutos_retardo'   => $minutosRetardo,
                'evento_retardo_id' => $eventoRetardoId,
            ],
        ]);
    }
    private function guardarSelfieBase64(
        ?string $selfie,
        ?string $mime,
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): ?string {

        \Log::info('SELFIE DEBUG INICIO', [
            'has_selfie'    => ! empty($selfie),
            'selfie_length' => ! empty($selfie) ? strlen($selfie) : 0,
            'mime'          => $mime,
            'id_portal'     => $idPortal,
            'id_cliente'    => $idCliente,
            'id_empleado'   => $idEmpleado,
            'fecha'         => $fecha,
            'env'           => app()->environment(),
        ]);

        if (empty($selfie)) {
            \Log::warning('SELFIE DEBUG VACIA');
            return null;
        }

        $mime = strtolower((string) $mime);

        $extension = match ($mime) {
            'image/jpeg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            default     => null,
        };

        if (! $extension) {
            \Log::warning('SELFIE DEBUG MIME NO SOPORTADO', [
                'mime' => $mime,
            ]);
            return null;
        }

        $imageData = base64_decode($selfie, true);

        if ($imageData === false) {
            \Log::warning('SELFIE DEBUG BASE64 INVALIDO');
            return null;
        }

        \Log::info('SELFIE DEBUG BASE64 OK', [
            'bytes'     => strlen($imageData),
            'extension' => $extension,
        ]);

        $mes = Carbon::parse($fecha)->format('Y-m');

        $relativeDir = "_checadasEvidencia/{$idPortal}/{$idCliente}/{$idEmpleado}/{$mes}";

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        \Log::info('SELFIE DEBUG PATHS', [
            'base_path'    => $basePath,
            'relative_dir' => $relativeDir,
        ]);

        if (empty($basePath)) {
            \Log::error('SELFIE DEBUG BASE PATH VACIO');
            return null;
        }

        $fullDir = rtrim($basePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        \Log::info('SELFIE DEBUG FULL DIR', [
            'full_dir'         => $fullDir,
            'exists'           => File::exists($fullDir),
            'is_writable_base' => is_writable($basePath),
        ]);

        if (! File::exists($fullDir)) {
            File::makeDirectory($fullDir, 0755, true);

            \Log::info('SELFIE DEBUG DIRECTORIO CREADO', [
                'full_dir'        => $fullDir,
                'exists_after'    => File::exists($fullDir),
                'is_writable_dir' => is_writable($fullDir),
            ]);
        }

        $filename = 'checada_' . now()->format('Ymd_His')
        . '_' . Str::random(10)
            . '.' . $extension;

        $fullPath = $fullDir . DIRECTORY_SEPARATOR . $filename;

        $bytesWritten = File::put($fullPath, $imageData);

        \Log::info('SELFIE DEBUG FILE PUT', [
            'full_path'     => $fullPath,
            'bytes_written' => $bytesWritten,
            'file_exists'   => File::exists($fullPath),
            'file_size'     => File::exists($fullPath) ? File::size($fullPath) : null,
        ]);

        if ($bytesWritten === false) {
            \Log::error('SELFIE DEBUG ERROR ESCRIBIENDO ARCHIVO', [
                'full_path' => $fullPath,
            ]);
            return null;
        }

        return $relativeDir . '/' . $filename;
    }

    private function calcularDistanciaMetros(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadius = 6371000;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a =
        sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) *
        cos(deg2rad($lat2)) *
        sin($dLng / 2) *
        sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function resolverMovimientosPermitidos($ultimaChecada, $horarios): array
    {
        $permiteDescanso = true;

        if (! $ultimaChecada) {
            return [
                [
                    'tipo'   => 'in',
                    'clase'  => 'work',
                    'label'  => 'Entrada',
                    'accion' => 'entrada',
                ],
            ];
        }

        if ($ultimaChecada->tipo === 'in') {
            return [
                [
                    'tipo'   => 'out',
                    'clase'  => 'work',
                    'label'  => 'Salida',
                    'accion' => 'salida',
                ],
                [
                    'tipo'   => 'out',
                    'clase'  => 'meal',
                    'label'  => 'Salida a comida',
                    'accion' => 'meal',
                ],
                [
                    'tipo'   => 'out',
                    'clase'  => 'break',
                    'label'  => 'Salida a descanso',
                    'accion' => 'break',
                ],
                [
                    'tipo'   => 'out',
                    'clase'  => 'personal',
                    'label'  => 'Salida personal',
                    'accion' => 'personal',
                ],
                [
                    'tipo'   => 'out',
                    'clase'  => 'transfer',
                    'label'  => 'Salida a traslado',
                    'accion' => 'transfer',
                ],
            ];
        }

        if ($ultimaChecada->tipo === 'out') {
            return [
                [
                    'tipo'   => 'in',
                    'clase'  => $ultimaChecada->clase,
                    'label'  => match ($ultimaChecada->clase) {
                        'meal'     => 'Regreso de comida',
                        'break'    => 'Regreso de descanso',
                        'personal' => 'Regreso de salida personal',
                        'transfer' => 'Regreso de traslado',
                        default    => 'Entrada',
                    },
                    'accion' => $ultimaChecada->clase,
                ],
            ];
        }

        return [];
    }
    private function guardarSelfieFile(
        Request $request,
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        string $fecha
    ): ?string {
        if (! $request->hasFile('foto')) {
            return null;
        }

        $file = $request->file('foto');

        if (! $file || ! $file->isValid()) {
            return null;
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');

        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $mes = Carbon::parse($fecha)->format('Y-m');

        $relativeDir = "_checadasEvidencia/{$idPortal}/{$idCliente}/{$idEmpleado}/{$mes}";

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        if (empty($basePath)) {
            \Log::error('SELFIE FILE ERROR BASE PATH VACIO', [
                'env' => app()->environment(),
            ]);

            return null;
        }

        $fullDir = rtrim($basePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (! File::exists($fullDir)) {
            File::makeDirectory($fullDir, 0755, true);
        }

        $filename = 'checada_' . now()->format('Ymd_His')
        . '_' . Str::random(10)
            . '.' . $extension;

        $file->move($fullDir, $filename);

        return $relativeDir . '/' . $filename;
    }

    private function cerrarJornadaPendienteSiAplica(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $referencia
    ): void {
        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $referencia->toDateString())
            ->where(function ($query) use ($referencia) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $referencia->toDateString());
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion) {
            return;
        }

        $horario = DB::connection('portal_main')
            ->table('checador_horario_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_horario)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->first();

        if (! $horario || empty($horario->timezone)) {
            return;
        }

        $timezone = $horario->timezone;
        $fechaRef = $referencia->copy()->setTimezone($timezone);

        $detalle = DB::connection('portal_main')
            ->table('checador_horario_detalles')
            ->where('id_plantilla', (int) $horario->id)
            ->where('dia_semana', (int) $fechaRef->dayOfWeek)
            ->where('labora', 1)
            ->orderBy('orden')
            ->first();

        if (! $detalle || empty($detalle->hora_salida)) {
            return;
        }

        $fecha = $fechaRef->toDateString();

        $salidaProgramada = Carbon::parse(
            $fecha . ' ' . $detalle->hora_salida,
            $timezone
        );

        $limiteCierre = $salidaProgramada->copy()->addMinutes(
            (int) ($horario->tolerancia_salida_min ?? 0)
        );

        if ($fechaRef->lessThanOrEqualTo($limiteCierre)) {
            return;
        }

        $ultimaChecada = Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $fecha)
            ->orderByDesc('check_time')
            ->first();

        if (! $ultimaChecada) {
            return;
        }

        if ($ultimaChecada->tipo !== 'in' || $ultimaChecada->clase !== 'work') {
            return;
        }

        $yaExisteSalidaWork = Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $fecha)
            ->where('tipo', 'out')
            ->where('clase', 'work')
            ->exists();

        if ($yaExisteSalidaWork) {
            return;
        }

        Checada::create([
            'id_portal'          => $idPortal,
            'id_cliente'         => $idCliente,
            'id_empleado'        => $idEmpleado,
            'id_asignacion'      => (int) $asignacion->id,
            'id_ubicacion'       => null,

            'fecha'              => $fecha,
            'check_time'         => $salidaProgramada->format('Y-m-d H:i:s'),

            'tipo'               => 'out',
            'clase'              => 'work',

            'dispositivo'        => 'sistema',
            'origen'             => 'api',
            'metodo_validacion'  => 'auto_cierre',
            'estatus_validacion' => 'valida',

            'distancia_metros'   => null,
            'precision_metros'   => null,
            'latitud'            => null,
            'longitud'           => null,

            'observacion'        => 'Salida generada automáticamente por cierre de jornada.',
            'hash'               => sha1($idPortal . '|' . $idEmpleado . '|' . $salidaProgramada->format('Y-m-d H:i:s') . '|auto_cierre'),

            'evidencia_foto'     => null,
            'qr_token'           => null,
            'ip_address'         => null,
            'timezone'           => $timezone,
            'device_info'        => 'Sistema',
            'metadata'           => [
                'tipo'        => 'auto_cierre_jornada',
                'generado_en' => now()->toDateTimeString(),
            ],
        ]);
    }
    private function registrarFaltaSiAplica(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $fechaRef
    ): void {
        $fecha = $fechaRef->toDateString();

        $asignacion = DB::connection('portal_main')
            ->table('checador_asignaciones')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('activa', 1)
            ->whereDate('fecha_inicio', '<=', $fecha)
            ->where(function ($query) use ($fecha) {
                $query->whereNull('fecha_fin')
                    ->orWhereDate('fecha_fin', '>=', $fecha);
            })
            ->orderByDesc('prioridad')
            ->orderByDesc('id')
            ->first();

        if (! $asignacion) {
            return;
        }

        $horario = DB::connection('portal_main')
            ->table('checador_horario_plantillas')
            ->where('id', (int) $asignacion->id_plantilla_horario)
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('activo', 1)
            ->first();

        if (! $horario || empty($horario->timezone)) {
            return;
        }

        $fechaHorario = $fechaRef->copy()->setTimezone($horario->timezone);

        $detalle = DB::connection('portal_main')
            ->table('checador_horario_detalles')
            ->where('id_plantilla', (int) $horario->id)
            ->where('dia_semana', (int) $fechaHorario->dayOfWeek)
            ->where('labora', 1)
            ->first();

        if (! $detalle) {
            return;
        }

        $salidaProgramada = Carbon::parse(
            $fechaHorario->toDateString() . ' ' . $detalle->hora_salida,
            $horario->timezone
        );

        $limiteRevision = $salidaProgramada->copy()->addMinutes(
            (int) ($horario->tolerancia_salida_min ?? 0)
        );

        if ($fechaHorario->lessThanOrEqualTo($limiteRevision)) {
            return;
        }

        $hayChecadas = Checada::where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('fecha', $fechaHorario->toDateString())
            ->exists();

        if ($hayChecadas) {
            return;
        }

        $hayJustificacion = DB::connection('portal_main')
            ->table('calendario_eventos')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('eliminado', 0)
            ->whereIn('id_tipo', [1, 2, 3, 10])
            ->whereDate('inicio', '<=', $fechaHorario->toDateString())
            ->whereDate('fin', '>=', $fechaHorario->toDateString())
            ->exists();

        if ($hayJustificacion) {
            return;
        }

        $yaExisteFalta = DB::connection('portal_main')
            ->table('calendario_eventos')
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('eliminado', 0)
            ->where('id_tipo', 4)
            ->whereDate('inicio', '<=', $fechaHorario->toDateString())
            ->whereDate('fin', '>=', $fechaHorario->toDateString())
            ->exists();

        if ($yaExisteFalta) {
            return;
        }

        DB::connection('portal_main')
            ->table('calendario_eventos')
            ->insert([
                'id_usuario'           => null,
                'id_empleado'          => $idEmpleado,
                'id_portal'            => $idPortal,
                'id_cliente'           => $idCliente,
                'inicio'               => $fechaHorario->toDateString(),
                'fin'                  => $fechaHorario->toDateString(),
                'dias_evento'          => 1,
                'descripcion'          => 'Falta por ausencia de checadas en día laborable.',
                'archivo'              => null,
                'id_tipo'              => 4,
                'tipo_incapacidad_sat' => null,
                'eliminado'            => 0,
                'estado'               => 2,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
    }
}
