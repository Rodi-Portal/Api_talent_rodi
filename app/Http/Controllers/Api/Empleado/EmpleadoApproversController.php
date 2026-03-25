<?php

namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Empleado;
use App\Models\OrganigramaNode;

class EmpleadoApproversController extends Controller
{
    public function index(Request $request)
    {
        $employee = $request->user();

        if (!$employee) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        $default = [];
        $available = [];

        /* =========================
           NODO DEL EMPLEADO
        ========================= */

        $node = OrganigramaNode::where('empleado_id', $employee->id)
            ->where('activo', 1)
            ->first();

        if (!$node) {
            return response()->json([
                'default' => [],
                'available' => []
            ]);
        }

        /* =========================
           JEFE DIRECTO
        ========================= */

        $parent = $node->parent;

        if ($parent && $parent->empleado_id) {

            $jefe = Empleado::find($parent->empleado_id);

            if ($jefe) {

                $default[] = [
                    'id' => $jefe->id,
                    'nombre' => trim(
                        ($jefe->nombre ?? '') . ' ' .
                        ($jefe->paterno ?? '') . ' ' .
                        ($jefe->materno ?? '')
                    ),
                    'puesto' => $parent->titulo_puesto
                ];
            }
        }

        /* =========================
           CADENA JERÁRQUICA
        ========================= */

        $current = $parent;

        while ($current) {

            $current = $current->parent;

            if (!$current) {
                break;
            }

            if (!$current->empleado_id) {
                continue;
            }

            $emp = Empleado::find($current->empleado_id);

            if (!$emp) {
                continue;
            }

            $available[] = [
                'id' => $emp->id,
                'nombre' => trim(
                    ($emp->nombre ?? '') . ' ' .
                    ($emp->paterno ?? '') . ' ' .
                    ($emp->materno ?? '')
                ),
                'puesto' => $current->titulo_puesto
            ];
        }

        /* =========================
           RESPUESTA
        ========================= */

        return response()->json([
            'default' => $default,
            'available' => $available
        ]);
    }
}