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
                    'orden'         => 0,
                    'activo'        => 1,
                    'creacion'      => now(),
                    'edicion'       => now(),
                ]);

            return response()->json([
                'status' => true,
                'id'     => $id,
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
        try {

            $updateData = [];

            if ($request->has('titulo_puesto')) {
                $updateData['titulo_puesto'] = $request->titulo_puesto;
            }

            if ($request->has('parent_id')) {
                $updateData['parent_id'] = $request->parent_id;
            }

            if ($request->has('empleado_id')) {
                $updateData['empleado_id'] = $request->empleado_id;
            }

            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'code'    => 'NO_DATA',
                ], 400);
            }

            $updateData['edicion'] = now();

            $affected = DB::connection('portal_main')
                ->table('organigrama_nodes')
                ->where('id', $id)
                ->update($updateData);

            if ($affected === 0) {
                return response()->json([
                    'success' => false,
                    'code'    => 'NOT_UPDATED',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'code'    => 'UPDATED',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'code'    => 'SERVER_ERROR',
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {

            $connection = DB::connection('portal_main');

            // Obtener nodos activos
            $nodes = $connection
                ->table('organigrama_nodes')
                ->where('activo', 1)
                ->get();

            $idsToDeactivate = [];

            function collectChildren($nodes, $id, &$idsToDeactivate)
            {
                $idsToDeactivate[] = $id;

                foreach ($nodes as $node) {
                    if ($node->parent_id == $id) {
                        collectChildren($nodes, $node->id, $idsToDeactivate);
                    }
                }
            }

            collectChildren($nodes, $id, $idsToDeactivate);

            $total = count($idsToDeactivate);

            if ($total === 0) {
                return response()->json([
                    'success' => false,
                    'code'    => 'NOT_FOUND',
                ], 404);
            }

            $connection
                ->table('organigrama_nodes')
                ->whereIn('id', $idsToDeactivate)
                ->update([
                    'activo'  => 0,
                    'edicion' => now(),
                ]);

            return response()->json([
                'success' => true,
                'code'    => 'DELETED',
                'total'   => $total,
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'code'    => 'SERVER_ERROR',
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
                    'success' => false,
                    'code'    => 'NOT_FOUND',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'code'    => 'EMPLOYEE_REMOVED',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'success' => false,
                'code'    => 'SERVER_ERROR',
            ], 500);
        }
    }

}
