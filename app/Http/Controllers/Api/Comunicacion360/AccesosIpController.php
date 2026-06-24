<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccesosIpController extends Controller
{
    public function index(Request $request, int $id)
    {
        $empleado = $this->obtenerEmpleado($id);

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado.',
                'data'    => [],
            ], 404);
        }

        $ips = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->select([
                'id',
                'ip',
                'descripcion',
                'activo',
                'creacion',
                'edicion',
            ])
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->orderByDesc('activo')
            ->orderBy('ip')
            ->get()
            ->map(fn($item) => $this->formatearIp($item))
            ->values();

        return response()->json([
            'ok'      => true,
            'message' => 'IPs autorizadas obtenidas correctamente.',
            'data'    => $ips,
        ]);
    }
    public function guardarIp(Request $request, int $id)
    {
        $validator = validator($request->all(), [
            'ip'          => ['required', 'string', 'max:45', 'ip'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo'      => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $empleado = $this->obtenerEmpleado($id);

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        $ip = trim($request->input('ip'));

        $existe = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->where('ip', $ip)
            ->exists();

        if ($existe) {
            return response()->json([
                'ok'      => false,
                'message' => 'Esta IP ya está registrada para el empleado.',
            ], 409);
        }

        $ipId = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->insertGetId([
                'id_portal'   => (int) $empleado->id_portal,
                'id_cliente'  => (int) $empleado->id_cliente,
                'id_empleado' => (int) $empleado->id,
                'ip'          => $ip,
                'descripcion' => $request->input('descripcion'),
                'activo'      => $request->boolean('activo', true) ? 1 : 0,
                'creacion'    => now(),
                'edicion'     => now(),
            ]);

        $registro = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->select(['id', 'ip', 'descripcion', 'activo', 'creacion', 'edicion'])
            ->where('id', $ipId)
            ->first();

        return response()->json([
            'ok'      => true,
            'message' => 'IP autorizada registrada correctamente.',
            'data'    => $this->formatearIp($registro),
        ], 201);
    }
    public function actualizarIp(Request $request, int $id, int $ipId)
    {
        $validator = validator($request->all(), [
            'ip'          => ['required', 'string', 'max:45', 'ip'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo'      => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'Datos inválidos.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $empleado = $this->obtenerEmpleado($id);

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        $registro = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id', $ipId)
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->first();

        if (! $registro) {
            return response()->json([
                'ok'      => false,
                'message' => 'IP autorizada no encontrada para este empleado.',
            ], 404);
        }

        $ip = trim($request->input('ip'));

        $duplicado = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->where('ip', $ip)
            ->where('id', '<>', $ipId)
            ->exists();

        if ($duplicado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Ya existe otra IP igual registrada para este empleado.',
            ], 409);
        }

        DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id', $ipId)
            ->update([
                'ip'          => $ip,
                'descripcion' => $request->input('descripcion'),
                'activo'      => $request->boolean('activo', true) ? 1 : 0,
                'edicion'     => now(),
            ]);

        $actualizado = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->select(['id', 'ip', 'descripcion', 'activo', 'creacion', 'edicion'])
            ->where('id', $ipId)
            ->first();

        return response()->json([
            'ok'      => true,
            'message' => 'IP autorizada actualizada correctamente.',
            'data'    => $this->formatearIp($registro),
        ]);
    }
    public function eliminarIp(int $id, int $ipId)
    {
        $empleado = DB::connection('portal_main')
            ->table('empleados')
            ->select(['id', 'id_portal', 'id_cliente'])
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('eliminado', 0)
                    ->orWhereNull('eliminado');
            })
            ->first();

        if (! $empleado) {
            return response()->json([
                'ok'      => false,
                'message' => 'Empleado no encontrado.',
            ], 404);
        }

        $eliminadas = DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id', $ipId)
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->delete();

        if (! $eliminadas) {
            return response()->json([
                'ok'      => false,
                'message' => 'La IP autorizada no existe.',
            ], 404);
        }

        return response()->json([
            'ok'      => true,
            'message' => 'IP autorizada eliminada correctamente.',
        ]);
    }

    private function obtenerEmpleado(int $id)
    {
        return DB::connection('portal_main')
            ->table('empleados')
            ->select(['id', 'id_portal', 'id_cliente'])
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('eliminado', 0)
                    ->orWhereNull('eliminado');
            })
            ->first();
    }

    private function obtenerIpEmpleado($empleado, int $ipId)
    {
        return DB::connection('portal_main')
            ->table('empleado_ips_autorizadas')
            ->where('id', $ipId)
            ->where('id_portal', (int) $empleado->id_portal)
            ->where('id_cliente', (int) $empleado->id_cliente)
            ->where('id_empleado', (int) $empleado->id)
            ->first();
    }

    private function formatearIp($item): array
    {
        return [
            'id'          => (int) $item->id,
            'ip'          => $item->ip,
            'descripcion' => $item->descripcion,
            'activo'      => (bool) $item->activo,
            'creacion'    => $item->creacion,
            'edicion'     => $item->edicion,
        ];
    }
}
