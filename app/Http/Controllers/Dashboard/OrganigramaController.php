<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Auth\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganigramaController extends Controller
{
    private string $conn = 'portal_main';

    private ?PermissionService $permSvc = null;

    private AdminClientScopeService $clientScope;

    public function __construct(
        AdminClientScopeService $clientScope,
        private AuditoriaService $auditoria
    ) {
        $this->clientScope = $clientScope;
    }
    private function perm(): PermissionService
    {
        return $this->permSvc ??= new PermissionService($this->conn);
    }

    private function roleIdOf($user): int
    {
        return (int) ($user->id_rol ?? $user->idRol ?? $user->role_id ?? 0);
    }

    private function can($user, string $key, ?int $clientId = null): bool
    {
        return $this->perm()->can(
            (int) $user->id,
            $this->roleIdOf($user),
            $key,
            $clientId
        );
    }
    /**
     * Obtener organigrama por portal y cliente
     */
    public function index(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $idPortal = (int) $administrator->id_portal;

        $permittedClientIds = $this->clientScope->permittedClientIds(
            $administrator
        );
        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as n')
            ->leftJoin('empleados as e', 'e.id', '=', 'n.empleado_id')
            ->where('n.id_portal', $idPortal)
            ->where('n.activo', 1)
            ->select(
                'n.id',
                'n.parent_id',
                'n.id_cliente',
                'n.titulo_puesto',
                'n.layout', // 🔥 AGREGAR ESTO
                'n.line_style',
                'n.color',
                'n.empleado_id',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.puesto as puesto_actual'
            )
            ->orderBy('n.orden')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $nodes,
        ]);
    }

    /**
     * Crear nodo
     */

    public function store(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'id_cliente'    => 'required|integer|min:1',
            'titulo_puesto' => 'required|string|max:150',
            'parent_id'     => 'nullable|integer|min:1',
            'empleado_id'   => 'nullable|integer|min:1',
            'layout'        => 'nullable|in:horizontal,vertical',
            'line_style'    => 'nullable|in:solid,dashed',
            'color'         => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $idPortal  = (int) $administrator->id_portal;
        $idCliente = (int) $data['id_cliente'];

        // El nodo nuevo debe pertenecer a una sucursal administrable.
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$idCliente]
        );
        if (
            ! empty($data['empleado_id']) &&
            ! $this->can(
                $administrator,
                'dashboards.organigrama.asignar_empleados',
                $idCliente
            )
        ) {
            return response()->json([
                'message' => 'Forbidden',
            ], 403);
        }
        try {
            $connection = DB::connection('portal_main');

            /*
         * El padre puede pertenecer a otra sucursal,
         * pero nunca a otro portal.
         */
            if (! empty($data['parent_id'])) {
                $parentExists = $connection
                    ->table('organigrama_nodes')
                    ->where('id', (int) $data['parent_id'])
                    ->where('id_portal', $idPortal)
                    ->where('activo', 1)
                    ->exists();

                if (! $parentExists) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Nodo padre no válido para este portal',
                    ], 422);
                }
            }

            /*
         * Si se asigna empleado, debe pertenecer al mismo portal
         * y a la sucursal del nodo que estamos creando.
         */
            if (! empty($data['empleado_id'])) {
                $employeeExists = $connection
                    ->table('empleados')
                    ->where('id', (int) $data['empleado_id'])
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->where(function ($query) {
                        $query->where('eliminado', 0)
                            ->orWhereNull('eliminado');
                    })
                    ->exists();

                if (! $employeeExists) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Empleado no válido para la sucursal seleccionada',
                    ], 422);
                }
            }

            $id = $connection
                ->table('organigrama_nodes')
                ->insertGetId([
                    'id_portal'     => $idPortal,
                    'id_cliente'    => $idCliente,
                    'parent_id'     => $data['parent_id'] ?? null,
                    'empleado_id'   => $data['empleado_id'] ?? null,
                    'titulo_puesto' => $data['titulo_puesto'],
                    'layout'        => $data['layout'] ?? 'horizontal',
                    'line_style'    => $data['line_style'] ?? 'solid',
                    'color'         => $data['color'] ?? null,
                    'orden'         => 0,
                    'activo'        => 1,
                    'creacion'      => now(),
                    'edicion'       => now(),
                ]);

            $newNode = $connection
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->where('id_portal', $idPortal)
                ->first();

            $this->auditoria->registrar([
                'id_portal'    => $idPortal,
                'id_cliente'   => $idCliente,
                'actor_tipo'   => 'administrador',
                'actor_id'     => (int) $administrator->id,
                'actor_nombre' => $this->administratorName($administrator),
                'modulo'       => 'dashboard',
                'entidad_tipo' => 'organigrama_nodo',
                'entidad_id'   => (int) $id,
                'accion'       => 'crear_nodo',
                'resultado'    => 'exitoso',
                'descripcion'  => 'Nodo de organigrama creado.',
                'datos_nuevos' => [

                    'id'            => (int) $newNode->id,
                    'id_cliente'    => (int) $newNode->id_cliente,
                    'parent_id'     => $newNode->parent_id
                        ? (int) $newNode->parent_id
                        : null,
                    'empleado_id'   => $newNode->empleado_id
                        ? (int) $newNode->empleado_id
                        : null,
                    'titulo_puesto' => $newNode->titulo_puesto,
                    'layout'        => $newNode->layout,
                    'line_style'    => $newNode->line_style,
                    'color'         => $newNode->color,
                    'activo'        => (int) $newNode->activo,
                ],
            ], $request);
            return response()->json([
                'status' => true,
                'data'   => $newNode,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function insertAbove(Request $request, $id)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'id_cliente'    => 'required|integer|min:1',
            'titulo_puesto' => 'required|string|max:150',
            'empleado_id'   => 'nullable|integer|min:1',
            'layout'        => 'nullable|in:horizontal,vertical',
            'line_style'    => 'nullable|in:solid,dashed',
            'color'         => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $idPortal  = (int) $administrator->id_portal;
        $idCliente = (int) $data['id_cliente'];
        $nodeId    = (int) $id;

        try {
            $connection = DB::connection('portal_main');

            /*
         * Nodo sobre el cual vamos a insertar.
         * Nunca puede ser de otro portal.
         */
            $targetNode = $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->first();

            if (! $targetNode) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Nodo no válido para este portal',
                ], 404);
            }

            /*
         * Se van a modificar:
         *
         * 1. El nodo nuevo.
         * 2. El parent_id del nodo seleccionado.
         *
         * Por eso ambas sucursales deben ser administrables.
         */
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                array_values(array_unique([
                    $idCliente,
                    (int) $targetNode->id_cliente,
                ]))
            );

            /*
         * Si el nuevo nodo tendrá empleado, también requiere
         * permiso de asignación.
         */
            if (
                ! empty($data['empleado_id']) &&
                ! $this->can(
                    $administrator,
                    'dashboards.organigrama.asignar_empleados',
                    $idCliente
                )
            ) {
                return response()->json([
                    'message' => 'Forbidden',
                ], 403);
            }

            /*
         * El empleado debe pertenecer al portal y a la sucursal
         * del nuevo nodo.
         */
            if (! empty($data['empleado_id'])) {
                $employeeExists = $connection
                    ->table('empleados')
                    ->where('id', (int) $data['empleado_id'])
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', $idCliente)
                    ->where(function ($query) {
                        $query->where('eliminado', 0)
                            ->orWhereNull('eliminado');
                    })
                    ->exists();

                if (! $employeeExists) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Empleado no válido para la sucursal seleccionada',
                    ], 422);
                }
            }

            $oldParentId = $targetNode->parent_id
                ? (int) $targetNode->parent_id
                : null;

            $newNode = null;

            $connection->transaction(function () use (
                $connection,
                $data,
                $idPortal,
                $idCliente,
                $nodeId,
                $oldParentId,
                &$newNode
            ) {
                /*
             * El nodo nuevo ocupa exactamente la posición que
             * antes tenía el nodo seleccionado.
             *
             * Si oldParentId es null, automáticamente se convierte
             * en nuevo root.
             */
                $newId = $connection
                    ->table('organigrama_nodes')
                    ->insertGetId([
                        'id_portal'     => $idPortal,
                        'id_cliente'    => $idCliente,
                        'parent_id'     => $oldParentId,
                        'empleado_id'   => $data['empleado_id'] ?? null,
                        'titulo_puesto' => $data['titulo_puesto'],
                        'layout'        => $data['layout'] ?? 'horizontal',
                        'line_style'    => $data['line_style'] ?? 'solid',
                        'color'         => $data['color'] ?? null,
                        'orden'         => 0,
                        'activo'        => 1,
                        'creacion'      => now(),
                        'edicion'       => now(),
                    ]);

                /*
             * El nodo seleccionado pasa a depender del nuevo nodo.
             */
                $connection
                    ->table('organigrama_nodes')
                    ->where('id', $nodeId)
                    ->where('id_portal', $idPortal)
                    ->update([
                        'parent_id' => $newId,
                        'edicion'   => now(),
                    ]);

                $newNode = $connection
                    ->table('organigrama_nodes')
                    ->where('id', $newId)
                    ->where('id_portal', $idPortal)
                    ->first();
            });

            /*
         * Una sola auditoría para toda la operación lógica.
         */
            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => $idCliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     => $this->administratorName($administrator),
                'modulo'           => 'dashboard',
                'entidad_tipo'     => 'organigrama_nodo',
                'entidad_id'       => (int) $newNode->id,
                'accion'           => 'crear_nodo_superior',
                'resultado'        => 'exitoso',
                'descripcion'      => 'Nodo insertado por encima de otro nodo del organigrama.',

                'datos_anteriores' => [
                    'nodo_objetivo_id' => $nodeId,
                    'parent_id'        => $oldParentId,
                ],

                'datos_nuevos'     => [
                    'nuevo_nodo'    => [
                        'id'            => (int) $newNode->id,
                        'id_cliente'    => (int) $newNode->id_cliente,
                        'parent_id'     => $newNode->parent_id
                            ? (int) $newNode->parent_id
                            : null,
                        'empleado_id'   => $newNode->empleado_id
                            ? (int) $newNode->empleado_id
                            : null,
                        'titulo_puesto' => $newNode->titulo_puesto,
                        'layout'        => $newNode->layout,
                        'line_style'    => $newNode->line_style,
                        'color'         => $newNode->color,
                    ],

                    'nodo_objetivo' => [
                        'id'        => $nodeId,
                        'parent_id' => (int) $newNode->id,
                    ],
                ],
            ], $request);

            return response()->json([
                'status' => true,
                'data'   => [
                    'new_node' => $newNode,
                    'target'   => [
                        'id'        => $nodeId,
                        'parent_id' => (int) $newNode->id,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Actualizar nodo
     */
    public function update(Request $request, $id)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'titulo_puesto' => 'sometimes|required|string|max:150',
            'parent_id'     => 'sometimes|nullable|integer|min:1',
            'empleado_id'   => 'sometimes|nullable|integer|min:1',
            'layout'        => 'sometimes|in:horizontal,vertical',
            'line_style'    => 'sometimes|in:solid,dashed',
            'color'         => ['sometimes', 'nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        try {
            $connection = DB::connection('portal_main');
            $idPortal   = (int) $administrator->id_portal;
            $nodeId     = (int) $id;

            /*
         * El nodo objetivo debe pertenecer al portal autenticado.
         */
            $node = $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->first();

            if (! $node) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NOT_FOUND',
                ], 404);
            }

            /*
         * El administrador debe tener alcance sobre
         * la sucursal propietaria del nodo.
         */
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                [(int) $node->id_cliente]
            );
            if (
                array_key_exists('empleado_id', $data) &&
                (int) ($data['empleado_id'] ?? 0) !== (int) ($node->empleado_id ?? 0) &&
                ! $this->can(
                    $administrator,
                    'dashboards.organigrama.asignar_empleados',
                    (int) $node->id_cliente
                )
            ) {
                return response()->json([
                    'message' => 'Forbidden',
                ], 403);
            }
            /*
         * El nuevo padre puede pertenecer a otra sucursal,
         * pero siempre debe pertenecer al mismo portal.
         */
            if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
                $parentId = (int) $data['parent_id'];

                if ($parentId === $nodeId) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Un nodo no puede ser su propio padre',
                    ], 422);
                }

                $parent = $connection
                    ->table('organigrama_nodes')
                    ->where('id', $parentId)
                    ->where('id_portal', $idPortal)
                    ->where('activo', 1)
                    ->first();

                if (! $parent) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Nodo padre no válido para este portal',
                    ], 422);
                }

/*
 * Evitar ciclos:
 * el nuevo padre no puede ser descendiente del nodo actual.
 */
                $ancestorId = $parent->parent_id;

                while ($ancestorId) {
                    if ((int) $ancestorId === $nodeId) {
                        return response()->json([
                            'status'  => false,
                            'message' => 'No se puede mover el nodo debajo de uno de sus descendientes',
                        ], 422);
                    }

                    $ancestor = $connection
                        ->table('organigrama_nodes')
                        ->where('id', (int) $ancestorId)
                        ->where('id_portal', $idPortal)
                        ->where('activo', 1)
                        ->first();

                    if (! $ancestor) {
                        break;
                    }

                    $ancestorId = $ancestor->parent_id;
                }
            }

            /*
         * El empleado debe pertenecer al mismo portal
         * y a la sucursal propietaria del nodo.
         */
            if (array_key_exists('empleado_id', $data) && $data['empleado_id'] !== null) {
                $employeeExists = $connection
                    ->table('empleados')
                    ->where('id', (int) $data['empleado_id'])
                    ->where('id_portal', $idPortal)
                    ->where('id_cliente', (int) $node->id_cliente)
                    ->where(function ($query) {
                        $query->where('eliminado', 0)
                            ->orWhereNull('eliminado');
                    })
                    ->exists();

                if (! $employeeExists) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'Empleado no válido para la sucursal del nodo',
                    ], 422);
                }
            }

            if ($data === []) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NO_DATA',
                ], 400);
            }

            $updateData            = $data;
            $updateData['edicion'] = now();

            $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->update($updateData);

            $newNode = $connection
                ->table('organigrama_nodes as o')
                ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
                ->select(
                    'o.id',
                    'o.id_cliente',
                    'o.parent_id',
                    'o.titulo_puesto',
                    'o.layout',
                    'o.line_style',
                    'o.color',
                    'o.empleado_id',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.foto',
                    'e.puesto as puesto_actual'
                )
                ->where('o.id', $nodeId)
                ->where('o.id_portal', $idPortal)
                ->first();
            $empleadoAnterior = $node->empleado_id
                ? (int) $node->empleado_id
                : null;

            $empleadoNuevo = $newNode->empleado_id
                ? (int) $newNode->empleado_id
                : null;

            $accionAuditoria = $empleadoAnterior !== $empleadoNuevo
                ? 'asignar_empleado'
                : 'editar_nodo';

            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => (int) $node->id_cliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     => $this->administratorName($administrator),
                'modulo'           => 'dashboard',
                'entidad_tipo'     => 'organigrama_nodo',
                'entidad_id'       => $nodeId,
                'accion'           => $accionAuditoria,
                'resultado'        => 'exitoso',
                'descripcion'      => $accionAuditoria === 'asignar_empleado'
                    ? 'Asignación de empleado en nodo de organigrama actualizada.'
                    : 'Nodo de organigrama actualizado.',
                'datos_anteriores' => [
                    'parent_id'     => $node->parent_id
                        ? (int) $node->parent_id
                        : null,
                    'empleado_id'   => $empleadoAnterior,
                    'titulo_puesto' => $node->titulo_puesto,
                    'layout'        => $node->layout,
                    'line_style'    => $node->line_style,
                    'color'         => $node->color,
                ],
                'datos_nuevos'     => [
                    'parent_id'     => $newNode->parent_id
                        ? (int) $newNode->parent_id
                        : null,
                    'empleado_id'   => $empleadoNuevo,
                    'titulo_puesto' => $newNode->titulo_puesto,
                    'layout'        => $newNode->layout,
                    'line_style'    => $newNode->line_style,
                    'color'         => $newNode->color,
                ],
            ], $request);
            return response()->json([
                'status' => true,
                'code'   => 'UPDATED',
                'data'   => $newNode,
            ]);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'error'  => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $connection = DB::connection('portal_main');
            $idPortal   = (int) $administrator->id_portal;
            $nodeId     = (int) $id;

            /*
         * El nodo raíz debe pertenecer al portal autenticado.
         */
            $node = $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->first();

            if (! $node) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NOT_FOUND',
                ], 404);
            }

            /*
         * El administrador debe poder administrar
         * la sucursal propietaria del nodo raíz.
         */
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                [(int) $node->id_cliente]
            );

            /*
         * Cargamos todos los nodos activos del portal.
         * No filtramos por cliente porque una rama
         * puede mezclar varias sucursales.
         */
            $nodes = $connection
                ->table('organigrama_nodes')
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->get();

            $idsToDeactivate = [];

            $collectChildren = function (
                $nodes,
                $currentId,
                &$idsToDeactivate,
                $collectChildren
            ) {
                $idsToDeactivate[] = (int) $currentId;

                foreach ($nodes as $child) {
                    if ((int) $child->parent_id === (int) $currentId) {
                        $collectChildren(
                            $nodes,
                            (int) $child->id,
                            $idsToDeactivate,
                            $collectChildren
                        );
                    }
                }
            };

            $collectChildren(
                $nodes,
                $nodeId,
                $idsToDeactivate,
                $collectChildren
            );

            /*
         * Obtener los clientes involucrados en toda la rama.
         */
            $branchClientIds = $nodes
                ->whereIn('id', $idsToDeactivate)
                ->pluck('id_cliente')
                ->map(fn($clientId) => (int) $clientId)
                ->unique()
                ->values()
                ->all();

            /*
         * La eliminación es todo-o-nada:
         * el administrador debe tener alcance sobre TODAS
         * las sucursales presentes en la rama.
         */
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                $branchClientIds
            );

            $connection->transaction(function () use (
                $connection,
                $idsToDeactivate,
                $idPortal
            ) {
                $connection
                    ->table('organigrama_nodes')
                    ->where('id_portal', $idPortal)
                    ->whereIn('id', $idsToDeactivate)
                    ->where('activo', 1)
                    ->update([
                        'empleado_id' => null,
                        'activo'      => 0,
                        'edicion'     => now(),
                    ]);
            });
            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => (int) $node->id_cliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     => $this->administratorName($administrator),
                'modulo'           => 'dashboard',
                'entidad_tipo'     => 'organigrama_rama',
                'entidad_id'       => $nodeId,
                'accion'           => 'eliminar_rama',
                'resultado'        => 'exitoso',
                'descripcion'      => 'Rama de organigrama eliminada lógicamente.',
                'datos_anteriores' => [
                    'nodo_raiz' => [
                        'id'            => (int) $node->id,
                        'id_cliente'    => (int) $node->id_cliente,
                        'parent_id'     => $node->parent_id
                            ? (int) $node->parent_id
                            : null,
                        'empleado_id'   => $node->empleado_id
                            ? (int) $node->empleado_id
                            : null,
                        'titulo_puesto' => $node->titulo_puesto,
                    ],
                ],
                'datos_nuevos'     => [
                    'activo'      => 0,
                    'empleado_id' => null,
                ],
                'metadatos'        => [
                    'total_nodos'        => count($idsToDeactivate),
                    'nodos_afectados'    => $idsToDeactivate,
                    'clientes_afectados' => $branchClientIds,
                ],
            ], $request);
            return response()->json([
                'status' => true,
                'code'   => 'DELETED',
                'total'  => count($idsToDeactivate),
            ]);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'code'   => 'SERVER_ERROR',
            ], 500);
        }
    }

    public function removeEmployee(Request $request, $id)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $connection = DB::connection('portal_main');
            $idPortal   = (int) $administrator->id_portal;
            $nodeId     = (int) $id;

            $node = $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->first();

            if (! $node) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NOT_FOUND',
                ], 404);
            }

            /*
         * Solo puede quitar empleados de nodos
         * cuya sucursal pueda administrar.
         */
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                [(int) $node->id_cliente]
            );

            if ($node->empleado_id === null) {
                return response()->json([
                    'status' => true,
                    'code'   => 'EMPLOYEE_ALREADY_EMPTY',
                ]);
            }

            $connection
                ->table('organigrama_nodes')
                ->where('id', $nodeId)
                ->where('id_portal', $idPortal)
                ->update([
                    'empleado_id' => null,
                    'edicion'     => now(),
                ]);
            $this->auditoria->registrar([
                'id_portal'        => $idPortal,
                'id_cliente'       => (int) $node->id_cliente,
                'actor_tipo'       => 'administrador',
                'actor_id'         => (int) $administrator->id,
                'actor_nombre'     => $this->administratorName($administrator),
                'modulo'           => 'dashboard',
                'entidad_tipo'     => 'organigrama_nodo',
                'entidad_id'       => $nodeId,
                'accion'           => 'quitar_empleado',
                'resultado'        => 'exitoso',
                'descripcion'      => 'Empleado removido de nodo de organigrama.',
                'datos_anteriores' => [
                    'empleado_id' => (int) $node->empleado_id,
                ],
                'datos_nuevos'     => [
                    'empleado_id' => null,
                ],
            ], $request);
            return response()->json([
                'status' => true,
                'code'   => 'EMPLOYEE_REMOVED',
            ]);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'code'   => 'SERVER_ERROR',
            ], 500);
        }
    }

    public function primerClienteConDatos(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $idPortal = (int) $administrator->id_portal;

        $clientIds = $this->clientScope->permittedClientIds(
            $administrator
        );

        if ($clientIds === []) {
            return response()->json([
                'status' => false,
                'data'   => null,
            ]);
        }

        $cliente = DB::connection('portal_main')
            ->table('organigrama_nodes')
            ->where('id_portal', $idPortal)
            ->whereIn('id_cliente', $clientIds) // 🔒 solo permitidos
            ->where('activo', 1)
            ->select('id_cliente')
            ->groupBy('id_cliente')
            ->orderBy('id_cliente')
            ->first();

        return response()->json([
            'status' => $cliente ? true : false,
            'data'   => $cliente,
        ]);
    }
    public function empleadosDisponibles(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $idPortal = (int) $administrator->id_portal;

        $permittedClientIds = $this->clientScope->permittedClientIds(
            $administrator
        );

        if ($permittedClientIds === []) {
            return response()->json([
                'status'   => true,
                'clientes' => [],
                'data'     => [],
            ]);
        }
        $clientesPermitidos = DB::connection('portal_main')
            ->table('cliente')
            ->where('id_portal', $idPortal)
            ->whereIn('id', $permittedClientIds)
            ->where('status', 1)
            ->where('eliminado', 0)
            ->select(
                'id',
                'nombre'
            )
            ->orderBy('nombre')
            ->get()
            ->map(function ($cliente) {
                return [
                    'id_cliente' => (int) $cliente->id,
                    'sucursal'   => $cliente->nombre,
                ];
            })
            ->values();
        /*
     * Si viene id_cliente, se usa como filtro opcional.
     * Si no viene, regresamos todas las sucursales autorizadas.
     */
        $idCliente = (int) $request->query('id_cliente', 0);

        if ($idCliente > 0) {
            $this->clientScope->authorizeRequestedClients(
                $administrator,
                [$idCliente]
            );

            $clientIds = [$idCliente];
        } else {
            $clientIds = $permittedClientIds;
        }

        $empleados = DB::connection('portal_main')
            ->table('empleados as e')
            ->join('cliente as c', function ($join) use ($idPortal) {
                $join->on('c.id', '=', 'e.id_cliente')
                    ->where('c.id_portal', $idPortal);
            })
            ->leftJoin('organigrama_nodes as n', function ($join) use ($idPortal) {
                $join->on('n.empleado_id', '=', 'e.id')
                    ->where('n.id_portal', $idPortal)
                    ->where('n.activo', 1);
            })
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id_cliente', $clientIds)
            ->where('e.status', 1)
            ->where(function ($query) {
                $query->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->where('c.status', 1)
            ->where('c.eliminado', 0)
            ->whereNull('n.id')
            ->select(
                'e.id',
                'e.id_cliente',
                'c.nombre as sucursal',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.puesto',
                'e.departamento'
            )
            ->orderBy('c.nombre')
            ->orderBy('e.departamento')
            ->orderBy('e.nombre')
            ->get();

        $agrupados = $empleados
            ->groupBy('id_cliente')
            ->map(function ($items, $idCliente) {
                $primero = $items->first();

                return [
                    'id_cliente' => (int) $idCliente,
                    'sucursal'   => $primero->sucursal,
                    'empleados'  => $items
                        ->map(function ($empleado) {
                            return [
                                'id'           => (int) $empleado->id,
                                'nombre'       => $empleado->nombre,
                                'paterno'      => $empleado->paterno,
                                'materno'      => $empleado->materno,
                                'foto'         => $empleado->foto,
                                'puesto'       => $empleado->puesto,
                                'departamento' => $empleado->departamento,
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        return response()->json([
            'status'   => true,
            'clientes' => $clientesPermitidos,
            'data'     => $agrupados,
        ]);
    }
    public function getRoot(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $portalId = (int) $administrator->id_portal;

        $permittedClientIds = $this->clientScope->permittedClientIds(
            $administrator
        );

        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as o')
            ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
            ->select(
                'o.id',
                'o.parent_id',
                'o.id_cliente',
                'o.titulo_puesto',
                'o.layout',
                'o.line_style',
                'o.color',
                'o.empleado_id',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.puesto as puesto_actual',
                DB::raw('EXISTS(
                SELECT 1 FROM organigrama_nodes o2
                WHERE o2.parent_id = o.id
                AND o2.id_portal = o.id_portal
                AND o2.activo = 1
            ) as has_children')
            )
            ->where('o.id_portal', $portalId)
            ->whereNull('o.parent_id')
            ->where('o.activo', 1)
            ->orderBy('o.orden')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $nodes,
        ]);
    }

    public function getChildren(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $portalId = (int) $administrator->id_portal;
        $parentId = (int) $request->input('parent_id');

        if ($parentId <= 0) {
            return response()->json([
                'status'  => false,
                'message' => 'parent_id es requerido',
            ], 422);
        }

        $parentNode = DB::connection('portal_main')
            ->table('organigrama_nodes')
            ->where('id', $parentId)
            ->where('id_portal', $portalId)
            ->where('activo', 1)
            ->first();

        if (! $parentNode) {
            return response()->json([
                'status'  => false,
                'message' => 'Nodo padre no encontrado',
            ], 404);
        }

        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as o')
            ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
            ->select(
                'o.id',
                'o.parent_id',
                'o.id_cliente',
                'o.titulo_puesto',
                'o.layout',
                'o.line_style',
                'o.color',
                'o.empleado_id',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.puesto as puesto_actual',

                DB::raw('EXISTS(
                SELECT 1 FROM organigrama_nodes o2
                WHERE o2.parent_id = o.id
                AND o2.id_portal = o.id_portal
                AND o2.activo = 1
            ) as has_children')
            )
            ->where('o.id_portal', $portalId)
            ->where('o.parent_id', $parentId)
            ->where('o.activo', 1)
            ->orderBy('o.orden')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $nodes,
        ]);
    }

    public function options(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $idPortal  = (int) $administrator->id_portal;
        $idCliente = (int) $request->query('id_cliente');

        if ($idCliente <= 0) {
            return response()->json([
                'status'  => false,
                'message' => 'id_cliente es requerido',
            ], 422);
        }

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$idCliente]
        );
        $search = trim((string) $request->query('search', ''));
        $query  = DB::connection('portal_main')
            ->table('organigrama_nodes as o')
            ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
            ->where('o.id_portal', $idPortal)
            ->where('o.id_cliente', $idCliente)
            ->where('o.activo', 1);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('o.titulo_puesto', 'like', "%{$search}%")
                    ->orWhere('e.nombre', 'like', "%{$search}%")
                    ->orWhere('e.paterno', 'like', "%{$search}%")
                    ->orWhere('e.materno', 'like', "%{$search}%");
            });
        }

        $nodes = $query
            ->select(
                'o.id',
                'o.parent_id',
                'o.titulo_puesto',
                'o.empleado_id',
                'e.nombre',
                'e.paterno',
                'e.materno'
            )
            ->limit(30)
            ->get();

        $result = $nodes->map(function ($node) use ($idPortal, $idCliente) {

            return [
                'id'      => $node->id,
                'node_id' => $node->id,
                'type'    => $node->empleado_id ? 'empleado' : 'nodo',
                'label'   => $node->empleado_id
                    ? trim("{$node->nombre} {$node->paterno} {$node->materno}") . " - {$node->titulo_puesto}"
                    : "{$node->titulo_puesto} (Vacante)",
                'parent_chain' => $this->buildParentChain(
                    $node->parent_id,
                    $idPortal
                ),
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => $result,
        ]);
    }
    private function buildParentChain($parentId, $idPortal)
    {
        $chain = [];

        while ($parentId) {
            $parent = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $parentId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->first();

            if (! $parent) {
                break;
            }

            array_unshift($chain, $parent->id);

            $parentId = $parent->parent_id;
        }

        return $chain;
    }

    public function storeBulkChildren(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $request->validate([
            'id_cliente'         => 'required|integer|min:1',
            'parent_id'          => 'required|integer|min:1',
            'empleados'          => 'required|array|min:1',
            'empleados.*.id'     => 'required|integer|min:1|distinct',
            'empleados.*.nombre' => 'nullable|string|max:150',
            'line_style'         => 'nullable|in:solid,dashed',
            'layout'             => 'nullable|in:horizontal,vertical',
        ]);

        $idPortal  = (int) $administrator->id_portal;
        $idCliente = (int) $data['id_cliente'];
        $parentId  = (int) $data['parent_id'];

        /*
     * Los nodos nuevos pertenecen a esta sucursal,
     * por lo que debe estar dentro del alcance administrativo.
     */
        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$idCliente]
        );

        try {
            $connection = DB::connection('portal_main');

            /*
         * El padre puede pertenecer a otra sucursal,
         * pero siempre debe pertenecer al mismo portal.
         */
            $parentExists = $connection
                ->table('organigrama_nodes')
                ->where('id', $parentId)
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->exists();

            if (! $parentExists) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Nodo padre no válido para este portal',
                ], 422);
            }

            $employeeIds = collect($data['empleados'])
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();

            /*
         * Todos los empleados deben pertenecer al portal
         * autenticado y a la sucursal seleccionada.
         */
            $validEmployeeIds = $connection
                ->table('empleados')
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
                ->whereIn('id', $employeeIds)
                ->where(function ($query) {
                    $query->where('eliminado', 0)
                        ->orWhereNull('eliminado');
                })
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();

            $invalidEmployeeIds = array_values(
                array_diff($employeeIds, $validEmployeeIds)
            );

            if ($invalidEmployeeIds !== []) {
                return response()->json([
                    'status'              => false,
                    'message'             => 'Uno o más empleados no pertenecen a la sucursal autorizada',
                    'empleados_invalidos' => $invalidEmployeeIds,
                ], 422);
            }

            /*
         * Evitar asignar empleados que ya estén dentro
         * de un nodo activo del organigrama.
         */
            $alreadyAssigned = $connection
                ->table('organigrama_nodes')
                ->where('id_portal', $idPortal)
                ->where('activo', 1)
                ->whereIn('empleado_id', $employeeIds)
                ->pluck('empleado_id')
                ->map(fn($id) => (int) $id)
                ->values()
                ->all();

            if ($alreadyAssigned !== []) {
                return response()->json([
                    'status'              => false,
                    'message'             => 'Uno o más empleados ya están asignados al organigrama',
                    'empleados_asignados' => $alreadyAssigned,
                ], 422);
            }

            $createdNodes = $connection->transaction(function () use (
                $connection,
                $data,
                $idPortal,
                $idCliente,
                $parentId
            ) {
                $now        = now();
                $createdIds = [];

                foreach ($data['empleados'] as $emp) {
                    $id = $connection
                        ->table('organigrama_nodes')
                        ->insertGetId([
                            'id_portal'     => $idPortal,
                            'id_cliente'    => $idCliente,
                            'parent_id'     => $parentId,
                            'empleado_id'   => (int) $emp['id'],
                            'titulo_puesto' => $emp['puesto'] ?? 'Nuevo puesto',
                            'layout'        => $data['layout'] ?? 'horizontal',
                            'line_style'    => $data['line_style'] ?? 'solid',
                            'orden'         => 0,
                            'activo'        => 1,
                            'creacion'      => $now,
                            'edicion'       => $now,
                        ]);

                    $createdIds[] = $id;
                }

                return $connection
                    ->table('organigrama_nodes as o')
                    ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
                    ->where('o.id_portal', $idPortal)
                    ->whereIn('o.id', $createdIds)
                    ->select(
                        'o.id',
                        'o.id_cliente',
                        'o.parent_id',
                        'o.titulo_puesto',
                        'o.layout',
                        'o.line_style',
                        'o.empleado_id',
                        'e.nombre',
                        'e.paterno',
                        'e.materno',
                        'e.foto',
                        'e.puesto as puesto_actual',
                        DB::raw('0 as has_children')
                    )
                    ->orderBy('o.id')
                    ->get();
            });
            $this->auditoria->registrar([
                'id_portal'    => $idPortal,
                'id_cliente'   => $idCliente,
                'actor_tipo'   => 'administrador',
                'actor_id'     => (int) $administrator->id,
                'actor_nombre' => $this->administratorName($administrator),
                'modulo'       => 'dashboard',
                'entidad_tipo' => 'organigrama_nodos',
                'entidad_id'   => $parentId,
                'accion'       => 'crear_nodos_masivo',
                'resultado'    => 'exitoso',
                'descripcion'  => 'Creación masiva de nodos de organigrama.',
                'datos_nuevos' => [
                    'parent_id' => $parentId,
                    'nodos'     => $createdNodes->map(function ($node) {
                        return [
                            'id'            => (int) $node->id,
                            'id_cliente'    => (int) $node->id_cliente,
                            'empleado_id'   => $node->empleado_id
                                ? (int) $node->empleado_id
                                : null,
                            'titulo_puesto' => $node->titulo_puesto,
                            'layout'        => $node->layout,
                            'line_style'    => $node->line_style,
                        ];
                    })->values()->all(),
                ],
                'metadatos'    => [
                    'total_creados'       => $createdNodes->count(),
                    'nodos_creados'       => $createdNodes
                        ->pluck('id')
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->all(),
                    'empleados_asignados' => $createdNodes
                        ->pluck('empleado_id')
                        ->filter()
                        ->map(fn($id) => (int) $id)
                        ->values()
                        ->all(),
                ],
            ], $request);

            return response()->json([
                'status' => true,
                'data'   => $createdNodes,
            ]);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;

        } catch (\Exception $e) {
            \Log::error('Error creando hijos bulk organigrama', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error creando hijos del organigrama',
            ], 500);
        }
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

}
