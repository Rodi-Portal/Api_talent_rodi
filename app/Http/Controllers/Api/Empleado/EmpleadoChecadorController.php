<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\Checada;
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
        return response()->json([
            'ok'      => true,
            'message' => 'Contexto de checador obtenido correctamente.',
            'data'    => [
                'empleado'    => [
                    'id'         => $idEmpleado,
                    'nombre'     => $empleado->nombre,
                    'paterno'    => $empleado->paterno,
                    'materno'    => $empleado->materno,
                    'id_portal'  => $idPortal,
                    'id_cliente' => $idCliente,
                ],
                'asignacion'  => $asignacion,
                'plantilla'   => $plantilla,
                'metodos'     => $metodos,
                'ubicaciones' => $ubicaciones,
                'horarios'    => $horarios,
            ],
        ]);
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
            'tipo'      => 'required|string|in:in,out',
            'clase'     => 'required|string|in:work,break',
            'metodo'    => 'required|string',
            'lat'       => 'nullable|numeric',
            'lng'       => 'nullable|numeric',
            'accuracy'  => 'nullable|numeric',
            'qr_token'  => 'nullable|string',
            'selfie'    => 'nullable|string',
            'timestamp' => 'required',
        ]);

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
            ->table('checador_checada_plantilla_horarios as ph')
            ->join('checador_horario_plantillas as h', 'h.id', '=', 'ph.id_horario_plantilla')
            ->where('ph.id_plantilla', (int) $plantilla->id)
            ->where('ph.activo', 1)
            ->where('h.activo', 1)
            ->select([
                'h.id',
                'h.timezone',
                'h.tolerancia_entrada_min',
                'h.tolerancia_salida_min',
                'h.permite_descanso',
            ])
            ->first();

        if (! $horario || empty($horario->timezone)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla asignada no tiene zona horaria configurada.',
            ], 422);
        }

        $timezone = $horario->timezone;

        $fechaHora = Carbon::createFromTimestampMs((int) $request->timestamp)
            ->setTimezone($timezone);
        $detalleHorario = DB::connection('portal_main')
            ->table('checador_horario_detalles')
            ->where('id_plantilla', (int) $horario->id)
            ->where('dia_semana', (int) $fechaHora->dayOfWeekIso)
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

        $ubicacionDetectada = null;
        $distanciaDetectada = null;
        $estaDentroGeocerca = false;

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
                (float) $request->lat,
                (float) $request->lng,
                (float) $ubicacion->latitud,
                (float) $ubicacion->longitud
            );

            if ($distancia <= (float) $ubicacion->radio_metros) {
                $estaDentroGeocerca = true;

                $ubicacionDetectada = $ubicacion;

                $distanciaDetectada = round($distancia, 2);

                break;
            }
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

        $minutosRetardo = 0;

        if (
            $detalleHorario &&
            $request->tipo === 'in' &&
            $request->clase === 'work' &&
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
        if ($request->clase === 'work' && in_array($request->tipo, ['in', 'out'], true)) {
            $yaExisteChecadaTrabajo = Checada::where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->where('id_empleado', $idEmpleado)
                ->where('fecha', $fechaHora->toDateString())
                ->where('clase', 'work')
                ->where('tipo', $request->tipo)
                ->exists();

            if ($yaExisteChecadaTrabajo) {
                $mensajeTipo = $request->tipo === 'in'
                    ? 'entrada'
                    : 'salida';

                return response()->json([
                    'ok'      => false,
                    'message' => 'Ya existe una checada de ' . $mensajeTipo . ' laboral para el día de hoy.',
                ], 422);
            }
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

        if ($requiereFoto && empty($request->selfie)) {
            return response()->json([
                'ok'      => false,
                'message' => 'La plantilla asignada requiere fotografía de evidencia.',
            ], 422);
        }
        $evidenciaFoto = $this->guardarSelfieBase64(
            $request->selfie,
            $idPortal,
            $idCliente,
            $idEmpleado,
            $fechaHora->toDateString()
        );
        $checada = Checada::create([
            'id_portal'          => $idPortal,
            'id_cliente'         => $idCliente,
            'id_empleado'        => $idEmpleado,
            'id_asignacion'      => (int) $asignacion->id,
            'id_ubicacion'       => $ubicacionDetectada ? (int) $ubicacionDetectada->id : null,

            'fecha'              => $fechaHora->toDateString(),
            'check_time'         => $fechaHora->format('Y-m-d H:i:s'),

            'tipo'               => $request->tipo,
            'clase'              => $request->clase,

            'dispositivo'        => 'web',
            'origen'             => 'geoloc',
            'metodo_validacion'  => $request->metodo,
            'estatus_validacion' => $estatusValidacion,
            'distancia_metros'   => $distanciaDetectada,
            'precision_metros'   => $request->accuracy,
            'latitud'            => $request->lat,
            'longitud'           => $request->lng,

            'observacion'        => $observacionGeocerca,
            'hash'               => sha1($idPortal . '|' . $idEmpleado . '|' . $fechaHora->format('Y-m-d H:i:s') . '|' . uniqid('', true)),

            'evidencia_foto'     => $evidenciaFoto,
            'qr_token'           => $request->qr_token,
            'ip_address'         => $ip,
            'timezone'           => $timezone,
            'device_info'        => $request->device,
            'metadata'           => [
                'screen'            => $request->screen,
                'timestamp_cliente' => $request->timestamp,
            ],
        ]);
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
    private function guardarSelfieBase64(?string $selfie, int $idPortal, int $idCliente, int $idEmpleado, string $fecha): ?string
    {
        if (empty($selfie)) {
            return null;
        }

        if (! preg_match('/^data:image\/(\w+);base64,/', $selfie, $matches)) {
            return null;
        }

        $extension = strtolower($matches[1]);

        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $base64    = substr($selfie, strpos($selfie, ',') + 1);
        $imageData = base64_decode($base64);

        if ($imageData === false) {
            return null;
        }

        $mes = Carbon::parse($fecha)->format('Y-m');

        $relativeDir = "_checadasEvidencia/{$idPortal}/{$idCliente}/{$idEmpleado}/{$mes}";

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        $fullDir = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);

        if (! File::exists($fullDir)) {
            File::makeDirectory($fullDir, 0755, true);
        }

        $filename = 'checada_' . now()->format('Ymd_His') . '_' . Str::random(10) . '.' . $extension;

        $fullPath = $fullDir . DIRECTORY_SEPARATOR . $filename;

        File::put($fullPath, $imageData);

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
}
