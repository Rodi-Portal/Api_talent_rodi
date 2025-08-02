<?php
namespace App\Http\Controllers;

use App\Models\ConfiguracionColumnas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionColumnasController extends Controller
{
    /**
     * Obtener configuración de columnas por usuario, portal, cliente y módulo
     */
    public function obtener(Request $request)
    {
        $request->validate([
            'id_usuario'   => 'required|integer',
            'id_portal'    => 'required|integer',
            'id_cliente'   => 'required|array|min:1', // <-- ahora es array
            'id_cliente.*' => 'integer',              // <-- cada elemento debe ser entero
            'modulo'       => 'required|string|in:mensajeria,empleados,former',
        ]);

        $configs = ConfiguracionColumnas::where('id_usuario', $request->id_usuario)
            ->where('id_portal', $request->id_portal)
            ->whereIn('id_cliente', $request->id_cliente) // <-- whereIn para el array
            ->where('modulo', $request->modulo)
            ->get();

        if ($configs->isEmpty()) {
            return response()->json(['columnas' => []], 200);
        }

        // Unión de todas las columnas encontradas (sin duplicados, preservando orden de aparición)
        $columnas = $configs->reduce(function ($carry, $cfg) {
            $cols = is_array($cfg->columnas) ? $cfg->columnas : [];
            foreach ($cols as $col) {
                if (! in_array($col, $carry, true)) {
                    $carry[] = $col;
                }
            }
            return $carry;
        }, []);

        return response()->json([
            'columnas' => $columnas,
        ], 200);
    }

    /**
     * Guardar o actualizar configuración de columnas
     */

    public function guardar(Request $request)
    {
        $request->validate([
            'id_usuario'   => 'required|integer',
            'id_portal'    => 'required|integer',
            'id_cliente'   => 'required|array|min:1', // <— arreglo obligatorio
            'id_cliente.*' => 'integer|distinct',     // <— cada elemento entero y único
            'modulo'       => 'required|string|in:mensajeria,empleados,former',
            'columnas'     => 'required|array|min:1',
            // 'columnas.*'  => 'string' // descomenta si quieres validar el tipo de cada columna
        ]);

        $ids        = $request->id_cliente;
        $resultados = [];

        DB::transaction(function () use ($request, $ids, &$resultados) {
            foreach ($ids as $idCli) {
                // No sobreescribir 'creacion' si ya existe el registro
                $config = ConfiguracionColumnas::firstOrNew([
                    'id_usuario' => $request->id_usuario,
                    'id_portal'  => $request->id_portal,
                    'id_cliente' => $idCli,
                    'modulo'     => $request->modulo,
                ]);

                $config->columnas = $request->columnas; // reemplaza; si quieres unir, te paso variante abajo
                $config->edicion  = now();

                if (! $config->exists) {
                    $config->creacion = now();
                }

                $config->save();

                $resultados[] = [
                    'id_cliente' => $idCli,
                    'columnas'   => $config->columnas,
                ];
            }
        });

        return response()->json([
            'mensaje'    => 'Configuración guardada exitosamente',
            'resultados' => $resultados, // detalle por cliente
        ], 200);
    }

}