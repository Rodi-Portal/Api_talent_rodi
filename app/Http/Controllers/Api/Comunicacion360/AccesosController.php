<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\EmployeePhotoPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AccesosController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria,
        private EmployeePhotoPathService $photoPaths
    ) {}

    public function index(Request $request)
    {
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;

        $sucursalesSolicitadas = $request->query(
            'sucursales',
            []
        );

        if (! is_array($sucursalesSolicitadas)) {
            $sucursalesSolicitadas = [
                $sucursalesSolicitadas,
            ];
        }

        $sucursalesSolicitadas = collect(
            $sucursalesSolicitadas
        )
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $clientIds = $sucursalesSolicitadas === []
            ? $this->clientScope
            ->permittedClientIds($administrator)
            : $this->clientScope
            ->authorizeRequestedClients(
                $administrator,
                $sucursalesSolicitadas
            );

        if ($clientIds === []) {
            return response()->json([
                'ok'      => true,
                'message' =>
                'Accesos obtenidos correctamente',
                'data'    => [],
            ]);
        }

        $query = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
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
                'e.foto',
            ])
            ->where('e.id_portal', $idPortal)
            ->where('e.status', 1)
            ->where('e.eliminado', 0);
        $query->whereIn(
            'e.id_cliente',
            $clientIds
        );

        $empleados = $query
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->orderBy('e.materno')
            ->get();

        $employeeIds = $empleados
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        $tareasHoyPorEmpleado = $employeeIds === []
            ? collect()
            : DB::connection('portal_main')
            ->table('comunicacion360_empleado_tareas')
            ->select('empleado_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw(
                'SUM(CASE WHEN estatus = ? THEN 1 ELSE 0 END) AS completadas',
                ['completada']
            )
            ->selectRaw(
                'SUM(CASE WHEN estatus = ? THEN 1 ELSE 0 END) AS pendientes',
                ['pendiente']
            )
            ->selectRaw(
                'SUM(CASE WHEN tiene_evidencia = 1 THEN 1 ELSE 0 END) AS con_evidencia'
            )
            ->where('id_portal', $idPortal)
            ->whereIn('empleado_id', $employeeIds)
            ->whereNull('deleted_at')
            ->whereDate('created_at', now()->toDateString())
            ->groupBy('empleado_id')
            ->get()
            ->keyBy('empleado_id');

        $ultimasChecadasHoy = collect();

        if ($employeeIds !== []) {

            // Identificar la ultima checada del dia por empleado.

            $checadasOrdenadas = DB::connection('portal_main')
                ->table('checadas as c')
                ->join('empleados as e', function ($join) {
                    $join->on('e.id', '=', 'c.id_empleado')
                        ->on('e.id_portal', '=', 'c.id_portal')
                        ->on('e.id_cliente', '=', 'c.id_cliente');
                })
                ->select([

                    'c.id',
                    'c.id_portal',
                    'c.id_cliente',
                    'c.id_empleado',
                    'c.id_ubicacion',
                    'c.id_asignacion',
                    'c.latitud',
                    'c.longitud',
                    'c.fecha',
                    'c.check_time',
                    'c.tipo',
                    'c.clase',
                    'c.metodo_validacion',
                    'c.estatus_validacion',
                    'c.origen',
                    'c.distancia_metros',
                    'c.precision_metros',
                    'c.observacion',
                ])
                ->selectRaw(
                    'ROW_NUMBER() OVER (
                PARTITION BY c.id_empleado
                ORDER BY c.check_time DESC, c.id DESC
            ) AS orden_checada'
                )
                ->where('c.id_portal', $idPortal)
                ->whereIn('c.id_empleado', $employeeIds)
                ->whereDate('c.fecha', now()->toDateString());

            // Obtener solo la ultima checada y su ubicacion.
            $ultimasChecadasHoy = DB::connection('portal_main')
                ->query()
                ->fromSub($checadasOrdenadas, 'uc')
                ->leftJoin('checador_ubicaciones as u', function ($join) {
                    $join->on('u.id', '=', 'uc.id_ubicacion')
                        ->on('u.id_portal', '=', 'uc.id_portal')
                        ->on('u.id_cliente', '=', 'uc.id_cliente');
                })
                ->where('uc.orden_checada', 1)
                ->select([
                    'uc.*',
                    'u.nombre as ubicacion_nombre',
                    'u.tipo_zona as ubicacion_tipo_zona',
                    'u.radio_metros as ubicacion_radio_metros',
                ])
                ->get()
                ->keyBy('id_empleado');

/*
 * Recuperar la ubicacion autorizada mas cercana para
 * checadas GPS que no conservaron la asociacion original.
 *
 * Es un calculo de lectura; no modifica la checada.
 */
            $checadasSinUbicacion = $ultimasChecadasHoy->filter(
                static fn($checada) =>
                $checada->metodo_validacion === 'gps'
                && $checada->id_ubicacion === null
                && $checada->distancia_metros === null
                && $checada->id_asignacion !== null
                && $checada->latitud !== null
                && $checada->longitud !== null
            );

            if ($checadasSinUbicacion->isNotEmpty()) {

                $idsAsignaciones = $checadasSinUbicacion
                    ->pluck('id_asignacion')
                    ->unique()
                    ->values()
                    ->all();

                // Una consulta para obtener las ubicaciones permitidas.
                $ubicacionesPorAsignacion = DB::connection('portal_main')
                    ->table('checador_asignaciones as a')
                    ->join(
                        'checador_checada_plantilla_ubicaciones as pu',
                        'pu.id_plantilla',
                        '=',
                        'a.id_plantilla_checada'
                    )
                    ->join('checador_ubicaciones as u', function ($join) {
                        $join->on('u.id', '=', 'pu.id_ubicacion')
                            ->on('u.id_portal', '=', 'a.id_portal')
                            ->on('u.id_cliente', '=', 'a.id_cliente');
                    })
                    ->whereIn('a.id', $idsAsignaciones)
                    ->where('a.id_portal', $idPortal)
                    ->where('pu.activo', 1)
                    ->where('u.activa', 1)
                    ->where('u.tipo_zona', 'circle')
                    ->whereNotNull('u.latitud')
                    ->whereNotNull('u.longitud')
                    ->whereNotNull('u.radio_metros')
                    ->select([
                        'a.id as id_asignacion',
                        'a.id_cliente',
                        'a.id_empleado',
                        'u.id',
                        'u.nombre',
                        'u.tipo_zona',
                        'u.latitud',
                        'u.longitud',
                        'u.radio_metros',
                    ])
                    ->get()
                    ->groupBy('id_asignacion');

                foreach ($checadasSinUbicacion as $checada) {

                    $ubicaciones = $ubicacionesPorAsignacion->get(
                        $checada->id_asignacion,
                        collect()
                    );

                    $ubicacionCercana = null;
                    $distanciaMenor   = null;

                    foreach ($ubicaciones as $ubicacion) {

                        // Mantener el aislamiento por cliente y empleado.
                        if (
                            (int) $ubicacion->id_cliente !== (int) $checada->id_cliente
                            || (int) $ubicacion->id_empleado !== (int) $checada->id_empleado
                        ) {
                            continue;
                        }

                        $lat1 = deg2rad((float) $checada->latitud);
                        $lat2 = deg2rad((float) $ubicacion->latitud);

                        $deltaLat = $lat2 - $lat1;
                        $deltaLng = deg2rad(
                            (float) $ubicacion->longitud -
                            (float) $checada->longitud
                        );

                        $a = sin($deltaLat / 2) ** 2
                         + cos($lat1) * cos($lat2)
                         * sin($deltaLng / 2) ** 2;

                        $a = max(0.0, min(1.0, $a));

                        $distancia = 6371000 * 2 * atan2(
                            sqrt($a),
                            sqrt(1 - $a)
                        );

                        if (
                            $distanciaMenor === null
                            || $distancia < $distanciaMenor
                        ) {
                            $distanciaMenor   = $distancia;
                            $ubicacionCercana = $ubicacion;
                        }
                    }

                    if ($ubicacionCercana !== null) {

                        // Completar solo el resultado en memoria.
                        $checada->ubicacion_nombre =
                        $ubicacionCercana->nombre;

                        $checada->ubicacion_tipo_zona =
                        $ubicacionCercana->tipo_zona;

                        $checada->ubicacion_radio_metros =
                        $ubicacionCercana->radio_metros;

                        $checada->distancia_metros =
                            round($distanciaMenor, 2);

                        $checada->ubicacion_recalculada = true;
                    }
                }
            }

        }

        $eventosHoy = $employeeIds === []
            ? collect()
            : DB::connection('portal_main')
            ->table('calendario_eventos as ce')
            ->join(
                'eventos_option as eo',
                'eo.id',
                '=',
                'ce.id_tipo'
            )
            ->whereIn(
                'ce.id_empleado',
                $employeeIds
            )
            ->where('ce.eliminado', 0)
            ->whereIn('ce.estado_aprobacion', [
                'no_requiere',
                'aprobado',
            ])
            ->whereDate(
                'ce.inicio',
                '<=',
                now()->toDateString()
            )
            ->whereDate(
                'ce.fin',
                '>=',
                now()->toDateString()
            )
            ->select([
                'ce.id',
                'ce.id_empleado',
                'ce.id_tipo',
                'ce.inicio',
                'ce.fin',
                'ce.estado_aprobacion',
                'eo.name as tipo_nombre',
                'eo.color as tipo_color',
                'eo.id_portal as tipo_id_portal',
            ])
            ->orderByDesc('ce.id')
            ->get()
            ->groupBy(
                fn($evento) => (int) $evento->id_empleado
            )
            ->map(
                fn($eventos) => $eventos->first()
            );

        $data = $empleados->map(function ($item) use (
            $eventosHoy,
            $tareasHoyPorEmpleado,
            $ultimasChecadasHoy
        ) {
            $nombreCompleto = trim(collect([
                $item->nombre,
                $item->paterno,
                $item->materno,
            ])->filter()->implode(' '));

            $tieneAcceso = ! empty($item->password) && (int) $item->status === 1;

            $resumenTareas = $tareasHoyPorEmpleado->get(
                (int) $item->id
            );

            $tareasTotal = (int) ($resumenTareas->total ?? 0);

            $tareasCompletadas = (int) (
                $resumenTareas->completadas ?? 0
            );

            $tareasPendientes = (int) (
                $resumenTareas->pendientes ?? 0
            );

            $tareasConEvidencia = (int) (
                $resumenTareas->con_evidencia ?? 0
            );

            $tareasProgreso = $tareasTotal > 0
                ? round(($tareasCompletadas / $tareasTotal) * 100)
                : 0;
            $ultimaChecada = $ultimasChecadasHoy->get(
                (int) $item->id
            );
            $eventoHoy = $eventosHoy->get(
                (int) $item->id
            );

            $checadorEstado      = 'sin_checada';
            $checadorEstadoLabel = 'Sin checada';

            if ($ultimaChecada) {
                $tipo  = strtolower(trim((string) $ultimaChecada->tipo));
                $clase = strtolower(trim((string) $ultimaChecada->clase));

                if ($tipo === 'in' && in_array($clase, ['work', 'meal', 'break'], true)) {
                    $checadorEstado      = 'trabajando';
                    $checadorEstadoLabel = 'Trabajando';
                } elseif ($tipo === 'out' && $clase === 'meal') {
                    $checadorEstado      = 'en_comida';
                    $checadorEstadoLabel = 'En comida';
                } elseif ($tipo === 'out' && $clase === 'break') {
                    $checadorEstado      = 'en_descanso';
                    $checadorEstadoLabel = 'En descanso';
                } elseif ($tipo === 'out' && $clase === 'work') {
                    $checadorEstado      = 'salida_registrada';
                    $checadorEstadoLabel = 'Salida registrada';
                }
            }
            if (! $ultimaChecada && $eventoHoy) {
                $checadorEstado      = 'evento_calendario';
                $checadorEstadoLabel =
                (string) $eventoHoy->tipo_nombre;
            }
            $fotoBase64 = null;

            if (! empty($item->foto)) {
                $fotoPath = $this->photoPaths->resolveExistingPathForScope(
                    (int) $item->id_portal,
                    (int) $item->id_cliente,
                    (int) $item->id,
                    $item->foto
                );

                if ($fotoPath !== null) {
                    $mime       = mime_content_type($fotoPath);
                    $fotoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fotoPath));
                }
            }
            $tokenActivo = DB::connection('portal_main')
                ->table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\Auth\\EmpleadoAuth')
                ->where('tokenable_id', (int) $item->id)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->orderByDesc('last_used_at')
                ->first();

            $sesionActiva = false;

            if ($tokenActivo && $tokenActivo->last_used_at) {
                $sesionActiva =
                (int) now()->diffInMinutes($tokenActivo->last_used_at, true) <= 15;
            }

            $asignacionHorario = DB::connection('portal_main')
                ->table('checador_asignaciones as a')
                ->join('checador_horario_plantillas as h', 'h.id', '=', 'a.id_plantilla_horario')
                ->where('a.id_portal', (int) $item->id_portal)
                ->where('a.id_cliente', (int) $item->id_cliente)
                ->where('a.id_empleado', (int) $item->id)
                ->where('a.activa', 1)
                ->whereDate('a.fecha_inicio', '<=', now()->toDateString())
                ->where(function ($q) {
                    $q->whereNull('a.fecha_fin')
                        ->orWhereDate('a.fecha_fin', '>=', now()->toDateString());
                })
                ->select([
                    'h.id',
                    'h.nombre',
                ])
                ->orderByDesc('a.fecha_inicio')
                ->first();

            $horarioDetalles = collect();

            if ($asignacionHorario) {
                $horarioDetalles = DB::connection('portal_main')
                    ->table('checador_horario_detalles')
                    ->where('id_plantilla', (int) $asignacionHorario->id)
                    ->orderBy('dia_semana')
                    ->get()
                    ->map(function ($detalle) {
                        return [
                            'dia_semana'   => (int) $detalle->dia_semana,
                            'labora'       => (int) $detalle->labora === 1,
                            'hora_entrada' => $detalle->hora_entrada
                                ? substr($detalle->hora_entrada, 0, 5)
                                : null,
                            'hora_salida'  => $detalle->hora_salida
                                ? substr($detalle->hora_salida, 0, 5)
                                : null,
                        ];
                    })->values();
            }

            $diasLabora = $horarioDetalles
                ->filter(fn($dia) => $dia['labora'])
                ->values();

            $horarioResumen = null;

            if ($diasLabora->isNotEmpty()) {
                $primerDia = $diasLabora->first();
                $ultimoDia = $diasLabora->last();

                $horarioResumen = $primerDia['hora_entrada'] . ' - ' . $primerDia['hora_salida'];
            }
            return [

                'id'                                   => (int) $item->id,
                'id_portal'                            => (int) $item->id_portal,
                'id_empleado'                          => $item->id_empleado,
                'nombre'                               => $item->nombre,
                'paterno'                              => $item->paterno,
                'materno'                              => $item->materno,
                'nombre_completo'                      => $nombreCompleto,
                'foto_base64'                          => $fotoBase64,
                'correo'                               => $item->correo,
                'puesto'                               => $item->puesto,
                'departamento'                         => $item->departamento,
                'id_cliente'                           => (int) $item->id_cliente,
                'nombre_sucursal'                      => 'Sucursal ' . (int) $item->id_cliente,
                'status'                               => (int) $item->status,
                'tiene_acceso'                         => $tieneAcceso,
                'force_password_change'                => (int) ($item->force_password_change ?? 0),
                'last_login_at'                        => $item->last_login_at,
                'ultimo_envio_credenciales'            => $item->password_changed_at,
                'tareas_total'                         => $tareasTotal,
                'tareas_completadas'                   => $tareasCompletadas,
                'tareas_pendientes'                    => $tareasPendientes,
                'tareas_con_evidencia'                 => $tareasConEvidencia,
                'tareas_progreso'                      => $tareasProgreso,
                'tiene_tareas_hoy'                     => $tareasTotal > 0,
                'checador_estado'                      => $checadorEstado,
                'checador_estado_label'                => $checadorEstadoLabel,
                'evento_vigente'                       => (bool) $eventoHoy,
                'evento_id'                            => $eventoHoy
                    ? (int) $eventoHoy->id
                    : null,
                'evento_tipo_id'                       => $eventoHoy
                    ? (int) $eventoHoy->id_tipo
                    : null,
                'evento_tipo_global'                   => (bool) (
                    $eventoHoy &&
                    $eventoHoy->tipo_id_portal === null
                ),
                'evento_tipo'                          => $eventoHoy?->tipo_nombre,
                'evento_color'                         => $eventoHoy?->tipo_color,
                'evento_inicio'                        => $eventoHoy?->inicio,
                'evento_fin'                           => $eventoHoy?->fin,
                'ultima_checada'                       => $ultimaChecada?->check_time,
                'ultima_checada_tipo'                  => $ultimaChecada?->tipo,
                'ultima_checada_clase'                 => $ultimaChecada?->clase,

                'ultima_checada_estatus_validacion'    =>
                $ultimaChecada?->estatus_validacion,

                'ultima_checada_metodo_validacion'     =>
                $ultimaChecada?->metodo_validacion,

                'ultima_checada_origen'                =>
                $ultimaChecada?->origen,

                'ultima_checada_ubicacion_nombre'      =>
                $ultimaChecada?->ubicacion_nombre,

                'ultima_checada_ubicacion_tipo_zona'   =>
                $ultimaChecada?->ubicacion_tipo_zona,

                'ultima_checada_distancia_metros'      =>
                $ultimaChecada?->distancia_metros !== null
                    ? (float) $ultimaChecada->distancia_metros
                    : null,

                'ultima_checada_radio_metros'          =>
                $ultimaChecada?->ubicacion_radio_metros !== null
                    ? (float) $ultimaChecada->ubicacion_radio_metros
                    : null,

                'ultima_checada_exceso_metros'         =>
                $ultimaChecada?->ubicacion_tipo_zona === 'circle'
                && $ultimaChecada?->distancia_metros !== null
                && $ultimaChecada?->ubicacion_radio_metros !== null
                    ? round(max(
                    0,
                    (float) $ultimaChecada->distancia_metros
                     - (float) $ultimaChecada->ubicacion_radio_metros
                ), 2)
                    : null,

                'ultima_checada_ubicacion_recalculada' =>
                (bool) ($ultimaChecada?->ubicacion_recalculada ?? false),

                'ultima_checada_precision_metros'      =>
                $ultimaChecada?->precision_metros !== null
                    ? (float) $ultimaChecada->precision_metros
                    : null,

                'ultima_checada_observacion'           =>
                $ultimaChecada?->observacion,
                'sesion_activa'                        => $sesionActiva,
                'ultimo_acceso_token'                  => $tokenActivo?->last_used_at,
                'horario_asignado'                     => (bool) $asignacionHorario,
                'horario_resumen'                      => $horarioResumen,
                'horario_nombre'                       => $asignacionHorario?->nombre,
                'horario_detalles'                     => $horarioDetalles,
            ];
        })->values();

        return response()->json([
            'ok'      => true,
            'message' => 'Accesos obtenidos correctamente',
            'data'    => $data,
        ]);
    }

    public function generar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empleados'   => 'required|array|min:1',
            'empleados.*' => 'integer|min:1',
            'locale'      => 'nullable|in:es,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $idUsuario     = (int) $administrator->id;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);
        $locale    = $request->input('locale', 'es');
        $empleados = collect($request->input('empleados', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $registros = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id_cliente', $clientIds)
            ->whereIn('e.id', $empleados->all())
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->get()
            ->keyBy('id');

        $detalle      = [];
        $procesados   = 0;
        $generados    = 0;
        $fallidos     = 0;
        $mailFallidos = 0;

        foreach ($empleados as $empleadoId) {
            $procesados++;

            $empleado = $registros->get($empleadoId);

            if (! $empleado) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleadoId,
                    'ok'   => false,
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            if ((int) $empleado->status !== 1) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_STATUS',
                ];
                continue;
            }

            if (! empty($empleado->fecha_salida)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EXIT_DATE',
                ];
                continue;
            }

            if (empty($empleado->correo)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_EMAIL',
                ];
                continue;
            }

            if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_EMAIL',
                ];
                continue;
            }

            if (! empty($empleado->password)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'ALREADY_HAS_ACCESS',
                ];
                continue;
            }

            $passwordPlano = $this->generarPasswordSeguro(12);

            DB::connection('portal_main')->beginTransaction();

            try {
                DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id', $empleado->id)
                    ->update([
                        'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                        'force_password_change' => 1,
                        'password_changed_at'   => now(),
                        'login_attempts'        => 0,
                        'locked_until'          => null,
                        'id_usuario'            => $idUsuario,
                        'edicion'               => now(),
                    ]);

                $licenciaActiva = DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->where('id_portal', $idPortal)
                    ->where('id_empleado', $empleado->id)
                    ->where('activo_cobrable', 1)
                    ->exists();

                if (! $licenciaActiva) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_licencias')
                        ->insert([
                            'id_portal'         => $idPortal,
                            'id_empleado'       => $empleado->id,
                            'id_cliente'        => $empleado->id_cliente,
                            'tipo_movimiento'   => 'alta',
                            'activo_cobrable'   => 1,
                            'costo_mensual'     => 3.00,
                            'moneda'            => 'USD',
                            'fecha_movimiento'  => now(),
                            'id_usuario_accion' => $idUsuario,
                            'observaciones'     => 'Generación inicial de credenciales',
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                }

                DB::connection('portal_main')->commit();
                try {
                    Mail::send(
                        'emails.comunicacion360.accesos_generados',
                        [
                            'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                            'correo'        => $empleado->correo,
                            'passwordPlano' => $passwordPlano,
                            'loginUrl'      => rtrim((string) config('services.miportal.base_url'), '/') . '/login',
                            'locale'        => $locale,
                        ],
                        function ($message) use ($empleado) {
                            $message->to($empleado->correo)
                                ->subject('Tus accesos de Communication 360');
                        }
                    );

                    $generados++;

                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'GENERATED',
                    ];
                } catch (\Throwable $mailException) {
                    \Log::error('Error al enviar correo de accesos Comunicación 360', [
                        'empleado_id' => $empleado->id,
                        'error'       => $mailException->getMessage(),
                    ]);

                    $generados++;
                    $mailFallidos++;
                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'GENERATED_MAIL_FAILED',
                    ];
                }
            } catch (\Throwable $e) {
                DB::connection('portal_main')->rollBack();

                \Log::error('Error al generar accesos Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'error'       => $e->getMessage(),
                ]);

                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'PROCESS_FAILED',
                ];
            }
        }

        $clientesProcesados = $registros
            ->pluck('id_cliente')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $this->auditoria->registrar([
            'id_portal'    => $idPortal,
            'id_cliente'   => $clientesProcesados->count() === 1
                ? (int) $clientesProcesados->first()
                : null,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'accesos_colaboradores',
            'entidad_id'   => null,
            'accion'       => 'generar_accesos',
            'resultado'    => $generados === 0
                ? 'fallido'
                : (($fallidos > 0 || $mailFallidos > 0)
                    ? 'parcial'
                    : 'exitoso'),
            'descripcion'  =>
            'Generación masiva de accesos de colaboradores.',
            'datos_nuevos' => [
                'clientes'      => $clientesProcesados->all(),
                'empleados'     => $empleados->all(),
                'procesados'    => $procesados,
                'generados'     => $generados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
            ],
        ], $request);

        return response()->json([
            'ok'   => true,
            'code' => ($fallidos > 0 || $mailFallidos > 0)
                ? 'COMPLETED_WITH_ERRORS'
                : 'COMPLETED_SUCCESS',
            'data' => [
                'procesados'    => $procesados,
                'generados'     => $generados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
                'detalle'       => $detalle,
            ],
        ]);
    }

    public function actualizar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'empleados'   => 'required|array|min:1',
            'empleados.*' => 'integer|min:1',
            'locale'      => 'nullable|in:es,en',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $idUsuario     = (int) $administrator->id;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);
        $locale    = $request->input('locale', 'es');
        $empleados = collect($request->input('empleados', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $registros = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id_cliente', $clientIds)
            ->whereIn('e.id', $empleados->all())
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->get()
            ->keyBy('id');

        $detalle      = [];
        $procesados   = 0;
        $actualizados = 0;
        $fallidos     = 0;
        $mailFallidos = 0;

        foreach ($empleados as $empleadoId) {
            $procesados++;

            $empleado = $registros->get($empleadoId);

            if (! $empleado) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleadoId,
                    'ok'   => false,
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            if ((int) $empleado->status !== 1) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_STATUS',
                ];
                continue;
            }

            if (! empty($empleado->fecha_salida)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EXIT_DATE',
                ];
                continue;
            }

            if (empty($empleado->correo)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_EMAIL',
                ];
                continue;
            }

            if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_EMAIL',
                ];
                continue;
            }

            // 🔴 CLAVE: aquí es lo contrario a generar
            if (empty($empleado->password)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_ACCESS_TO_UPDATE',
                ];
                continue;
            }

            $passwordPlano = $this->generarPasswordSeguro(12);

            DB::connection('portal_main')->beginTransaction();

            try {
                DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id', $empleado->id)
                    ->update([
                        'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                        'force_password_change' => 1,
                        'password_changed_at'   => now(),
                        'login_attempts'        => 0,
                        'locked_until'          => null,
                        'id_usuario'            => $idUsuario,
                        'edicion'               => now(),
                    ]);

                // 🔵 Registrar rotación (NO crea nueva licencia)
                DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->insert([
                        'id_portal'         => $idPortal,
                        'id_empleado'       => $empleado->id,
                        'id_cliente'        => $empleado->id_cliente,
                        'tipo_movimiento'   => 'rotacion',
                        'activo_cobrable'   => 1,
                        'costo_mensual'     => 3.00,
                        'moneda'            => 'USD',
                        'fecha_movimiento'  => now(),
                        'id_usuario_accion' => $idUsuario,
                        'observaciones'     => 'Rotación de credenciales',
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                DB::connection('portal_main')->commit();

                try {
                    Mail::send(
                        'emails.comunicacion360.accesos_generados',
                        [
                            'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                            'correo'        => $empleado->correo,
                            'passwordPlano' => $passwordPlano,
                            'loginUrl'      => rtrim((string) config('services.miportal.base_url'), '/') . '/login',
                            'locale'        => $locale,
                        ],
                        function ($message) use ($empleado) {
                            $message->to($empleado->correo)
                                ->subject('Actualización de accesos - Communication 360');
                        }
                    );

                    $actualizados++;
                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'UPDATED',
                    ];
                } catch (\Throwable $mailException) {
                    \Log::error('Error al enviar correo de actualización Comunicación 360', [
                        'empleado_id' => $empleado->id,
                        'error'       => $mailException->getMessage(),
                    ]);

                    $actualizados++;
                    $mailFallidos++;

                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'UPDATED_MAIL_FAILED',
                    ];
                }

            } catch (\Throwable $e) {
                DB::connection('portal_main')->rollBack();

                \Log::error('Error al actualizar accesos Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'error'       => $e->getMessage(),
                ]);

                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'PROCESS_FAILED',
                ];
            }
        }

        $clientesProcesados = $registros
            ->pluck('id_cliente')
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $this->auditoria->registrar([
            'id_portal'    => $idPortal,
            'id_cliente'   => $clientesProcesados->count() === 1
                ? (int) $clientesProcesados->first()
                : null,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'accesos_colaboradores',
            'entidad_id'   => null,
            'accion'       => 'actualizar_accesos',
            'resultado'    => $actualizados === 0
                ? 'fallido'
                : (($fallidos > 0 || $mailFallidos > 0)
                    ? 'parcial'
                    : 'exitoso'),
            'descripcion'  =>
            'Actualización masiva de accesos de colaboradores.',
            'datos_nuevos' => [
                'clientes'      => $clientesProcesados->all(),
                'empleados'     => $empleados->all(),
                'procesados'    => $procesados,
                'actualizados'  => $actualizados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
            ],
        ], $request);

        return response()->json([
            'ok'   => true,
            'code' => ($fallidos > 0 || $mailFallidos > 0)
                ? 'COMPLETED_WITH_ERRORS'
                : 'COMPLETED_SUCCESS',
            'data' => [
                'procesados'    => $procesados,
                'actualizados'  => $actualizados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
                'detalle'       => $detalle,
            ],
        ]);
    }

    public function generarIndividual(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'id_empleado'           => 'required|integer|min:1',
            'password_type'         => 'required|in:auto,manual',
            'password'              => [
                'required',
                'string',
                'confirmed',
                Password::min(10)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'password_confirmation' => 'required|string',
            'locale'                => 'nullable|in:es,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $data          = $validator->validated();
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $idUsuario     = (int) $administrator->id;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);

        $resultado = $this->procesarAccesoIndividual(
            idPortal: $idPortal,
            idUsuario: $idUsuario,
            clientIds: $clientIds,
            empleadoId: (int) $data['id_empleado'],
            passwordPlano: $data['password'],
            modo: 'generate',
            locale: $request->input('locale', 'es')
        );
        $idEmpleado = (int) $data['id_empleado'];

        $idClienteAuditoria = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $idEmpleado)
            ->where('id_portal', $idPortal)
            ->whereIn('id_cliente', $clientIds)
            ->value('id_cliente');

        $correoEnviado = $resultado['data']['mail_sent'] ?? null;

        $this->auditoria->registrar([
            'id_portal'    => $idPortal,
            'id_cliente'   => $idClienteAuditoria
                ? (int) $idClienteAuditoria
                : null,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'acceso_colaborador',
            'entidad_id'   => $idEmpleado,
            'accion'       => 'generar_acceso',
            'resultado'    => ! ($resultado['ok'] ?? false)
                ? 'fallido'
                : ($correoEnviado === false
                    ? 'parcial'
                    : 'exitoso'),
            'descripcion'  =>
            'Generación individual de acceso de colaborador.',
            'datos_nuevos' => [
                'id_empleado'  => $idEmpleado,
                'codigo'       => $resultado['code'] ?? null,
                'mail_enviado' => $correoEnviado,
            ],
        ], $request);

        $status = $resultado['ok'] ? 200 : 422;

        return response()->json($resultado, $status);
    }

    public function actualizarIndividual(Request $request)
    {
        $validator = Validator::make($request->all(), [

            'id_empleado'           => 'required|integer|min:1',
            'password_type'         => 'required|in:auto,manual',
            'password'              => [
                'required',
                'string',
                'confirmed',
                Password::min(10)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'password_confirmation' => 'required|string',
            'locale'                => 'nullable|in:es,en',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $data          = $validator->validated();
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $idUsuario     = (int) $administrator->id;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);

        $resultado = $this->procesarAccesoIndividual(
            idPortal: $idPortal,
            idUsuario: $idUsuario,
            clientIds: $clientIds,
            empleadoId: (int) $data['id_empleado'],
            passwordPlano: $data['password'],
            modo: 'update',
            locale: $request->input('locale', 'es')
        );

        $idEmpleado = (int) $data['id_empleado'];

        $idClienteAuditoria = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', $idEmpleado)
            ->where('id_portal', $idPortal)
            ->whereIn('id_cliente', $clientIds)
            ->value('id_cliente');

        $correoEnviado = $resultado['data']['mail_sent'] ?? null;

        $this->auditoria->registrar([
            'id_portal'    => $idPortal,
            'id_cliente'   => $idClienteAuditoria
                ? (int) $idClienteAuditoria
                : null,
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion360',
            'entidad_tipo' => 'acceso_colaborador',
            'entidad_id'   => $idEmpleado,
            'accion'       => 'actualizar_acceso',
            'resultado'    => ! ($resultado['ok'] ?? false)
                ? 'fallido'
                : ($correoEnviado === false
                    ? 'parcial'
                    : 'exitoso'),
            'descripcion'  =>
            'Actualización individual de acceso de colaborador.',
            'datos_nuevos' => [
                'id_empleado'  => $idEmpleado,
                'codigo'       => $resultado['code'] ?? null,
                'mail_enviado' => $correoEnviado,
            ],
        ], $request);

        $status = $resultado['ok'] ? 200 : 422;

        return response()->json($resultado, $status);
    }

    public function cerrarSesion($id, Request $request)
    {
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);

        $empleado = \App\Models\Auth\EmpleadoAuth::query()
            ->where('id', (int) $id)
            ->where('id_portal', $idPortal)
            ->whereIn('id_cliente', $clientIds)
            ->where(function ($query) {
                $query
                    ->where('eliminado', 0)
                    ->orWhereNull('eliminado');
            })
            ->first();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        $tokensEliminados = $empleado->tokens()->count();

        app(\App\Services\Auth\EmpleadoSessionService::class)->revokeAllAccessTokens($empleado);

        $this->auditoria->registrar([
            'id_portal'        => $idPortal,
            'id_cliente'       => (int) $empleado->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion360',
            'entidad_tipo'     => 'acceso_colaborador',
            'entidad_id'       => (int) $empleado->id,
            'accion'           => 'cerrar_sesion',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Sesión del colaborador cerrada por un administrador.',
            'datos_anteriores' => [
                'tokens_activos' => $tokensEliminados,
            ],
            'datos_nuevos'     => [
                'tokens_activos' => 0,
            ],
        ], $request);

        \Log::info('Sesión cerrada por administrador', [
            'empleado_id'       => $empleado->id,
            'empleado_nombre'   => trim($empleado->nombre . ' ' . $empleado->paterno),
            'tokens_eliminados' => $tokensEliminados,
            'admin_ip'          => $request->ip(),
            'user_agent'        => $request->userAgent(),
        ]);

        return response()->json([
            'ok'                => true,
            'message'           => 'Sesión cerrada correctamente',
            'tokens_eliminados' => $tokensEliminados,
        ]);
    }
    public function eliminarAcceso(Request $request, $id)
    {
        $administrator = $this->administrator($request);
        $idPortal      = (int) $administrator->id_portal;
        $idUsuario     = (int) $administrator->id;
        $clientIds     = $this->clientScope
            ->permittedClientIds($administrator);

        $empleado = DB::connection('portal_main')
            ->table('empleados')
            ->where('id', (int) $id)
            ->where('id_portal', $idPortal)
            ->whereIn('id_cliente', $clientIds)
            ->where(function ($q) {
                $q->where('eliminado', 0)
                    ->orWhereNull('eliminado');
            })
            ->first();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'code'    => 'NOT_FOUND',
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        DB::connection('portal_main')->beginTransaction();

        try {
            DB::connection('portal_main')
                ->table('empleados')
                ->where('id', $empleado->id)
                ->where('id_portal', $idPortal)
                ->where('id_cliente', (int) $empleado->id_cliente)
                ->update([
                    'password'              => null,
                    'force_password_change' => 0,
                    'login_attempts'        => 0,
                    'locked_until'          => null,
                    'id_usuario'            => $idUsuario,
                    'edicion'               => now(),
                ]);

            $tokensEliminados = app(\App\Services\Auth\EmpleadoSessionService::class)
                ->revokeAllAccessTokensById((int) $empleado->id);

            $dispositivos = DB::connection('portal_main')
                ->table('checador_dispositivos')
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $empleado->id_cliente)
                ->where('tipo', 'biometrico')
                ->where('activo', 1)
                ->get();

            foreach ($dispositivos as $dispositivo) {
                DB::connection('portal_main')
                    ->table('checador_dispositivo_sync')
                    ->insert([
                        'id_portal'        => $idPortal,
                        'id_cliente'       => $empleado->id_cliente,
                        'id_dispositivo'   => $dispositivo->id,
                        'id_empleado'      => $empleado->id,
                        'clave_biometrica' => $empleado->id_empleado,
                        'accion'           => 'eliminar',
                        'origen'           => 'comunicacion360',
                        'estatus'          => 'pendiente',
                        'intentos'         => 0,
                        'payload'          => json_encode([
                            'accion'           => 'delete_user_from_device',
                            'empleado_pk'      => $empleado->id,
                            'clave_biometrica' => $empleado->id_empleado,
                            'nombre'           => trim($empleado->nombre . ' ' . $empleado->paterno . ' ' . $empleado->materno),
                            'correo'           => $empleado->correo,
                            'dispositivo_id'   => $dispositivo->id,
                            'dispositivo'      => $dispositivo->nombre,
                            'marca'            => $dispositivo->marca,
                            'modelo'           => $dispositivo->modelo,
                            'numero_serie'     => $dispositivo->numero_serie,
                        ]),
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ]);
            }

            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => (int) $empleado->id_cliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     =>
                $this->administratorName($administrator),
                'modulo'           => 'comunicacion360',
                'entidad_tipo'     => 'acceso_colaborador',
                'entidad_id'       => (int) $empleado->id,
                'accion'           => 'eliminar_acceso',
                'resultado'        => 'exitoso',
                'descripcion'      =>
                'Acceso del colaborador eliminado.',
                'datos_anteriores' => [
                    'tenia_acceso'   => ! empty($empleado->password),
                    'tokens_activos' => $tokensEliminados,
                ],
                'datos_nuevos'     => [
                    'tiene_acceso'     => false,
                    'tokens_activos'   => 0,
                    'sincronizaciones' => $dispositivos->count(),
                    'clave_biometrica' => $empleado->id_empleado,
                ],
            ], $request);

            DB::connection('portal_main')->commit();

            return response()->json([
                'ok'      => true,
                'code'    => 'ACCESS_DELETE_REGISTERED',
                'message' => 'Acceso eliminado y sincronización biométrica registrada',
                'data'    => [
                    'empleado_id'              => (int) $empleado->id,
                    'clave_biometrica'         => $empleado->id_empleado,
                    'dispositivos_encontrados' => $dispositivos->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            \Log::error('Error al eliminar acceso Comunicación 360', [
                'empleado_id' => $id,
                'id_portal'   => $idPortal,
                'error'       => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'code'    => 'PROCESS_FAILED',
                'message' => 'No fue posible eliminar el acceso',
            ], 500);
        }
    }
    private function procesarAccesoIndividual(
        int $idPortal,
        int $idUsuario,
        array $clientIds,
        int $empleadoId,
        string $passwordPlano,
        string $modo,
        string $locale = 'es'
    ): array {
        $empleado = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id_cliente', $clientIds)
            ->where('e.id', $empleadoId)
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->first();

        if (! $empleado) {
            return [
                'ok'   => false,
                'code' => 'NOT_FOUND',
                'data' => [
                    'id' => $empleadoId,
                ],
            ];
        }

        if ((int) $empleado->status !== 1) {
            return [
                'ok'   => false,
                'code' => 'INVALID_STATUS',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (! empty($empleado->fecha_salida)) {
            return [
                'ok'   => false,
                'code' => 'EXIT_DATE',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (empty($empleado->correo)) {
            return [
                'ok'   => false,
                'code' => 'NO_EMAIL',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok'   => false,
                'code' => 'INVALID_EMAIL',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if ($modo === 'generate' && ! empty($empleado->password)) {
            return [
                'ok'   => false,
                'code' => 'ALREADY_HAS_ACCESS',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if ($modo === 'update' && empty($empleado->password)) {
            return [
                'ok'   => false,
                'code' => 'NO_ACCESS_TO_UPDATE',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        DB::connection('portal_main')->beginTransaction();

        try {
            DB::connection('portal_main')
                ->table('empleados')
                ->where('id', $empleado->id)
                ->update([
                    'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                    'force_password_change' => 1,
                    'password_changed_at'   => now(),
                    'login_attempts'        => 0,
                    'locked_until'          => null,
                    'id_usuario'            => $idUsuario,
                    'edicion'               => now(),
                ]);

            if ($modo === 'generate') {
                $licenciaActiva = DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->where('id_portal', $idPortal)
                    ->where('id_empleado', $empleado->id)
                    ->where('activo_cobrable', 1)
                    ->exists();

                if (! $licenciaActiva) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_licencias')
                        ->insert([
                            'id_portal'         => $idPortal,
                            'id_empleado'       => $empleado->id,
                            'id_cliente'        => $empleado->id_cliente,
                            'tipo_movimiento'   => 'alta',
                            'activo_cobrable'   => 1,
                            'costo_mensual'     => 3.00,
                            'moneda'            => 'USD',
                            'fecha_movimiento'  => now(),
                            'id_usuario_accion' => $idUsuario,
                            'observaciones'     => 'Generación individual de credenciales',
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                }
            }

            if ($modo === 'update') {
                DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->insert([
                        'id_portal'         => $idPortal,
                        'id_empleado'       => $empleado->id,
                        'id_cliente'        => $empleado->id_cliente,
                        'tipo_movimiento'   => 'rotacion',
                        'activo_cobrable'   => 1,
                        'costo_mensual'     => 3.00,
                        'moneda'            => 'USD',
                        'fecha_movimiento'  => now(),
                        'id_usuario_accion' => $idUsuario,
                        'observaciones'     => 'Rotación individual de credenciales',
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
            }

            DB::connection('portal_main')->commit();

            try {
                Mail::send(
                    'emails.comunicacion360.accesos_generados',
                    [
                        'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                        'correo'        => $empleado->correo,
                        'passwordPlano' => $passwordPlano,
                        'loginUrl'      => rtrim((string) config('services.miportal.base_url'), '/') . '/login',
                        'locale'        => $locale,

                    ],
                    function ($message) use ($empleado, $modo, $locale) {
                        $isEnglish = $locale === 'en';

                        $subject = $modo === 'generate'
                            ? ($isEnglish ? 'Your My Portal Access' : 'Tus accesos a Mi Portal')
                            : ($isEnglish ? 'My Portal Access Update' : 'Actualización de accesos - Mi Portal');

                        $message->to($empleado->correo)
                            ->subject($subject);
                    }
                );

                return [
                    'ok'   => true,
                    'code' => $modo === 'generate' ? 'GENERATED' : 'UPDATED',
                    'data' => [
                        'id'        => (int) $empleado->id,
                        'correo'    => $empleado->correo,
                        'mail_sent' => true,
                    ],
                ];
            } catch (\Throwable $mailException) {
                \Log::error('Error al enviar correo individual Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'modo'        => $modo,
                    'error'       => $mailException->getMessage(),
                ]);

                return [
                    'ok'   => true,
                    'code' => $modo === 'generate' ? 'GENERATED_MAIL_FAILED' : 'UPDATED_MAIL_FAILED',
                    'data' => [
                        'id'        => (int) $empleado->id,
                        'correo'    => $empleado->correo,
                        'mail_sent' => false,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            \Log::error('Error al procesar acceso individual Comunicación 360', [
                'empleado_id' => $empleadoId,
                'modo'        => $modo,
                'error'       => $e->getMessage(),
            ]);

            return [
                'ok'   => false,
                'code' => 'PROCESS_FAILED',
                'data' => [
                    'id' => $empleadoId,
                ],
            ];
        }
    }

    private function generarPasswordSeguro($length = 12)
    {
        $mayusculas = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $minusculas = 'abcdefghijklmnopqrstuvwxyz';
        $numeros    = '0123456789';
        $simbolos   = '!@#$%^&*()-_=+[]{}<>?';

        $password = [
            $mayusculas[random_int(0, strlen($mayusculas) - 1)],
            $minusculas[random_int(0, strlen($minusculas) - 1)],
            $numeros[random_int(0, strlen($numeros) - 1)],
            $simbolos[random_int(0, strlen($simbolos) - 1)],
        ];

        $all = $mayusculas . $minusculas . $numeros . $simbolos;

        for ($i = 4; $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }

    private function administratorName(
        AdministradorAuth $administrator
    ): string {
        return trim(collect([
            $administrator->nombre,
            $administrator->paterno,
            $administrator->materno,
        ])->filter()->implode(' '));
    }

    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }

}
