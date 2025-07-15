<?php
namespace App\Http\Controllers;

use App\Models\ConfiguracionColumnas;
use Illuminate\Http\Request;

class ConfiguracionColumnasController extends Controller
{
    /**
     * Obtener configuración de columnas por usuario, portal, cliente y módulo
     */
    public function obtener(Request $request)
    {
        $request->validate([
            'id_usuario' => 'required|integer',
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',
            'modulo'     => 'required|string|in:mensajeria,empleados,former',
        ]);

        $config = ConfiguracionColumnas::where('id_usuario', $request->id_usuario)
            ->where('id_portal', $request->id_portal)
            ->where('id_cliente', $request->id_cliente)
            ->where('modulo', $request->modulo)
            ->first();

        if (! $config) {
            return response()->json(['columnas' => []], 200); // Devuelve vacío si no hay
        }

        return response()->json([
            'columnas' => $config->columnas,
        ], 200);
    }

    /**
     * Guardar o actualizar configuración de columnas
     */
    public function guardar(Request $request)
    {
        $request->validate([
            'id_usuario' => 'required|integer',
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',
            'modulo'     => 'required|string|in:mensajeria,empleados,former',
            'columnas'   => 'required|array',
        ]);

        $config = ConfiguracionColumnas::updateOrCreate(
            [
                'id_usuario' => $request->id_usuario,
                'id_portal'  => $request->id_portal,
                'id_cliente' => $request->id_cliente,
                'modulo'     => $request->modulo,
            ],
            [
                'columnas' => $request->columnas,
                'edicion'  => now(),
                'creacion' => now(), // Solo será tomado si es una creación
            ]
        );

        return response()->json([
            'mensaje'  => 'Configuración guardada exitosamente',
            'columnas' => $config->columnas,
        ], 200);
    }
}
