<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\ClienteTalent;
use App\Models\Empleado;
use App\Models\Empleados\EmpleadoCambioSucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpleadoSucursalController extends Controller
{
    public function sucursalesPermitidas(int $idUsuario): JsonResponse
    {
        $sucursales = ClienteTalent::query()
            ->select([
                'cliente.id',
                DB::raw('TRIM(cliente.nombre) AS nombre'),
            ])
            ->join(
                'usuario_permiso',
                'usuario_permiso.id_cliente',
                '=',
                'cliente.id'
            )
            ->where('usuario_permiso.id_usuario', $idUsuario)
            ->where('cliente.status', 1)
            ->where('cliente.eliminado', 0)
            ->distinct()
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $sucursales,
        ]);
    }
    public function cambiarSucursal(Request $request, int $idEmpleado)
    {
        $validated = $request->validate([
            'id_usuario'  => 'required|integer',
            'id_cliente' => 'required|integer',
            'motivo'      => 'nullable|string|max:1000',
        ]);

        $empleado = Empleado::find($idEmpleado);

        if (! $empleado) {
            return response()->json([
                'success' => false,
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        $permitida = DB::connection('portal_main')
            ->table('usuario_permiso')
            ->where('id_usuario', $validated['id_usuario'])
            ->where('id_cliente', $validated['id_cliente'])
            ->exists();

        if (! $permitida) {
            return response()->json([
                'success' => false,
                'message' => 'La sucursal seleccionada no está permitida para este usuario.',
            ], 403);
        }

        if ((int) $empleado->id_cliente === (int) $validated['id_cliente']) {
            return response()->json([
                'success' => false,
                'message' => 'El colaborador ya pertenece a esa sucursal.',
            ], 422);
        }

        DB::connection('portal_main')->transaction(function () use (
            $empleado,
            $validated,
            $request
        ) {

            $idClienteAnterior = $empleado->id_cliente;

            $empleado->id_cliente = $validated['id_cliente'];
            $empleado->edicion    = now();
            $empleado->save();

            EmpleadoCambioSucursal::create([
                'id_portal'           => $empleado->id_portal,
                'id_empleado'         => $empleado->id,
                'id_cliente_anterior' => $idClienteAnterior,
                'id_cliente_nuevo'    => $validated['id_cliente'],
                'id_usuario'          => $validated['id_usuario'],
                'motivo'              => $validated['motivo'] ?? null,
                'ip'                  => $request->ip(),
                'user_agent'          => substr(
                    (string) $request->userAgent(),
                    0,
                    500
                ),
                'created_at'          => now(),
            ]);
        });

        $sucursal = ClienteTalent::find($validated['id_cliente']);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada correctamente.',
            'data'    => [
                'id_cliente'     => $sucursal->id,
                'nombre_cliente' => trim($sucursal->nombre),
            ],
        ]);
    }
}
