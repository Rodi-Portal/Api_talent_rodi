<?php
namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrganigramaController extends Controller
{
    /**
     * Obtener organigrama por portal y cliente
     */
    public function index(Request $request)
    {
        $idPortal  = $request->query('id_portal');
        $idCliente = $request->query('id_cliente');

        if (! $idPortal || ! $idCliente) {
            return response()->json([
                'status'  => false,
                'message' => 'id_portal e id_cliente son requeridos',
            ], 400);
        }

        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as n')
            ->leftJoin('empleados as e', 'e.id', '=', 'n.empleado_id')
            ->where('n.id_portal', $idPortal)
            ->where('n.id_cliente', $idCliente)
            ->where('n.activo', 1)
            ->select(
                'n.id',
                'n.parent_id',
                'n.titulo_puesto',
                'n.layout', // 🔥 AGREGAR ESTO
                'n.line_style',
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
        $request->validate([
            'id_portal'     => 'required|integer',
            'id_cliente'    => 'required|integer',
            'titulo_puesto' => 'required|string|max:150',
            'parent_id'     => 'nullable|integer',
            'empleado_id'   => 'nullable|integer',
            'layout'        => 'nullable|in:horizontal,vertical', // 🔥
            'line_style'    => 'nullable|in:solid,dashed',

        ]);

        try {

            $id = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->insertGetId([
                    'id_portal'     => $request->id_portal,
                    'id_cliente'    => $request->id_cliente,
                    'parent_id'     => $request->parent_id,
                    'empleado_id'   => $request->empleado_id,
                    'titulo_puesto' => $request->titulo_puesto,
                    'layout'        => $request->layout ?? 'horizontal', // 🔥
                    'line_style'    => $request->line_style ?? 'solid',  // 🔥 AGREGAR

                    'orden'         => 0,
                    'activo'        => 1,
                    'creacion'      => now(),
                    'edicion'       => now(),
                ]);

            $newNode = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->first();

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

    /**
     * Actualizar nodo
     */
    public function update(Request $request, $id)
    {
        \Log::info('REQUEST UPDATE ORGANIGRAMA', $request->all());

        try {

            $updateData = [];

            if ($request->has('titulo_puesto')) {
                $updateData['titulo_puesto'] = $request->titulo_puesto;
            }

            if ($request->has('parent_id')) {
                $updateData['parent_id'] = $request->parent_id;
            }

            if ($request->exists('empleado_id')) {
                $updateData['empleado_id'] = $request->empleado_id;
            }
            if ($request->has('layout')) {
                $updateData['layout'] = $request->layout;
            }
            if ($request->has('line_style')) {
                $updateData['line_style'] = $request->line_style;
            }

            if (empty($updateData)) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NO_DATA',
                ], 400);
            }

            $updateData['edicion'] = now();

            DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->update($updateData);

            $newNode = DB::connection('portal_main')
                ->table('organigrama_nodes as o')
                ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
                ->select(
                    'o.id',
                    'o.parent_id',
                    'o.titulo_puesto',
                    'o.layout',
                    'o.line_style',
                    'o.empleado_id',
                    'e.nombre',
                    'e.paterno',
                    'e.materno',
                    'e.foto',
                    'e.puesto as puesto_actual'
                )
                ->where('o.id', $id)
                ->first();

            return response()->json([
                'status' => true,
                'code'   => 'UPDATED',
                'data'   => $newNode,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'error'  => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {

            $connection = DB::connection('portal_main');

            // 🔥 Primero obtener el nodo para saber portal y cliente
            $node = $connection
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->where('activo', 1)
                ->first();

            if (! $node) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NOT_FOUND',
                ], 404);
            }

            // 🔥 Traer solo nodos del mismo portal y cliente
            $nodes = $connection
                ->table('organigrama_nodes')
                ->where('id_portal', $node->id_portal)
                ->where('id_cliente', $node->id_cliente)
                ->where('activo', 1)
                ->get();

            $idsToDeactivate = [];

            $collectChildren = function ($nodes, $id, &$idsToDeactivate, $collectChildren) {
                $idsToDeactivate[] = $id;

                foreach ($nodes as $n) {
                    if ($n->parent_id == $id) {
                        $collectChildren($nodes, $n->id, $idsToDeactivate, $collectChildren);
                    }
                }
            };

            $collectChildren($nodes, $id, $idsToDeactivate, $collectChildren);

            $connection
                ->table('organigrama_nodes')
                ->whereIn('id', $idsToDeactivate)
                ->update([
                    'empleado_id' => null, // 🔥 liberar empleados
                    'activo'      => 0,
                    'edicion'     => now(),
                ]);

            return response()->json([
                'status' => true,
                'code'   => 'DELETED',
                'total'  => count($idsToDeactivate),
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'code'   => 'SERVER_ERROR',
            ], 500);
        }
    }

    public function removeEmployee($id)
    {
        try {

            $affected = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->where('activo', 1)
                ->update([
                    'empleado_id' => null,
                    'edicion'     => now(),
                ]);

            if ($affected === 0) {
                return response()->json([
                    'status' => false,
                    'code'   => 'NOT_FOUND',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'code'   => 'EMPLOYEE_REMOVED',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'status' => false,
                'code'   => 'SERVER_ERROR',
            ], 500);
        }
    }

    public function primerClienteConDatos(Request $request)
    {
        $idPortal  = $request->query('id_portal');
        $clientIds = $request->query('client_ids');

        if (! $idPortal) {
            return response()->json([
                'status'  => false,
                'message' => 'id_portal es requerido',
            ], 400);
        }

        // 🔥 Si llega un solo id, convertirlo a array
        if (! is_array($clientIds)) {
            $clientIds = $clientIds ? [$clientIds] : [];
        }

        if (empty($clientIds)) {
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
        $idPortal  = $request->query('id_portal');
        $idCliente = $request->query('id_cliente');

        if (! $idPortal || ! $idCliente) {
            return response()->json([
                'status'  => false,
                'message' => 'id_portal e id_cliente son requeridos',
            ], 400);
        }

        $empleados = DB::connection('portal_main')
            ->table('empleados as e')
            ->leftJoin('organigrama_nodes as n', function ($join) use ($idPortal, $idCliente) {
                $join->on('n.empleado_id', '=', 'e.id')
                    ->where('n.id_portal', $idPortal)
                    ->where('n.id_cliente', $idCliente)
                    ->where('n.activo', 1);
            })
            ->where('e.id_cliente', $idCliente)
            ->where('e.status', 1) // 🔥 SOLO ACTIVOS
            ->whereNull('n.id')    // 🔥 NO ASIGNADOS
            ->select(
                'e.id',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.foto',
                'e.departamento'
            )
            ->orderBy('e.departamento')
            ->orderBy('e.nombre')
            ->get();

        return response()->json([
            'status' => true,
            'data'   => $empleados,
        ]);
    }

    public function getRoot(Request $request)
    {
        $portalId  = $request->id_portal;
        $clienteId = $request->id_cliente;

        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as o')
            ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
            ->select(
                'o.id',
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
                DB::raw('EXISTS(
                SELECT 1 FROM organigrama_nodes o2
                WHERE o2.parent_id = o.id
                AND o2.activo = 1
            ) as has_children')
            )
            ->where('o.id_portal', $portalId)
            ->where('o.id_cliente', $clienteId)
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
        $parentId = $request->parent_id;

        $nodes = DB::connection('portal_main')
            ->table('organigrama_nodes as o')
            ->leftJoin('empleados as e', 'e.id', '=', 'o.empleado_id')
            ->select(
                'o.id',
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
                DB::raw('EXISTS(
                SELECT 1 FROM organigrama_nodes o2
                WHERE o2.parent_id = o.id
                AND o2.activo = 1
            ) as has_children')
            )
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
        $idPortal  = $request->query('id_portal');
        $idCliente = $request->query('id_cliente');
        $search    = $request->query('q');

        if (! $idPortal || ! $idCliente) {
            return response()->json([
                'status'  => false,
                'message' => 'id_portal e id_cliente son requeridos',
            ], 400);
        }

        $query = DB::connection('portal_main')
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
                'parent_chain' => $this->buildParentChain($node->parent_id, $idPortal, $idCliente),
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => $result,
        ]);
    }
    private function buildParentChain($parentId, $idPortal, $idCliente)
    {
        $chain = [];

        while ($parentId) {

            $parent = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $parentId)
                ->where('id_portal', $idPortal)
                ->where('id_cliente', $idCliente)
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

}
