<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\ClienteInformacionInterna;
use App\Models\DocumentoInterno;
use App\Models\DocumentoInternoEmpleado;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\InternalDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClienteInformacionInternaController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private InternalDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}
    public function index(Request $request)
    {
        $data = $request->validate([
            'id_cliente' => ['required', 'integer', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $info = ClienteInformacionInterna::query()
            ->where('id_portal', (int) $administrator->id_portal)
            ->whereIn('id_cliente', $clientIds)
            ->where('eliminado', 0)
            ->with([
                'documentos.asignacionesEmpleados.empleado' => function ($query) {
                    $query->select([
                        'id',
                        'id_portal',
                        'id_cliente',
                        'nombre',
                        'paterno',
                        'materno',
                        'status',
                        'eliminado',
                    ]);
                },
            ])
            ->orderBy('nombre')
            ->get();

        return response()->json($info);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'id_cliente'  => ['required', 'integer', 'min:1'],
            'nombre'      => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $now = Carbon::now('America/Mexico_City');

        $info = ClienteInformacionInterna::create([
            'id_portal'   => (int) $administrator->id_portal,
            'id_cliente'  => $clientIds[0],
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'creacion'    => $now,
            'edicion'     => $now,
            'eliminado'   => 0,
        ]);
        $this->auditoria->registrar([
            'id_portal'        => (int) $info->id_portal,
            'id_cliente'       => (int) $info->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $this->administratorName($administrator),

            'modulo'           => 'informacion_interna',
            'entidad_tipo'     => 'directorio_interno',
            'entidad_id'       => (int) $info->id,
            'accion'           => 'directorio_creado',
            'resultado'        => 'exitoso',
            'descripcion'      => 'Se creó un directorio de información interna.',

            'datos_anteriores' => null,
            'datos_nuevos'     => [
                'nombre'      => $info->nombre,
                'descripcion' => $info->descripcion,
                'eliminado'   => (int) $info->eliminado,
            ],
        ], $request);
        return response()->json($info, 201);
    }

    public function update(
        Request $request,
        ClienteInformacionInterna $informacion
    ) {
        $data = $request->validate([
            'nombre'      => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
        ]);

        $administrator = $this->administrator($request);

        $this->authorizeInformation($administrator, $informacion);

        $previousData = [
            'nombre'      => $informacion->nombre,
            'descripcion' => $informacion->descripcion,
        ];

        $informacion->update([
            'nombre'      => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'edicion'     => Carbon::now('America/Mexico_City'),
        ]);

        $informacion->refresh();

        $this->auditoria->registrar([
            'id_portal'        => (int) $informacion->id_portal,
            'id_cliente'       => (int) $informacion->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $this->administratorName($administrator),

            'modulo'           => 'informacion_interna',
            'entidad_tipo'     => 'directorio_interno',
            'entidad_id'       => (int) $informacion->id,
            'accion'           => 'directorio_actualizado',
            'resultado'        => 'exitoso',
            'descripcion'      => 'Se actualizó un directorio de información interna.',

            'datos_anteriores' => $previousData,
            'datos_nuevos'     => [
                'nombre'      => $informacion->nombre,
                'descripcion' => $informacion->descripcion,
            ],
        ], $request);

        return response()->json($informacion);
    }

    public function destroy(
        Request $request,
        ClienteInformacionInterna $informacion
    ) {
        $administrator = $this->administrator($request);

        $this->authorizeInformation($administrator, $informacion);
        $previousData = [
            'nombre'      => $informacion->nombre,
            'descripcion' => $informacion->descripcion,
            'eliminado'   => (int) $informacion->eliminado,
        ];

        $documents = DocumentoInterno::query()
            ->withoutGlobalScope('no_eliminado')
            ->where(
                'id_informacion_interna',
                (int) $informacion->id
            )
            ->where('eliminado', 0)
            ->get();

        $movements = [];

        try {
            foreach ($documents as $document) {
                $movement = $this->documentPaths->moveToTrash(
                    $informacion,
                    (int) $document->id,
                    (string) $document->storage_path
                );

                if ($movement !== null) {
                    $movements[(int) $document->id] = $movement;
                }
            }

            $now = Carbon::now('America/Mexico_City');

            DB::connection('portal_main')->transaction(
                function () use (
                    $informacion,
                    $documents,
                    $movements,
                    $now
                ) {
                    foreach ($documents as $document) {
                        $document->eliminado = 1;

                        if (isset($movements[(int) $document->id])) {
                            $document->storage_path =
                                $movements[(int) $document->id]['ruta_borrado'];
                        }

                        $document->edicion = $now;
                        $document->save();

                        DocumentoInternoEmpleado::query()
                            ->withoutGlobalScope('no_eliminado')
                            ->where(
                                'id_documento_interno',
                                (int) $document->id
                            )
                            ->update([
                                'eliminado' => 1,
                                'edicion'   => $now,
                            ]);
                    }

                    $informacion->eliminado = 1;
                    $informacion->edicion   = $now;
                    $informacion->save();
                }
            );
        } catch (\Throwable $exception) {
            foreach (array_reverse($movements, true) as $movement) {
                try {
                    $this->documentPaths->restoreMovedFile(
                        $movement['ruta_borrado'],
                        $movement['ruta_anterior']
                    );
                } catch (\Throwable $restoreException) {
                    report($restoreException);
                }
            }

            throw $exception;
        }
        $this->auditoria->registrar([
            'id_portal'        => (int) $informacion->id_portal,
            'id_cliente'       => (int) $informacion->id_cliente,
            'actor_tipo'       => 'administrador',
            'actor_id'         => (int) $administrator->id,
            'actor_nombre'     => $this->administratorName($administrator),

            'modulo'           => 'informacion_interna',
            'entidad_tipo'     => 'directorio_interno',
            'entidad_id'       => (int) $informacion->id,
            'accion'           => 'directorio_eliminado',
            'resultado'        => 'exitoso',
            'descripcion'      => 'Se eliminó lógicamente un directorio de información interna.',

            'datos_anteriores' => $previousData,
            'datos_nuevos'     => [
                'eliminado' => 1,
            ],
            'metadatos'        => [
                'documentos_activos' => $documents->count(),
                'archivos_movidos'   => count($movements),
            ],
        ], $request);
        return response()->json(['ok' => true]);
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

    private function authorizeInformation(
        AdministradorAuth $administrator,
        ClienteInformacionInterna $informacion
    ): void {
        if (
            (int) $informacion->id_portal !==
            (int) $administrator->id_portal
        ) {
            throw new AuthorizationException(
                'La información interna no pertenece al portal autenticado.'
            );
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $informacion->id_cliente]
        );
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
