<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\CalendarioEvento;
use App\Models\ClienteTalent;
use App\Models\Empleado;
use App\Models\EventosOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

class CalendarioController extends Controller
{
    //
    public function colaboradoresPorSucursal(Request $request)
    {
        $raw = $request->query('id_cliente', []);

        if (! is_array($raw)) {
            $raw = is_string($raw) ? explode(',', $raw) : [$raw];
        }

        $ids = collect($raw)
            ->filter(fn($v) => $v !== null && $v !== '')
            ->map(fn($v) => (int) $v)
            ->filter(fn($v) => $v > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return response()->json([
                'clientes'  => [],
                'empleados' => [],
            ]);
        }

        $clientes = ClienteTalent::whereIn('id', $ids->all())
            ->select('id', 'nombre')
            ->get();

        $empleados = Empleado::with('cliente')
            ->whereIn('id_cliente', $ids->all())
            ->where('status', 1)
            ->select('id', 'id_empleado', 'nombre', 'paterno', 'materno', 'id_cliente')
            ->get()
            ->map(function ($e) {
                return [
                    'id'              => $e->id,
                    'id_empleado'     => $e->id_empleado,
                    'nombre'          => $e->nombre,
                    'paterno'         => $e->paterno,
                    'materno'         => $e->materno,
                    'nombre_completo' => trim("{$e->nombre} {$e->paterno} {$e->materno}"),
                    'nombre_cliente' => $e->cliente ? $e->cliente->nombre : '',
                    'id_cliente'     => $e->id_cliente,
                ];
            });

        return response()->json([
            'clientes'  => $clientes,
            'empleados' => $empleados,
            'ids_debug' => $ids->all(),
        ]);
    }

    public function getEventosPorClientes(Request $request)
    {
                                           // --- Lee rango (end exclusivo por convención de calendar) ---
        $start = $request->input('start'); // "YYYY-MM-DD HH:MM:SS" o "YYYY-MM-DD"
        $end   = $request->input('end');

        // Normaliza a DateTime si vienen en formato solo-fecha
        if ($start && preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start .= ' 00:00:00';
        }

        if ($end && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end .= ' 00:00:00';
        }

        // Construye query base según filtros de empleado/cliente
        $query = CalendarioEvento::with('tipo')->where('eliminado', 0);

        if ($request->has('id_empleado')) {
            $ids = $request->input('id_empleado');
            if (is_string($ids)) {$ids = explode(',', $ids);}
            if (! is_array($ids)) {$ids = [$ids];}
            $ids = array_filter($ids);
            $query->whereIn('id_empleado', $ids ?: [-1]); // evita todo si vacío
        } else {
            $cli = $request->input('id_cliente');
            if (is_string($cli)) {$cli = explode(',', $cli);}
            if (! is_array($cli)) {$cli = [$cli];}
            $cli = array_filter($cli);

            if (! empty($cli)) {
                $empleadosIds = Empleado::whereIn('id_cliente', $cli)->pluck('id');
                if ($empleadosIds->isEmpty()) {
                    return response()->json(['eventos' => []]);
                }
                $query->whereIn('id_empleado', $empleadosIds);
            } else {
                // si no hay ni empleado ni cliente -> sin resultados
                return response()->json(['eventos' => []]);
            }
        }

        // --- Filtro por rango (usa tu índice idx_eliminado_rango e idx_emp_rango) ---
        if ($start && $end) {
            // Intersección: inicio < end_exclusivo  AND  fin > start_inclusivo
            $query->where('inicio', '<', $end)
                ->where('fin', '>', $start);
        }

        // Ordena para aprovechar índice compuesto
        $eventos = $query->orderBy('id_empleado')->orderBy('inicio')->limit(2000)->get();

        $result = $eventos->map(function ($evento) {
            $empleado       = $evento->empleado;
            $nombreCompleto = $empleado
                ? trim(($empleado->nombre ?? '') . ' ' . ($empleado->paterno ?? '') . ' ' . ($empleado->materno ?? ''))
                : '';

            return [
                'id'                   => $evento->id,
                'title'                => $evento->tipo->name ?? 'Evento',
                'tipo_evento'          => $evento->tipo->name ?? 'evento',
                'start'                => $evento->inicio, // FECHA INICIO (inclusiva)
                'end'                  => $evento->fin,    // FECHA FIN (inclusiva en BD)
                'backgroundColor'      => $evento->tipo->color ?? '#a78bfa',
                'descripcion'          => $evento->descripcion,
                'archivo'              => $evento->archivo,
                'id_empleado'          => $evento->id_empleado,
                'empleado'             => $nombreCompleto,
                'tipo_incapacidad_sat' => $evento->tipo_incapacidad_sat,

            ];
        });

        return response()->json(['eventos' => $result]);
    }

    public function getUltimoMesConEventos(Request $request)
    {
        $query = CalendarioEvento::where('eliminado', 0);

        if ($request->has('id_empleado')) {
            $ids = $request->input('id_empleado');

            if (is_string($ids)) {
                $ids = explode(',', $ids);
            }

            if (! is_array($ids)) {
                $ids = [$ids];
            }

            $ids = array_filter($ids);

            if (empty($ids)) {
                return response()->json([
                    'ok'   => true,
                    'date' => null,
                ]);
            }

            $query->whereIn('id_empleado', $ids);
        } else {
            $cli = $request->input('id_cliente');

            if (is_string($cli)) {
                $cli = explode(',', $cli);
            }

            if (! is_array($cli)) {
                $cli = [$cli];
            }

            $cli = array_filter($cli);

            if (empty($cli)) {
                return response()->json([
                    'ok'   => true,
                    'date' => null,
                ]);
            }

            $empleadosIds = Empleado::whereIn('id_cliente', $cli)->pluck('id');

            if ($empleadosIds->isEmpty()) {
                return response()->json([
                    'ok'   => true,
                    'date' => null,
                ]);
            }

            $query->whereIn('id_empleado', $empleadosIds);
        }

        $ultimoInicio = $query->max('inicio');

        if (! $ultimoInicio) {
            return response()->json([
                'ok'   => true,
                'date' => null,
            ]);
        }

        $date = \Carbon\Carbon::parse($ultimoInicio)->startOfMonth()->format('Y-m-d');

        return response()->json([
            'ok'   => true,
            'date' => $date,
        ]);
    }
/*
    public function setEventos(Request $request)
    {
        \Log::info('Payload completo recibido en setEventos: ' . json_encode($request->all()));

        $eventos    = $request->input('eventos');
        $id_portal  = $request->input('id_portal');
        $id_usuario = $request->input('id_usuario');
        $id_periodo = $request->input('periodo_nomina_id');

        if (! is_array($eventos)) {
            return response()->json(['error' => 'El campo eventos debe ser un array.'], 400);
        }

        $eventosGuardados  = [];
        $eventosDuplicados = [];
        $combosVistos      = [];

        foreach ($eventos as $i => $evento) {
            // 1. Buscar o crear el tipo de evento si es personalizado
            $tipoId = $evento['tipoId'] ?? null;
            if (! $tipoId && ! empty($evento['tipoNombre'])) {
                $nuevoTipo = \App\Models\EventosOption::firstOrCreate(
                    [
                        'name'      => $evento['tipoNombre'],
                        'id_portal' => $id_portal ?? null,
                    ],
                    [
                        'color'    => $evento['backgroundColor'] ?? '#a78bfa',
                        'creacion' => now(),
                    ]
                );
                $tipoId = $nuevoTipo->id;
            }

            $inicio = $evento['start'];
            $fin    = $evento['end'];

            // --- Duplicado dentro del MISMO payload ---
            $comboKey = ($evento['colaboradorId'] ?? 'null') . '|' .
                ($tipoId ?? 'null') . '|' .
                $inicio . '|' .
                $fin;

            if (in_array($comboKey, $combosVistos, true)) {
                $eventosDuplicados[] = [
                    'index'       => $i,
                    'id_empleado' => $evento['colaboradorId'] ?? null,
                    'id_tipo'     => $tipoId,
                    'inicio'      => $inicio,
                    'fin'         => $fin,
                    'motivo'      => 'Duplicado en el mismo payload',
                ];
                continue;
            }
            $combosVistos[] = $comboKey;

            // 2. Archivo
            $archivoNombre = null;
            $archivo       = $request->file("eventos.$i.archivo");
            if ($archivo && $archivo->isValid()) {
                $extension         = $archivo->getClientOriginalExtension();
                $archivoNombre     = "portal{$id_portal}_emp{$evento['colaboradorId']}_" . time() . "_" . uniqid() . "." . $extension;
                $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
                if (! file_exists($directorioDestino)) {
                    mkdir($directorioDestino, 0777, true);
                }
                $archivo->move($directorioDestino, $archivoNombre);
            }

            $fechaInicio = new \DateTime($inicio);
            $fechaFin    = new \DateTime($fin);
            $dias        = $fechaInicio->diff($fechaFin)->days + 1;

            // 3. Sanitizar tipo_incapacidad_sat
            $tipoIncapSat = $evento['tipo_incapacidad_sat'] ?? null;
            if ($tipoIncapSat !== null && ! in_array($tipoIncapSat, ['01', '02', '03', '04'], true)) {
                $tipoIncapSat = null;
            }

            // 4. Duplicado en BD
            $existe = CalendarioEvento::where('id_empleado', $evento['colaboradorId'])
                ->where('id_tipo', $tipoId)
                ->where('inicio', $inicio)
                ->where('fin', $fin)
                ->where('eliminado', 0)
                ->exists();

            if ($existe) {
                $eventosDuplicados[] = [
                    'index'       => $i,
                    'id_empleado' => $evento['colaboradorId'],
                    'id_tipo'     => $tipoId,
                    'inicio'      => $inicio,
                    'fin'         => $fin,
                    'motivo'      => 'Ya existe un evento igual en la base de datos',
                ];
                continue;
            }

            // 5. Crear evento
            $eventoGuardado = CalendarioEvento::create([
                'id_usuario'           => $id_usuario,
                'id_empleado'          => $evento['colaboradorId'],
                'id_tipo'              => $tipoId,
                'inicio'               => $inicio,
                'fin'                  => $fin,
                'dias_evento'          => $dias,
                'descripcion'          => $evento['descripcion'] ?? '',
                'archivo'              => $archivoNombre,
                'eliminado'            => 0,
                'tipo_incapacidad_sat' => $tipoIncapSat,
            ]);

            $eventosGuardados[] = $eventoGuardado;

            // Lógica prenómina futura...
        }

        // --- Resumen para el front ---
        $totalGuardados  = count($eventosGuardados);
        $totalDuplicados = count($eventosDuplicados);

        if ($totalGuardados === 0 && $totalDuplicados > 0) {
            // Solo duplicados, nada guardado
            return response()->json([
                'ok'                 => false,
                'message'            => 'No se guardó ningún evento porque ya existían con el mismo empleado, tipo y fechas.',
                'eventos'            => [],
                'eventos_duplicados' => $eventosDuplicados,
            ], 200);
        }

        if ($totalGuardados > 0 && $totalDuplicados > 0) {
            // Parcial: algunos guardados, otros duplicados
            return response()->json([
                'ok'      => true,
                'message' => "Se guardaron {$totalGuardados} evento(s). {$totalDuplicados} se omitieron por ser duplicados.",
                'eventos'            => $eventosGuardados,
                'eventos_duplicados' => $eventosDuplicados,
            ], 200);
        }

        // Caso normal: todos guardados, sin duplicados
        return response()->json([
            'ok'      => true,
            'message' => "Se guardaron {$totalGuardados} evento(s) correctamente.",
            'eventos'            => $eventosGuardados,
            'eventos_duplicados' => $eventosDuplicados,
        ], 200);
    }
*/
    public function actualizarEvento(Request $request, $id)
    {
        \Log::info('>>> [actualizarEvento] REQUEST', [
            'id'     => $id,
            'inputs' => $request->all(),
            'files'  => $request->allFiles(),
            'method' => $request->method(),
        ]);

        if ($request->isMethod('put') || $request->isMethod('patch')) {
            \Log::info('>>> [actualizarEvento] Método PUT/PATCH. Reagregando archivos...');
            $request->files->add($request->allFiles());
        }

        $evento = CalendarioEvento::find($id);
        \Log::info('>>> [actualizarEvento] Evento encontrado:', $evento ? $evento->toArray() : ['null']);

        if (! $evento) {
            \Log::warning('>>> [actualizarEvento] No se encontró el evento con ID: ' . $id);
            return response()->json(['error' => 'No se encontró el evento'], 404);
        }

        // (Opcional) validar tipo_incapacidad_sat
        $tipoIncapSat = $request->input('tipo_incapacidad_sat');
        if ($tipoIncapSat !== null && $tipoIncapSat !== '') {
            if (! in_array($tipoIncapSat, ['01', '02', '03', '04'], true)) {
                \Log::warning('>>> [actualizarEvento] tipo_incapacidad_sat inválido: ' . $tipoIncapSat);
                $tipoIncapSat = $evento->tipo_incapacidad_sat; // conservamos el anterior
            }
        }

        // 4. Asignar campos NUEVOS (lo que viene del front)
        $evento->id_usuario           = $request->input('id_usuario', $evento->id_usuario);
        $evento->id_empleado          = $request->input('id_empleado', $evento->id_empleado);
        $evento->id_tipo              = $request->input('id_tipo', $evento->id_tipo);
        $evento->inicio               = $request->input('inicio', $evento->inicio);
        $evento->fin                  = $request->input('fin', $evento->fin);
        $evento->descripcion          = $request->input('descripcion', $evento->descripcion);
        $evento->tipo_incapacidad_sat = $request->input('tipo_incapacidad_sat', $evento->tipo_incapacidad_sat);

        if ($tipoIncapSat !== null) {
            $evento->tipo_incapacidad_sat = $tipoIncapSat === '' ? null : $tipoIncapSat;
        }

        \Log::info('>>> [actualizarEvento] Datos para actualizar (antes de checar duplicado):', $evento->toArray());

        // 🔴 VERIFICAR DUPLICADO: mismo empleado + tipo + fechas, distinto id, no eliminado
        $existeDuplicado = CalendarioEvento::where('id_empleado', $evento->id_empleado)
            ->where('id_tipo', $evento->id_tipo)
            ->where('inicio', $evento->inicio)
            ->where('fin', $evento->fin)
            ->where('eliminado', 0)
            ->where('id', '!=', $evento->id)
            ->exists();

        if ($existeDuplicado) {
            \Log::warning('>>> [actualizarEvento] Intento de duplicar evento (empleado, tipo, fechas).', [
                'id_empleado' => $evento->id_empleado,
                'id_tipo'     => $evento->id_tipo,
                'inicio'      => $evento->inicio,
                'fin'         => $evento->fin,
            ]);

            return response()->json([
                'ok'    => false,
                'error' => 'Ya existe otro evento con el mismo empleado, tipo e intervalo de fechas.',
            ], 422);
        }

        // 5. Calcula días del evento
        try {
            $fechaInicio         = new \DateTime($evento->inicio);
            $fechaFin            = new \DateTime($evento->fin);
            $dias                = $fechaInicio->diff($fechaFin)->days + 1;
            $evento->dias_evento = $dias;
            \Log::info(">>> [actualizarEvento] Calculados días evento: $dias");
        } catch (\Exception $e) {
            $evento->dias_evento = 1;
            \Log::warning('>>> [actualizarEvento] Error calculando días evento: ' . $e->getMessage());
        }

        // 6. Manejo de archivo (tu código igual)
        \Log::info('>>> [actualizarEvento] hasFile(archivo): ' . ($request->hasFile('archivo') ? 'SI' : 'NO'));

        if ($request->hasFile('archivo')) {
            if ($evento->archivo) {
                $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
                $rutaArchivo       = $directorioDestino . '/' . $evento->archivo;
                if (file_exists($rutaArchivo)) {
                    unlink($rutaArchivo);
                    \Log::info(">>> [actualizarEvento] Archivo anterior borrado: $rutaArchivo");
                } else {
                    \Log::warning(">>> [actualizarEvento] Archivo anterior NO encontrado para borrar: $rutaArchivo");
                }
            }

            $archivo           = $request->file('archivo');
            $extension         = $archivo->getClientOriginalExtension();
            $archivoNombre     = "portal{$evento->id_usuario}_emp{$evento->id_empleado}_" . time() . "_" . uniqid() . "." . $extension;
            $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
            if (! file_exists($directorioDestino)) {
                mkdir($directorioDestino, 0777, true);
                \Log::info(">>> [actualizarEvento] Carpeta creada: $directorioDestino");
            }
            $archivo->move($directorioDestino, $archivoNombre);
            $evento->archivo = $archivoNombre;
            \Log::info(">>> [actualizarEvento] Archivo nuevo guardado: $archivoNombre");
        } else {
            \Log::info('>>> [actualizarEvento] No se envió archivo nuevo.');
        }

        $evento->save();
        \Log::info('>>> [actualizarEvento] Evento actualizado y guardado', $evento->toArray());

        return response()->json(['ok' => true, 'evento' => $evento]);
    }
 
    public function eliminarEvento(Request $request, $id)
    {
        $CONN               = 'portal_main';
        $ID_TIPO_VACACIONES = 1;

        $regresarVacaciones = (int) $request->input('regresar_vacaciones', 0) === 1;
        $idUsuario          = (int) $request->input('id_usuario', 0);

        DB::connection($CONN)->beginTransaction();

        try {
            /** @var \App\Models\CalendarioEvento $evento */
            $evento = \App\Models\CalendarioEvento::where('id', $id)
                ->where('eliminado', 0)
                ->firstOrFail();

            $esVacaciones     = ((int) $evento->id_tipo === $ID_TIPO_VACACIONES);
            $diasReintegrados = 0;

            // 1) Si es vacaciones y el usuario confirmó reintegrar saldo
            if ($esVacaciones && $regresarVacaciones) {
                $diasARestaurar = (float) ($evento->dias_evento ?? 0);

                if ($diasARestaurar > 0) {
                    $laboral = DB::connection($CONN)
                        ->table('laborales_empleado')
                        ->where('id_empleado', $evento->id_empleado)
                        ->lockForUpdate()
                        ->first();

                    if (! $laboral) {
                        throw new \RuntimeException('No se encontró el registro laboral del empleado.');
                    }

                    $saldoActual = (float) ($laboral->vacaciones_disponibles ?? 0);
                    $nuevoSaldo  = $saldoActual + $diasARestaurar;

                    DB::connection($CONN)
                        ->table('laborales_empleado')
                        ->where('id_empleado', $evento->id_empleado)
                        ->update([
                            'vacaciones_disponibles' => $nuevoSaldo,
                        ]);

                    $diasReintegrados = $diasARestaurar;
                }
            }

            // 2) Soft delete del evento
            $evento->eliminado = 1;

            if ($idUsuario > 0 && isset($evento->id_usuario)) {
                $evento->id_usuario = $idUsuario;
            }

            $evento->save();

            DB::connection($CONN)->commit();

        } catch (\Throwable $e) {
            DB::connection($CONN)->rollBack();

            Log::error('[Calendario] Error al eliminar evento', [
                'evento_id' => $id,
                'msg'       => $e->getMessage(),
                'line'      => $e->getLine(),
                'file'      => $e->getFile(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
            ], 500);
        }

        // 3) Compensación + re-evaluación de asistencia
        try {
            /** @var AsistenciaServicio $svc */
            $svc = app(AsistenciaServicio::class)->withConnection($CONN);
            $svc->handleCalendarEventDeletion((int) $evento->id);

        } catch (\Throwable $e) {
            Log::error('[Calendario] Error en compensación post-delete', [
                'evento_id' => $id,
                'msg'       => $e->getMessage(),
            ]);
            // No interrumpimos la respuesta; el evento ya se borró.
        }

        return response()->json([
            'ok'                      => true,
            'message'                 => 'Evento eliminado correctamente.',
            'evento_id'               => (int) $evento->id,
            'vacaciones_reintegradas' => $esVacaciones && $regresarVacaciones,
            'dias_reintegrados'       => $diasReintegrados,
        ], 200);
    }

    public function setEventos(Request $request)
    {
        \Log::info('Payload completo recibido en setEventos: ' . json_encode($request->all()));

        $eventos             = $request->input('eventos');
        $id_portal           = (int) $request->input('id_portal');
        $id_usuario          = (int) $request->input('id_usuario');
        $id_periodo          = $request->input('periodo_nomina_id');
        $descontarVacaciones = (int) $request->input('descontar_vacaciones', 0) === 1;

        $ID_TIPO_VACACIONES = 1;

        if (! is_array($eventos)) {
            return response()->json(['error' => 'El campo eventos debe ser un array.'], 400);
        }

        $eventosGuardados  = [];
        $eventosDuplicados = [];
        $combosVistos      = [];

        DB::connection('portal_main')->beginTransaction();

        try {
            foreach ($eventos as $i => $evento) {
                // 1. Buscar o crear el tipo de evento si es personalizado
                $tipoId = $evento['tipoId'] ?? null;

                if (! $tipoId && ! empty($evento['tipoNombre'])) {
                    $nuevoTipo = \App\Models\EventosOption::firstOrCreate(
                        [
                            'name'      => $evento['tipoNombre'],
                            'id_portal' => $id_portal ?: null,
                        ],
                        [
                            'color'    => $evento['backgroundColor'] ?? '#a78bfa',
                            'creacion' => now(),
                        ]
                    );
                    $tipoId = $nuevoTipo->id;
                }

                $idEmpleado = (int) ($evento['colaboradorId'] ?? 0);
                $inicio     = $evento['start'] ?? null;
                $fin        = $evento['end'] ?? null;

                if (! $idEmpleado || ! $tipoId || ! $inicio || ! $fin) {
                    $eventosDuplicados[] = [
                        'index'       => $i,
                        'id_empleado' => $idEmpleado ?: null,
                        'id_tipo'     => $tipoId ?: null,
                        'inicio'      => $inicio,
                        'fin'         => $fin,
                        'motivo'      => 'Faltan datos obligatorios del evento',
                    ];
                    continue;
                }

                // 2. Archivo
                $archivoNombre = null;
                $archivo       = $request->file("eventos.$i.archivo");

                if ($archivo && $archivo->isValid()) {
                    $extension         = $archivo->getClientOriginalExtension();
                    $archivoNombre     = "portal{$id_portal}_emp{$idEmpleado}_" . time() . "_" . uniqid() . "." . $extension;
                    $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';

                    if (! file_exists($directorioDestino)) {
                        mkdir($directorioDestino, 0777, true);
                    }

                    $archivo->move($directorioDestino, $archivoNombre);
                }

                // 3. Sanitizar tipo_incapacidad_sat
                $tipoIncapSat = $evento['tipo_incapacidad_sat'] ?? null;
                if ($tipoIncapSat !== null && ! in_array($tipoIncapSat, ['01', '02', '03', '04'], true)) {
                    $tipoIncapSat = null;
                }

                $esVacaciones = ((int) $tipoId === $ID_TIPO_VACACIONES);

                // =====================================================
                // VACACIONES: fragmentar y guardar bloques válidos
                // =====================================================
                if ($esVacaciones) {
                    $ctx = $this->obtenerContextoEmpleado($idEmpleado);

                    if (! $ctx) {
                        $eventosDuplicados[] = [
                            'index'       => $i,
                            'id_empleado' => $idEmpleado,
                            'id_tipo'     => $tipoId,
                            'inicio'      => $inicio,
                            'fin'         => $fin,
                            'motivo'      => 'No se encontró contexto del empleado',
                        ];
                        continue;
                    }

                    $diasDescanso = $this->obtenerDiasDescansoEfectivos(
                        $id_portal,
                        (int) $ctx->id_cliente,
                        $idEmpleado,
                        $ctx->dias_descanso
                    );

                    $festivosNoLaborados = $this->obtenerFestivosNoLaborados(
                        $id_portal,
                        (int) $ctx->id_cliente,
                        $idEmpleado,
                        $inicio,
                        $fin
                    );

                    $diasValidos = $this->expandirDiasLaborablesVacaciones(
                        $inicio,
                        $fin,
                        $diasDescanso,
                        $festivosNoLaborados
                    );

                    if (empty($diasValidos)) {
                        $eventosDuplicados[] = [
                            'index'       => $i,
                            'id_empleado' => $idEmpleado,
                            'id_tipo'     => $tipoId,
                            'inicio'      => $inicio,
                            'fin'         => $fin,
                            'motivo'      => 'No hay días laborables válidos para registrar vacaciones',
                        ];
                        continue;
                    }

                    $bloques          = $this->agruparFechasConsecutivas($diasValidos);
                    $diasDescontables = 0;

                    foreach ($bloques as $bloque) {
                        $bloqueInicio = $bloque['inicio'];
                        $bloqueFin    = $bloque['fin'];
                        $diasBloque   = $bloque['dias'];

                        // Duplicado dentro del payload
                        $comboKey = $idEmpleado . '|' . $tipoId . '|' . $bloqueInicio . '|' . $bloqueFin;
                        if (in_array($comboKey, $combosVistos, true)) {
                            $eventosDuplicados[] = [
                                'index'       => $i,
                                'id_empleado' => $idEmpleado,
                                'id_tipo'     => $tipoId,
                                'inicio'      => $bloqueInicio,
                                'fin'         => $bloqueFin,
                                'motivo'      => 'Duplicado en el mismo payload',
                            ];
                            continue;
                        }
                        $combosVistos[] = $comboKey;

                        // Duplicado en BD
                        $existe = CalendarioEvento::where('id_empleado', $idEmpleado)
                            ->where('id_tipo', $tipoId)
                            ->where('inicio', $bloqueInicio)
                            ->where('fin', $bloqueFin)
                            ->where('eliminado', 0)
                            ->exists();

                        if ($existe) {
                            $eventosDuplicados[] = [
                                'index'       => $i,
                                'id_empleado' => $idEmpleado,
                                'id_tipo'     => $tipoId,
                                'inicio'      => $bloqueInicio,
                                'fin'         => $bloqueFin,
                                'motivo'      => 'Ya existe un evento igual en la base de datos',
                            ];
                            continue;
                        }

                        $eventoGuardado = CalendarioEvento::create([
                            'id_usuario'           => $id_usuario,
                            'id_empleado'          => $idEmpleado,
                            'id_tipo'              => $tipoId,
                            'inicio'               => $bloqueInicio,
                            'fin'                  => $bloqueFin,
                            'dias_evento'          => $diasBloque,
                            'descripcion'          => $evento['descripcion'] ?? '',
                            'archivo'              => $archivoNombre,
                            'eliminado'            => 0,
                            'tipo_incapacidad_sat' => $tipoIncapSat,
                        ]);

                        $eventosGuardados[]  = $eventoGuardado;
                        $diasDescontables   += $diasBloque;
                    }

                    // Descontar saldo si el usuario confirmó
                    if ($descontarVacaciones && $diasDescontables > 0) {
                        $vacActual  = (float) ($ctx->vacaciones_disponibles ?? 0);
                        $nuevoSaldo = max(0, $vacActual - $diasDescontables);

                        DB::connection('portal_main')
                            ->table('laborales_empleado')
                            ->where('id_empleado', $idEmpleado)
                            ->update([
                                'vacaciones_disponibles' => $nuevoSaldo,
                            ]);
                    }

                    continue;
                }

                // =====================================================
                // EVENTOS NORMALES: guardar como hoy
                // =====================================================
                $comboKey = $idEmpleado . '|' . $tipoId . '|' . $inicio . '|' . $fin;

                if (in_array($comboKey, $combosVistos, true)) {
                    $eventosDuplicados[] = [
                        'index'       => $i,
                        'id_empleado' => $idEmpleado,
                        'id_tipo'     => $tipoId,
                        'inicio'      => $inicio,
                        'fin'         => $fin,
                        'motivo'      => 'Duplicado en el mismo payload',
                    ];
                    continue;
                }
                $combosVistos[] = $comboKey;

                $fechaInicio = new \DateTime($inicio);
                $fechaFin    = new \DateTime($fin);
                $dias        = $fechaInicio->diff($fechaFin)->days + 1;

                $existe = CalendarioEvento::where('id_empleado', $idEmpleado)
                    ->where('id_tipo', $tipoId)
                    ->where('inicio', $inicio)
                    ->where('fin', $fin)
                    ->where('eliminado', 0)
                    ->exists();

                if ($existe) {
                    $eventosDuplicados[] = [
                        'index'       => $i,
                        'id_empleado' => $idEmpleado,
                        'id_tipo'     => $tipoId,
                        'inicio'      => $inicio,
                        'fin'         => $fin,
                        'motivo'      => 'Ya existe un evento igual en la base de datos',
                    ];
                    continue;
                }

                $eventoGuardado = CalendarioEvento::create([
                    'id_usuario'           => $id_usuario,
                    'id_empleado'          => $idEmpleado,
                    'id_tipo'              => $tipoId,
                    'inicio'               => $inicio,
                    'fin'                  => $fin,
                    'dias_evento'          => $dias,
                    'descripcion'          => $evento['descripcion'] ?? '',
                    'archivo'              => $archivoNombre,
                    'eliminado'            => 0,
                    'tipo_incapacidad_sat' => $tipoIncapSat,
                ]);

                $eventosGuardados[] = $eventoGuardado;
            }

            DB::connection('portal_main')->commit();

        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            \Log::error('[setEventos] Error al guardar eventos', [
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
            ], 500);
        }

        $totalGuardados  = count($eventosGuardados);
        $totalDuplicados = count($eventosDuplicados);

        if ($totalGuardados === 0 && $totalDuplicados > 0) {
            return response()->json([
                'ok'                 => false,
                'message'            => 'No se guardó ningún evento porque ya existían con el mismo empleado, tipo y fechas, o no hubo días laborables válidos.',
                'eventos'            => [],
                'eventos_duplicados' => $eventosDuplicados,
            ], 200);
        }

        if ($totalGuardados > 0 && $totalDuplicados > 0) {
            return response()->json([
                'ok'      => true,
                'message' => "Se guardaron {$totalGuardados} evento(s). {$totalDuplicados} se omitieron por duplicidad o porque no había días laborables válidos.",
                'eventos'            => $eventosGuardados,
                'eventos_duplicados' => $eventosDuplicados,
            ], 200);
        }

        return response()->json([
            'ok'      => true,
            'message' => "Se guardaron {$totalGuardados} evento(s) correctamente.",
            'eventos'            => $eventosGuardados,
            'eventos_duplicados' => $eventosDuplicados,
        ], 200);
    }
    protected function obtenerContextoEmpleado(int $idEmpleado)
    {
        return DB::connection('portal_main')
            ->table('empleados as e')
            ->join('laborales_empleado as l', 'l.id_empleado', '=', 'e.id')
            ->where('e.id', $idEmpleado)
            ->select(
                'e.id',
                'e.id_cliente',
                'l.dias_descanso',
                'l.vacaciones_disponibles'
            )
            ->first();
    }
    protected function obtenerDiasDescansoEfectivos(int $idPortal, int $idCliente, int $idEmpleado, $diasDescansoLaboralRaw = null): array
    {
        // 1) Obtener descansos base desde laborales_empleado
        $laborales = [];

        if (is_array($diasDescansoLaboralRaw)) {
            $laborales = $diasDescansoLaboralRaw;
        } elseif (is_string($diasDescansoLaboralRaw) && trim($diasDescansoLaboralRaw) !== '') {
            $decoded = json_decode($diasDescansoLaboralRaw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $laborales = $decoded;
            }
        }

        $dias = $this->normalizarDiasDescanso($laborales);

        // 2) Aplicar política SOLO para sábado y domingo
        $politica = $this->resolverPoliticaAsistenciaAplicable($idPortal, $idCliente, $idEmpleado);

        if ($politica) {
            // Sábado
            if ((bool) $politica->trabaja_sabado) {
                $dias = array_values(array_diff($dias, ['sabado']));
            } else {
                if (! in_array('sabado', $dias, true)) {
                    $dias[] = 'sabado';
                }
            }

            // Domingo
            if ((bool) $politica->trabaja_domingo) {
                $dias = array_values(array_diff($dias, ['domingo']));
            } else {
                if (! in_array('domingo', $dias, true)) {
                    $dias[] = 'domingo';
                }
            }
        }

        return array_values(array_unique($dias));
    }
    protected function normalizarDiasDescanso(array $dias): array
    {
        $mapa = [
            'lunes'     => 'lunes',
            'martes'    => 'martes',
            'miercoles' => 'miercoles',
            'miércoles' => 'miercoles',
            'jueves'    => 'jueves',
            'viernes'   => 'viernes',
            'sabado'    => 'sabado',
            'sábado'    => 'sabado',
            'domingo'   => 'domingo',
        ];

        $salida = [];

        foreach ($dias as $dia) {
            if (! is_string($dia)) {
                continue;
            }

            $dia = trim(mb_strtolower($dia, 'UTF-8'));

            if (isset($mapa[$dia])) {
                $salida[] = $mapa[$dia];
            }
        }

        return array_values(array_unique($salida));
    }
    protected function resolverPoliticaAsistenciaAplicable(int $idPortal, int $idCliente, int $idEmpleado)
    {
        $cn = DB::connection('portal_main');

        // 1. EMPLEADO
        $politica = $cn->table('politica_asistencia as pa')
            ->join('politica_asistencia_empleado as pae', 'pae.id_politica_asistencia', '=', 'pa.id')
            ->where('pa.id_portal', $idPortal)
            ->where('pa.estado', 'publicada')
            ->where('pa.scope', 'EMPLEADO')
            ->where('pae.id_empleado', (string) $idEmpleado)
            ->orderByDesc('pa.actualizado_en')
            ->orderByDesc('pa.id')
            ->select('pa.*')
            ->first();

        if ($politica) {
            return $politica;
        }

        // 2. SUCURSAL
        $politica = $cn->table('politica_asistencia as pa')
            ->join('politica_asistencia_cliente as pac', 'pac.id_politica_asistencia', '=', 'pa.id')
            ->where('pa.id_portal', $idPortal)
            ->where('pa.estado', 'publicada')
            ->where('pa.scope', 'SUCURSAL')
            ->where('pac.id_cliente', $idCliente)
            ->orderByDesc('pa.actualizado_en')
            ->orderByDesc('pa.id')
            ->select('pa.*')
            ->first();

        if ($politica) {
            return $politica;
        }

        // 3. PORTAL
        $politica = $cn->table('politica_asistencia as pa')
            ->where('pa.id_portal', $idPortal)
            ->where('pa.estado', 'publicada')
            ->where('pa.scope', 'PORTAL')
            ->orderByDesc('pa.actualizado_en')
            ->orderByDesc('pa.id')
            ->select('pa.*')
            ->first();

        return $politica ?: null;
    }

    protected function obtenerFestivosNoLaborados(int $idPortal, int $idCliente, int $idEmpleado, string $inicio, string $fin): array
    {
        $politica = $this->resolverPoliticaAsistenciaAplicable($idPortal, $idCliente, $idEmpleado);

        if (! $politica) {
            return [];
        }

        return DB::connection('portal_main')
            ->table('politica_festivos')
            ->where('id_politica_asistencia', $politica->id)
            ->where('es_laborado', 0)
            ->whereBetween('fecha', [$inicio, $fin])
            ->pluck('fecha')
            ->map(fn($f) => is_string($f) ? substr($f, 0, 10) : (string) $f)
            ->values()
            ->all();
    }

    protected function expandirDiasLaborablesVacaciones(string $inicio, string $fin, array $diasDescanso = [], array $festivosNoLaborados = []): array
    {
        $mapaDias = [
            1 => 'lunes',
            2 => 'martes',
            3 => 'miercoles',
            4 => 'jueves',
            5 => 'viernes',
            6 => 'sabado',
            7 => 'domingo',
        ];

        $diasDescanso = $this->normalizarDiasDescanso($diasDescanso);

        $festivosNoLaborados = array_values(array_unique(array_filter(array_map(function ($f) {
            return is_string($f) ? substr(trim($f), 0, 10) : null;
        }, $festivosNoLaborados))));

        $fechaInicio = new \DateTime(substr($inicio, 0, 10));
        $fechaFin    = new \DateTime(substr($fin, 0, 10));

        $diasValidos = [];

        while ($fechaInicio <= $fechaFin) {
            $fechaYmd  = $fechaInicio->format('Y-m-d');
            $numeroDia = (int) $fechaInicio->format('N');
            $nombreDia = $mapaDias[$numeroDia] ?? null;

            $esDescanso          = $nombreDia && in_array($nombreDia, $diasDescanso, true);
            $esFestivoNoLaborado = in_array($fechaYmd, $festivosNoLaborados, true);

            if (! $esDescanso && ! $esFestivoNoLaborado) {
                $diasValidos[] = $fechaYmd;
            }

            $fechaInicio->modify('+1 day');
        }

        return $diasValidos;
    }

    protected function agruparFechasConsecutivas(array $fechas): array
    {
        if (empty($fechas)) {
            return [];
        }

        sort($fechas);

        $bloques      = [];
        $inicioBloque = $fechas[0];
        $finBloque    = $fechas[0];
        $diasBloque   = 1;

        for ($i = 1; $i < count($fechas); $i++) {
            $prev = new \DateTime($finBloque);
            $prev->modify('+1 day');

            if ($prev->format('Y-m-d') === $fechas[$i]) {
                $finBloque = $fechas[$i];
                $diasBloque++;
            } else {
                $bloques[] = [
                    'inicio' => $inicioBloque,
                    'fin'    => $finBloque,
                    'dias'   => $diasBloque,
                ];

                $inicioBloque = $fechas[$i];
                $finBloque    = $fechas[$i];
                $diasBloque   = 1;
            }
        }

        $bloques[] = [
            'inicio' => $inicioBloque,
            'fin'    => $finBloque,
            'dias'   => $diasBloque,
        ];

        return $bloques;
    }
    public function getTiposEvento(Request $request)
    {
        $query = EventosOption::query();

        if ($request->filled('id_portal')) {
            $id_portal = $request->input('id_portal');
            $query->where(function ($q) use ($id_portal) {
                $q->where('id_portal', $id_portal)
                    ->orWhereNull('id_portal');
            });
        } else {
            $query->whereNull('id_portal');
        }

        $tipos = $query->select('id', 'name', 'color')->distinct()->get();

        return response()->json($tipos);
    }
    private function calendarioBasePath(): string
    {
        // Detecta ambiente y toma la variable correcta
        $base = app()->environment('production')
            ? config('paths.prod_images', '')
            : config('paths.local_images', '');

        // Normaliza separadores y quita slashes finales
        return rtrim(str_replace(['\\', '//'], ['/', '/'], $base), '/');
    }

    private function joinPaths(string ...$parts): string
    {
        $clean = array_map(fn($p) => trim($p, "/ \t\n\r\0\x0B"), $parts);
        return implode('/', $clean);
    }

    public function streamArchivoCalendario($id)
    {
        $evento = \App\Models\CalendarioEvento::find($id);
        if (! $evento) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }

        if (empty($evento->archivo)) {
            return response()->json(['message' => 'Este evento no tiene archivo'], 404);
        }

        // Base por ambiente (.env)
        $base = app()->environment('production')
            ? config('paths.prod_images', '')
            : config('paths.local_images', '');
        // Normaliza separadores y quita slashes finales
        $base = rtrim(str_replace(['\\', '//'], ['/', '/'], $base), '/');

        // Ruta absoluta del archivo
        $absPath = $base . '/_archivo_calendario/' . $evento->archivo;

        // Seguridad: confirmar que existe y que está dentro de $base
        $realBase = realpath($base);
        $realFile = $absPath ? realpath($absPath) : false;

        if (! $realBase || ! $realFile || strpos($realFile, $realBase) !== 0 || ! is_file($realFile)) {
            return response()->json(['message' => 'Archivo no encontrado en servidor'], 404);
        }

        // Detectar MIME
        $mime = (function ($path) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = $f ? finfo_file($f, $path) : null;
            if ($f) {
                finfo_close($f);
            }

            return $m ?: 'application/octet-stream';
        })($realFile);

        $filename = $evento->archivo;

        $headers = [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="' . addslashes($filename) . '"',
            'X-Accel-Buffering'   => 'no',
            'Cache-Control'       => 'private, max-age=0, no-cache, no-store, must-revalidate',
            'Pragma'              => 'no-cache',
            'Expires'             => '0',
        ];

        @set_time_limit(0);

        return Response::stream(function () use ($realFile) {
            $h = fopen($realFile, 'rb');
            if ($h === false) {
                return;
            }

            while (! feof($h)) {
                echo fread($h, 8192);
                @ob_flush(); flush();
            }
            fclose($h);
        }, 200, $headers);
    }

    public function downloadArchivoCalendario($id)
    {
        $evento = \App\Models\CalendarioEvento::find($id);
        if (! $evento) {
            return response()->json(['message' => 'Evento no encontrado'], 404);
        }
        if (empty($evento->archivo)) {
            return response()->json(['message' => 'Este evento no tiene archivo'], 404);
        }

        // Base por ambiente (.env)
        $base = app()->environment('production')
            ? config('paths.prod_images', '')
            : config('paths.local_images', '');
        $base = rtrim(str_replace(['\\', '//'], ['/', '/'], $base), '/');

        // Ruta del archivo
        $absPath  = $base . '/_archivo_calendario/' . $evento->archivo;
        $realBase = realpath($base);
        $realFile = $absPath ? realpath($absPath) : false;

        if (! $realBase || ! $realFile || strpos($realFile, $realBase) !== 0 || ! is_file($realFile)) {
            return response()->json(['message' => 'Archivo no encontrado en servidor'], 404);
        }

        // ====== MIME correcto (mejor compatibilidad) ======
        $mime = null;
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = finfo_file($f, $realFile) ?: null;
                finfo_close($f);
            }
        }
        // Fallback por extensión si finfo no disponible
        if (! $mime) {
            $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
            $map = [
                'pdf'  => 'application/pdf',
                'png'  => 'image/png',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt'  => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'txt'  => 'text/plain',
                'csv'  => 'text/csv',
                'zip'  => 'application/zip',
            ];
            $mime = $map[$ext] ?? 'application/octet-stream';
        }

        $filename = $evento->archivo;

        // Evita corrupción por buffers/compresión
        if (function_exists('ini_get') && function_exists('ini_set')) {
            if (ini_get('zlib.output_compression')) {
                @ini_set('zlib.output_compression', 'Off');
            }
        }
        while (ob_get_level() > 0) {@ob_end_clean();}

        $headers = [
            'Content-Type'              => $mime, // 👈 MIME real
                                                  // Compatibilidad con nombres UTF-8
            'Content-Disposition'       => 'attachment; filename="' . addslashes($filename) . '"' .
            "; filename*=UTF-8''" . rawurlencode($filename),
            'Content-Transfer-Encoding' => 'binary',
            'X-Accel-Buffering'         => 'no',
            'Cache-Control'             => 'private, max-age=0, no-cache, no-store, must-revalidate',
            'Pragma'                    => 'no-cache',
            'Expires'                   => '0',
            'Content-Length'            => (string) filesize($realFile),
            'X-Content-Type-Options'    => 'nosniff',
        ];

        @set_time_limit(0);

        return Response::stream(function () use ($realFile) {
            $h = fopen($realFile, 'rb');
            if ($h === false) {
                return;
            }

            while (! feof($h)) {
                echo fread($h, 8192);
                @ob_flush(); flush();
            }
            fclose($h);
        }, 200, $headers);
    }

}
