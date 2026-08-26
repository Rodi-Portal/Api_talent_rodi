<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CalendarioEvento;
use App\Models\ClienteTalent;
use App\Models\Empleado;
use App\Models\EventosOption;
use App\Services\Asistencia\AsistenciaServicio;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\EmployeeDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CalendarioController extends Controller
{
    private const DOCUMENT_CATEGORY = '_incidencias';
    private const VACATION_TYPE_ID  = 1;
    public function __construct(
        private AdminClientScopeService $clientScope,
        private EmployeeDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}
    //
    public function colaboradoresPorSucursal(Request $request)
    {
        $administrator = $this->administrator($request);
        $raw           = $request->query('id_cliente', []);

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
        $authorizedClientIds =
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            $ids->all()
        );
        $clientes = ClienteTalent::whereIn(
            'id',
            $authorizedClientIds
        )
            ->select('id', 'nombre')
            ->get();

        $empleados = Empleado::with('cliente')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $authorizedClientIds)
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
        ]);
    }

    public function getEventosPorClientes(Request $request)
    {
        $administrator = $this->administrator($request);
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

        $employeeIds = $this->authorizedEmployeeIds(
            $request,
            $administrator
        );

        if ($employeeIds === []) {
            return response()->json(['eventos' => []]);
        }

        $query = CalendarioEvento::with(['tipo', 'empleado'])
            ->where('eliminado', 0)
            ->whereIn('id_empleado', $employeeIds);

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
        $administrator = $this->administrator($request);

        $employeeIds = $this->authorizedEmployeeIds(
            $request,
            $administrator
        );

        if ($employeeIds === []) {
            return response()->json([
                'ok'   => true,
                'date' => null,
            ]);
        }

        $query = CalendarioEvento::query()
            ->where('eliminado', 0)
            ->whereIn('id_empleado', $employeeIds);

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

    public function actualizarEvento(Request $request, $id)
    {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'id_empleado'          => ['nullable', 'integer', 'min:1'],
            'id_tipo'              => ['nullable', 'integer', 'min:1'],
            'inicio'               => ['nullable', 'date'],
            'fin'                  => ['nullable', 'date'],
            'descripcion'          => ['nullable', 'string', 'max:5000'],
            'tipo_incapacidad_sat' => [
                'nullable',
                'string',
                'in:01,02,03,04',
            ],
            'archivo'              => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt',
                'max:10240',
            ],
        ]);

        $evento = CalendarioEvento::query()
            ->where('id', (int) $id)
            ->where('eliminado', 0)
            ->firstOrFail();

        $originalEmployee = $this->authorizeEvent(
            $administrator,
            $evento
        );

        $targetEmployeeId = (int) (
            $data['id_empleado'] ?? $evento->id_empleado
        );

        $targetEmployee = $this->authorizeEmployee(
            $administrator,
            $targetEmployeeId
        );

        $targetTypeId = (int) (
            $data['id_tipo'] ?? $evento->id_tipo
        );

        $typeAuthorized = EventosOption::query()
            ->where('id', $targetTypeId)
            ->where(function ($query) use ($administrator) {
                $query
                    ->whereNull('id_portal')
                    ->orWhere(
                        'id_portal',
                        (int) $administrator->id_portal
                    );
            })
            ->exists();

        if (! $typeAuthorized) {
            throw ValidationException::withMessages([
                'id_tipo' =>
                'El tipo de evento no pertenece al portal autenticado.',
            ]);
        }

        $previousData = [
            'id_usuario'           => $evento->id_usuario,
            'id_empleado'          => $evento->id_empleado,
            'id_portal'            => $evento->id_portal,
            'id_cliente'           => $evento->id_cliente,
            'id_tipo'              => $evento->id_tipo,
            'inicio'               => $evento->inicio,
            'fin'                  => $evento->fin,
            'dias_evento'          => $evento->dias_evento,
            'descripcion'          => $evento->descripcion,
            'tipo_incapacidad_sat' =>
            $evento->tipo_incapacidad_sat,
            'archivo'              => $evento->archivo,
        ];

        $evento->id_usuario  = (int) $administrator->id;
        $evento->id_portal   = (int) $administrator->id_portal;
        $evento->id_cliente  = (int) $targetEmployee->id_cliente;
        $evento->id_empleado = $targetEmployeeId;
        $evento->id_tipo     = $targetTypeId;

        if (array_key_exists('inicio', $data)) {
            $evento->inicio = $data['inicio'];
        }

        if (array_key_exists('fin', $data)) {
            $evento->fin = $data['fin'];
        }

        if (array_key_exists('descripcion', $data)) {
            $evento->descripcion = $data['descripcion'];
        }

        if (array_key_exists('tipo_incapacidad_sat', $data)) {
            $evento->tipo_incapacidad_sat =
                $data['tipo_incapacidad_sat'];
        }

        if (
            new \DateTime($evento->fin)
            < new \DateTime($evento->inicio)
        ) {
            throw ValidationException::withMessages([
                'fin' =>
                'La fecha final debe ser posterior o igual a la inicial.',
            ]);
        }

        $duplicateExists = CalendarioEvento::query()
            ->where('id_empleado', $evento->id_empleado)
            ->where('id_tipo', $evento->id_tipo)
            ->where('inicio', $evento->inicio)
            ->where('fin', $evento->fin)
            ->where('eliminado', 0)
            ->where('id', '<>', $evento->id)
            ->exists();

        if ($duplicateExists) {
            throw ValidationException::withMessages([
                'evento' =>
                'Ya existe otro evento igual para el empleado.',
            ]);
        }

        $evento->dias_evento =
        (new \DateTime($evento->inicio))
            ->diff(new \DateTime($evento->fin))
            ->days + 1;

        $oldStoredPath     = (string) ($evento->archivo ?? '');
        $newFileMetadata   = null;
        $updatedFileEvents = 0;

        DB::connection('portal_main')->beginTransaction();

        try {
            if ($request->hasFile('archivo')) {
                $newFileMetadata = $this->storeEventFile(
                    $request->file('archivo'),
                    $targetEmployee
                );

                $evento->archivo =
                    $newFileMetadata['storage_path'];

                $updatedFileEvents = 1;

                if ($oldStoredPath !== '') {
                    $updatedFileEvents += CalendarioEvento::query()
                        ->where(
                            'id_empleado',
                            (int) $previousData['id_empleado']
                        )
                        ->where('archivo', $oldStoredPath)
                        ->where('eliminado', 0)
                        ->where('id', '<>', (int) $evento->id)
                        ->update([
                            'archivo'    =>
                            $newFileMetadata['storage_path'],
                            'id_usuario' =>
                            (int) $administrator->id,
                        ]);
                }
            }

            $evento->save();

            DB::connection('portal_main')->commit();
        } catch (\Throwable $exception) {
            DB::connection('portal_main')->rollBack();

            if (
                $newFileMetadata
                && is_file($newFileMetadata['absolute_path'])
            ) {
                @unlink($newFileMetadata['absolute_path']);
            }

            $this->auditoria->registrar([
                'id_portal'        => (int) $administrator->id_portal,
                'id_cliente'       => (int) $targetEmployee->id_cliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     =>
                $this->administratorName($administrator),
                'modulo'           => 'comunicacion_interna',
                'entidad_tipo'     => 'calendario_evento',
                'entidad_id'       => (int) $evento->id,
                'accion'           => $request->hasFile('archivo')
                    ? 'archivo_evento_reemplazado'
                    : 'evento_actualizado',
                'resultado'        => 'fallido',
                'descripcion'      => $exception->getMessage(),
                'datos_anteriores' => $previousData,
            ], $request);

            throw $exception;
        }

        $trashPath = null;

        if (
            $newFileMetadata
            && $oldStoredPath !== ''
        ) {
            try {
                $trashPath = $this->documentPaths->moveToTrash(
                    self::DOCUMENT_CATEGORY,
                    $originalEmployee,
                    (int) $evento->id,
                    $oldStoredPath,
                    'reemplazados'
                );
            } catch (\Throwable $exception) {
                Log::warning(
                    'No se pudo mover la evidencia anterior del evento.',
                    [
                        'evento_id' => (int) $evento->id,
                        'message'   => $exception->getMessage(),
                    ]
                );
            }
        }

        $newData = [
            'id_usuario'           => $evento->id_usuario,
            'id_empleado'          => $evento->id_empleado,
            'id_portal'            => $evento->id_portal,
            'id_cliente'           => $evento->id_cliente,
            'id_tipo'              => $evento->id_tipo,
            'inicio'               => $evento->inicio,
            'fin'                  => $evento->fin,
            'dias_evento'          => $evento->dias_evento,
            'descripcion'          => $evento->descripcion,
            'tipo_incapacidad_sat' =>
            $evento->tipo_incapacidad_sat,
            'archivo'              => $evento->archivo,
        ];

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $targetEmployee->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion_interna',
            'entidad_tipo'     => 'calendario_evento',
            'entidad_id'       => (int) $evento->id,
            'accion'           => $newFileMetadata
                ? 'archivo_evento_reemplazado'
                : 'evento_actualizado',
            'resultado'        => 'exitoso',
            'descripcion'      => $newFileMetadata
                ? 'Se reemplazó la evidencia de un evento.'
                : 'Se actualizaron los datos de un evento.',
            'datos_anteriores' => $previousData,
            'datos_nuevos'     => $newData,
            'metadatos'        => $newFileMetadata
                ? [
                'archivo_anterior'     => $oldStoredPath,
                'respaldo_anterior'    => $trashPath,
                'eventos_actualizados' => $updatedFileEvents,
                'storage_path'         =>
                $newFileMetadata['storage_path'],
                'nombre_original'      =>
                $newFileMetadata['original_name'],
                'nombre_fisico'        =>
                $newFileMetadata['physical_name'],
                'mime_type'            =>
                $newFileMetadata['mime_type'],
                'size_bytes'           =>
                $newFileMetadata['size_bytes'],
            ]
                : null,
        ], $request);

        return response()->json([
            'ok'     => true,
            'evento' => $evento->fresh([
                'tipo:id,name,color',
                'empleado:id,id_empleado,nombre,paterno,materno,id_cliente',
            ]),
        ]);
    }

    public function eliminarEvento(Request $request, $id)
    {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'regresar_vacaciones' => ['nullable', 'boolean'],
        ]);

        $connection     = 'portal_main';
        $vacationTypeId = self::VACATION_TYPE_ID;

        $returnVacations = (int) (
            $data['regresar_vacaciones'] ?? 0
        ) === 1;

        $evento       = null;
        $employee     = null;
        $previousData = null;
        $daysReturned = 0;
        $isVacation   = false;

        DB::connection($connection)->beginTransaction();

        try {
            $evento = CalendarioEvento::query()
                ->where('id', (int) $id)
                ->where('eliminado', 0)
                ->lockForUpdate()
                ->firstOrFail();

            $employee = $this->authorizeEvent(
                $administrator,
                $evento
            );

            $previousData = [
                'id_usuario'  => $evento->id_usuario,
                'id_empleado' => $evento->id_empleado,
                'id_portal'   => $evento->id_portal,
                'id_cliente'  => $evento->id_cliente,
                'id_tipo'     => $evento->id_tipo,
                'inicio'      => $evento->inicio,
                'fin'         => $evento->fin,
                'dias_evento' => $evento->dias_evento,
                'descripcion' => $evento->descripcion,
                'archivo'     => $evento->archivo,
                'eliminado'   => $evento->eliminado,
            ];

            $isVacation =
            (int) $evento->id_tipo === $vacationTypeId;

            if ($isVacation && $returnVacations) {
                $daysToReturn = (float) (
                    $evento->dias_evento ?? 0
                );

                if ($daysToReturn > 0) {
                    $laboral = DB::connection($connection)
                        ->table('laborales_empleado')
                        ->where(
                            'id_empleado',
                            (int) $evento->id_empleado
                        )
                        ->lockForUpdate()
                        ->first();

                    if (! $laboral) {
                        throw new RuntimeException(
                            'No se encontró el registro laboral del empleado.'
                        );
                    }

                    $newBalance = (float) (
                        $laboral->vacaciones_disponibles ?? 0
                    ) + $daysToReturn;

                    DB::connection($connection)
                        ->table('laborales_empleado')
                        ->where(
                            'id_empleado',
                            (int) $evento->id_empleado
                        )
                        ->update([
                            'vacaciones_disponibles' =>
                            $newBalance,
                        ]);

                    $daysReturned = $daysToReturn;
                }
            }

            $evento->id_usuario = (int) $administrator->id;
            $evento->eliminado  = 1;
            $evento->save();

            DB::connection($connection)->commit();
        } catch (\Throwable $exception) {
            DB::connection($connection)->rollBack();

            $this->auditoria->registrar([
                'id_portal'        => (int) $administrator->id_portal,
                'id_cliente'       => $employee
                    ? (int) $employee->id_cliente
                    : null,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     =>
                $this->administratorName($administrator),
                'modulo'           => 'comunicacion_interna',
                'entidad_tipo'     => 'calendario_evento',
                'entidad_id'       => is_numeric($id)
                    ? (int) $id
                    : null,
                'accion'           => 'evento_eliminado',
                'resultado'        => 'fallido',
                'descripcion'      => $exception->getMessage(),
                'datos_anteriores' => $previousData,
            ], $request);

            throw $exception;
        }

        try {
            app(AsistenciaServicio::class)
                ->withConnection($connection)
                ->handleCalendarEventDeletion(
                    (int) $evento->id
                );
        } catch (\Throwable $exception) {
            Log::error(
                '[Calendario] Error en compensación post-delete',
                [
                    'evento_id' => (int) $evento->id,
                    'message'   => $exception->getMessage(),
                ]
            );
        }

        $this->auditoria->registrar([
            'id_portal'        => (int) $administrator->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     =>
            $this->administratorName($administrator),
            'modulo'           => 'comunicacion_interna',
            'entidad_tipo'     => 'calendario_evento',
            'entidad_id'       => (int) $evento->id,
            'accion'           => 'evento_eliminado',
            'resultado'        => 'exitoso',
            'descripcion'      =>
            'Se eliminó lógicamente un evento.',
            'datos_anteriores' => $previousData,
            'datos_nuevos'     => [
                'eliminado'  => 1,
                'id_usuario' => (int) $administrator->id,
            ],
            'metadatos'        => [
                'archivo_conservado'      =>
                ! empty($evento->archivo),
                'storage_path'            => $evento->archivo,
                'vacaciones_reintegradas' =>
                $isVacation && $returnVacations,
                'dias_reintegrados'       => $daysReturned,
            ],
        ], $request);

        return response()->json([
            'ok'                      => true,
            'message'                 => 'Evento eliminado correctamente.',
            'evento_id'               => (int) $evento->id,
            'vacaciones_reintegradas' =>
            $isVacation && $returnVacations,
            'dias_reintegrados'       => $daysReturned,
        ]);
    }

    public function setEventos(Request $request)
    {
        $administrator = $this->administrator($request);

        $data = $request->validate([
            'eventos'                        => ['required', 'array', 'min:1'],
            'eventos.*.colaboradorId'        => ['required', 'integer', 'min:1'],
            'eventos.*.tipoId'               => ['nullable', 'integer', 'min:1'],
            'eventos.*.tipoNombre'           => ['nullable', 'string', 'max:150'],
            'eventos.*.backgroundColor'      => ['nullable', 'string', 'max:30'],
            'eventos.*.start'                => ['required', 'date'],
            'eventos.*.end'                  => ['required', 'date'],
            'eventos.*.descripcion'          => ['nullable', 'string', 'max:5000'],
            'eventos.*.tipo_incapacidad_sat' => [
                'nullable',
                'string',
                'in:01,02,03,04',
            ],
            'eventos.*.archivo'              => [
                'nullable',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv,txt',
                'max:10240',
            ],
            'periodo_nomina_id'              => ['nullable', 'integer', 'min:1'],
            'descontar_vacaciones'           => ['nullable', 'boolean'],
        ]);

        $eventos = $data['eventos'];

        $id_portal  = (int) $administrator->id_portal;
        $id_usuario = (int) $administrator->id;

        $id_periodo = $data['periodo_nomina_id'] ?? null;

        $descontarVacaciones = (int) (
            $data['descontar_vacaciones'] ?? 0
        ) === 1;

        $eventosGuardados  = [];
        $eventosDuplicados = [];
        $combosVistos      = [];
        $writtenFiles      = [];
        $eventFileMetadata = [];
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
                $employee = $this->authorizeEmployee(
                    $administrator,
                    $idEmpleado
                );

                $tipoAutorizado = EventosOption::query()
                    ->where('id', (int) $tipoId)
                    ->where(function ($query) use ($id_portal) {
                        $query
                            ->whereNull('id_portal')
                            ->orWhere('id_portal', $id_portal);
                    })
                    ->exists();

                if (! $tipoAutorizado) {
                    throw ValidationException::withMessages([
                        "eventos.{$i}.tipoId" =>
                        'El tipo de evento no pertenece al portal autenticado.',
                    ]);
                }
                // 2. Archivo validado; se escribirá solo si el evento
                // realmente supera las comprobaciones de duplicidad.
                $archivoNombre   = null;
                $archivoMetadata = null;
                $archivo         = $request->file("eventos.$i.archivo");

                // 3. Sanitizar tipo_incapacidad_sat
                $tipoIncapSat = $evento['tipo_incapacidad_sat'] ?? null;
                if ($tipoIncapSat !== null && ! in_array($tipoIncapSat, ['01', '02', '03', '04'], true)) {
                    $tipoIncapSat = null;
                }

                $esVacaciones =
                (int) $tipoId === self::VACATION_TYPE_ID;

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
                        if (
                            $archivo
                            && $archivo->isValid()
                            && $archivoNombre === null
                        ) {
                            $archivoMetadata = $this->storeEventFile(
                                $archivo,
                                $employee
                            );

                            $archivoNombre =
                                $archivoMetadata['storage_path'];

                            $writtenFiles[] =
                                $archivoMetadata['absolute_path'];
                        }
                        $eventoGuardado = CalendarioEvento::create([
                            'id_usuario'           => $id_usuario,
                            'id_empleado'          => $idEmpleado,
                            'id_portal'            => $id_portal,
                            'id_cliente'           => (int) $employee->id_cliente,
                            'id_tipo'              => $tipoId,
                            'inicio'               => $bloqueInicio,
                            'fin'                  => $bloqueFin,
                            'dias_evento'          => $diasBloque,
                            'descripcion'          => $evento['descripcion'] ?? '',
                            'archivo'              => $archivoNombre,
                            'eliminado'            => 0,
                            'tipo_incapacidad_sat' => $tipoIncapSat,
                        ]);

                        $eventosGuardados[]                           = $eventoGuardado;
                        $eventFileMetadata[(int) $eventoGuardado->id] =
                            $archivoMetadata;
                        $diasDescontables += $diasBloque;
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
                if (
                    $archivo
                    && $archivo->isValid()
                    && $archivoNombre === null
                ) {
                    $archivoMetadata = $this->storeEventFile(
                        $archivo,
                        $employee
                    );

                    $archivoNombre =
                        $archivoMetadata['storage_path'];

                    $writtenFiles[] =
                        $archivoMetadata['absolute_path'];
                }

                $eventoGuardado = CalendarioEvento::create([
                    'id_usuario'           => $id_usuario,
                    'id_empleado'          => $idEmpleado,
                    'id_portal'            => $id_portal,
                    'id_cliente'           => (int) $employee->id_cliente,
                    'id_tipo'              => $tipoId,
                    'inicio'               => $inicio,
                    'fin'                  => $fin,
                    'dias_evento'          => $dias,
                    'descripcion'          => $evento['descripcion'] ?? '',
                    'archivo'              => $archivoNombre,
                    'eliminado'            => 0,
                    'tipo_incapacidad_sat' => $tipoIncapSat,
                ]);

                $eventosGuardados[]                           = $eventoGuardado;
                $eventFileMetadata[(int) $eventoGuardado->id] =
                    $archivoMetadata;
            }

            DB::connection('portal_main')->commit();
        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            foreach (array_reverse($writtenFiles) as $writtenFile) {
                if (is_file($writtenFile)) {
                    @unlink($writtenFile);
                }
            }

            $this->auditoria->registrar([
                'id_portal'    => (int) $administrator->id_portal,
                'actor_tipo'   => 'administrador',
                'actor_id'     => (int) $administrator->id,
                'actor_nombre' =>
                $this->administratorName($administrator),
                'modulo'       => 'comunicacion_interna',
                'entidad_tipo' => 'calendario_evento',
                'entidad_id'   => null,
                'accion'       => 'eventos_creados',
                'resultado'    => 'fallido',
                'descripcion'  => $e->getMessage(),
                'metadatos'    => [
                    'total_solicitados' => count($eventos),
                ],
            ], $request);

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
        foreach ($eventosGuardados as $createdEvent) {
            $metadata = $eventFileMetadata[
                (int) $createdEvent->id
            ] ?? null;

            $this->auditoria->registrar([
                'id_portal'    => (int) $administrator->id_portal,
                'id_cliente'   => (int) $createdEvent->id_cliente,
                'actor_tipo'   => 'administrador',
                'actor_id'     => (int) $administrator->id,
                'actor_nombre' =>
                $this->administratorName($administrator),
                'modulo'       => 'comunicacion_interna',
                'entidad_tipo' => 'calendario_evento',
                'entidad_id'   => (int) $createdEvent->id,
                'accion'       => $metadata
                    ? 'archivo_evento_cargado'
                    : 'evento_creado',
                'resultado'    => 'exitoso',
                'descripcion'  => $metadata
                    ? 'Se creó un evento con evidencia.'
                    : 'Se creó un evento.',
                'datos_nuevos' => [
                    'id_usuario'  => $createdEvent->id_usuario,
                    'id_empleado' => $createdEvent->id_empleado,
                    'id_portal'   => $createdEvent->id_portal,
                    'id_cliente'  => $createdEvent->id_cliente,
                    'id_tipo'     => $createdEvent->id_tipo,
                    'inicio'      => $createdEvent->inicio,
                    'fin'         => $createdEvent->fin,
                    'dias_evento' =>
                    $createdEvent->dias_evento,
                    'descripcion' =>
                    $createdEvent->descripcion,
                    'archivo'     => $createdEvent->archivo,
                ],
                'metadatos'    => $metadata
                    ? [
                    'storage_path'    =>
                    $metadata['storage_path'],
                    'nombre_original' =>
                    $metadata['original_name'],
                    'nombre_fisico'   =>
                    $metadata['physical_name'],
                    'mime_type'       =>
                    $metadata['mime_type'],
                    'size_bytes'      =>
                    $metadata['size_bytes'],
                ]
                    : null,
            ], $request);
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
        $administrator = $this->administrator($request);
        $portalId      = (int) $administrator->id_portal;

        $tipos = EventosOption::query()
            ->where(function ($query) use ($portalId) {
                $query
                    ->where('id_portal', $portalId)
                    ->orWhereNull('id_portal');
            })
            ->select('id', 'name', 'color')
            ->distinct()
            ->get();

        return response()->json($tipos);
    }
    private function resolveAuthorizedEventFile(
        Request $request,
        int $id
    ): array {
        $administrator = $this->administrator($request);

        $evento = CalendarioEvento::query()
            ->where('id', $id)
            ->where('eliminado', 0)
            ->firstOrFail();

        $employee = $this->authorizeEvent(
            $administrator,
            $evento
        );

        if (empty($evento->archivo)) {
            $this->auditEventFileAccess(
                $request,
                $administrator,
                $employee,
                $evento,
                'fallido',
                'El evento no tiene evidencia.'
            );

            abort(404, 'El evento no tiene archivo.');
        }

        try {
            $absolutePath = $this->documentPaths->absolutePath(
                self::DOCUMENT_CATEGORY,
                (string) $evento->archivo
            );
        } catch (\Throwable $exception) {
            $this->auditEventFileAccess(
                $request,
                $administrator,
                $employee,
                $evento,
                'fallido',
                'La ruta documental no es válida.'
            );

            abort(404, 'Archivo no encontrado.');
        }

        if (! is_file($absolutePath)) {
            $this->auditEventFileAccess(
                $request,
                $administrator,
                $employee,
                $evento,
                'fallido',
                'El archivo no existe físicamente.'
            );

            abort(404, 'Archivo no encontrado.');
        }

        return [
            'administrator' => $administrator,
            'employee'      => $employee,
            'evento'        => $evento,
            'absolute_path' => $absolutePath,
            'filename'      => basename($absolutePath),
            'mime_type'     => $this->eventFileMime($absolutePath),
            'size_bytes'    => filesize($absolutePath) ?: null,
        ];
    }

    private function eventFileMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $resource = finfo_open(FILEINFO_MIME_TYPE);

            if ($resource) {
                $mime = finfo_file($resource, $path);
                finfo_close($resource);

                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return 'application/octet-stream';
    }

    private function auditEventFileAccess(
        Request $request,
        AdministradorAuth $administrator,
        Empleado $employee,
        CalendarioEvento $evento,
        string $result,
        string $description,
        string $mode = 'visualizacion',
        ?string $absolutePath = null
    ): void {
        $this->auditoria->registrar([
            'id_portal'    => (int) $administrator->id_portal,
            'id_cliente'   => (int) (
                $evento->id_cliente ?: $employee->id_cliente
            ),
            'actor_tipo'   => 'administrador',
            'actor_id'     => (int) $administrator->id,
            'actor_nombre' =>
            $this->administratorName($administrator),
            'modulo'       => 'comunicacion_interna',
            'entidad_tipo' => 'calendario_evento',
            'entidad_id'   => (int) $evento->id,
            'accion'       => $mode === 'descarga'
                ? 'archivo_evento_descargado'
                : 'archivo_evento_visualizado',
            'resultado'    => $result,
            'descripcion'  => $description,
            'metadatos'    => [
                'modo'           => $mode,
                'storage_path'   => $evento->archivo,
                'storage_origen' =>
                str_starts_with(
                    (string) $evento->archivo,
                    'portales/'
                )
                    ? 'nuevo'
                    : 'legacy',
                'mime_type'      => $absolutePath
                    ? $this->eventFileMime($absolutePath)
                    : null,
                'size_bytes'     => $absolutePath
                && is_file($absolutePath)
                    ? filesize($absolutePath)
                    : null,
            ],
        ], $request);
    }

    public function streamArchivoCalendario(
        Request $request,
        $id
    ) {
        $file = $this->resolveAuthorizedEventFile(
            $request,
            (int) $id
        );

        $this->auditEventFileAccess(
            $request,
            $file['administrator'],
            $file['employee'],
            $file['evento'],
            'exitoso',
            'Se visualizó la evidencia de un evento.',
            'visualizacion',
            $file['absolute_path']
        );

        return response()->file(
            $file['absolute_path'],
            [
                'Content-Type'           => $file['mime_type'],
                'Content-Disposition'    =>
                'inline; filename="'
                . addslashes($file['filename'])
                . '"',
                'Cache-Control'          =>
                'private, max-age=0, no-cache, no-store, must-revalidate',
                'Pragma'                 => 'no-cache',
                'Expires'                => '0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    public function downloadArchivoCalendario(
        Request $request,
        $id
    ) {
        $file = $this->resolveAuthorizedEventFile(
            $request,
            (int) $id
        );

        $this->auditEventFileAccess(
            $request,
            $file['administrator'],
            $file['employee'],
            $file['evento'],
            'exitoso',
            'Se descargó la evidencia de un evento.',
            'descarga',
            $file['absolute_path']
        );

        return response()->download(
            $file['absolute_path'],
            $file['filename'],
            [
                'Content-Type'           => $file['mime_type'],
                'Cache-Control'          =>
                'private, max-age=0, no-cache, no-store, must-revalidate',
                'Pragma'                 => 'no-cache',
                'Expires'                => '0',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    private function administrator(Request $request): AdministradorAuth
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }

    private function employeeForPortal(
        AdministradorAuth $administrator,
        int $employeeId
    ): Empleado {
        if ($employeeId <= 0) {
            throw new AuthorizationException(
                'El empleado solicitado no es válido.'
            );
        }

        $employee = Empleado::query()
            ->where('id', $employeeId)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->first();

        if (! $employee) {
            throw new AuthorizationException(
                'El empleado no pertenece al portal autenticado.'
            );
        }

        return $employee;
    }

    private function authorizeEmployee(
        AdministradorAuth $administrator,
        int $employeeId
    ): Empleado {
        $employee = $this->employeeForPortal(
            $administrator,
            $employeeId
        );

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        return $employee;
    }

    private function authorizeEvent(
        AdministradorAuth $administrator,
        CalendarioEvento $event
    ): Empleado {
        $employee = $this->employeeForPortal(
            $administrator,
            (int) $event->id_empleado
        );

        $portalId = (int) (
            $event->id_portal ?: $employee->id_portal
        );

        if ($portalId !== (int) $administrator->id_portal) {
            throw new AuthorizationException(
                'El evento no pertenece al portal autenticado.'
            );
        }

        $clientId = (int) (
            $event->id_cliente ?: $employee->id_cliente
        );

        if ($clientId <= 0) {
            throw new AuthorizationException(
                'El evento no tiene un cliente válido.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$clientId]
        );

        return $employee;
    }
    private function storeEventFile(
        UploadedFile $file,
        Empleado $employee
    ): array {
        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        if ($documentsPath === '') {
            throw new RuntimeException(
                'La infraestructura documental nueva no está configurada.'
            );
        }

        $extension = strtolower(
            $file->getClientOriginalExtension()
        );

        $fileName = 'evento_'
        . Str::uuid()
            . ($extension !== '' ? '.' . $extension : '');

        $relativeDirectory = $this->documentPaths->uploadFolder(
            self::DOCUMENT_CATEGORY,
            $employee
        );

        $storedPath = $this->documentPaths->storedPath(
            self::DOCUMENT_CATEGORY,
            $employee,
            $fileName
        );

        $absoluteDirectory = $documentsPath
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativeDirectory
        );

        if (
            ! is_dir($absoluteDirectory)
            && ! @mkdir($absoluteDirectory, 0755, true)
            && ! is_dir($absoluteDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de incidencias.'
            );
        }

        $size         = $file->getSize();
        $mime         = $file->getMimeType();
        $originalName = basename(
            $file->getClientOriginalName()
        );

        $file->move($absoluteDirectory, $fileName);

        $absolutePath = $absoluteDirectory
            . DIRECTORY_SEPARATOR
            . $fileName;

        @chmod($absolutePath, 0664);

        return [
            'storage_path'  => $storedPath,
            'absolute_path' => $absolutePath,
            'original_name' => $originalName,
            'physical_name' => $fileName,
            'mime_type'     => $mime,
            'size_bytes'    => $size,
        ];
    }
    private function positiveIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            $raw = is_string($raw)
                ? explode(',', $raw)
                : [$raw];
        }

        return collect($raw)
            ->filter(fn($value) => $value !== null && $value !== '')
            ->map(fn($value) => (int) $value)
            ->filter(fn($value) => $value > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function authorizedEmployeeIds(
        Request $request,
        AdministradorAuth $administrator
    ): array {
        if ($request->has('id_empleado')) {
            $requestedIds = $this->positiveIds(
                $request->input('id_empleado')
            );

            if ($requestedIds === []) {
                return [];
            }

            $employees = Empleado::query()
                ->where(
                    'id_portal',
                    (int) $administrator->id_portal
                )
                ->whereIn('id', $requestedIds)
                ->get(['id', 'id_cliente']);

            if ($employees->count() !== count($requestedIds)) {
                throw new AuthorizationException(
                    'Uno o más empleados no pertenecen al portal autenticado.'
                );
            }

            $this->clientScope->authorizeRequestedClients(
                $administrator,
                $employees
                    ->pluck('id_cliente')
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all()
            );

            return $employees
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
        }

        $clientIds = $this->positiveIds(
            $request->input('id_cliente', [])
        );

        if ($clientIds === []) {
            return [];
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            $clientIds
        );

        return Empleado::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->whereIn('id_cliente', $clientIds)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
    }
    private function administratorName(
        AdministradorAuth $administrator
    ): ?string {
        $name = trim(implode(' ', array_filter([
            $administrator->nombre ?? null,
            $administrator->paterno ?? null,
            $administrator->materno ?? null,
        ])));

        return $name !== '' ? $name : null;
    }
}
