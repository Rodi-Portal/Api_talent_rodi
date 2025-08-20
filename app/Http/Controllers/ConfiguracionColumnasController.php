<?php
namespace App\Http\Controllers;

use App\Models\ConfiguracionColumnas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;


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
            'id_cliente' => 'required', // puede venir int o array
            'modulo'     => 'required|string|in:mensajeria,empleados,former',
        ]);

        // Normaliza a array aunque venga como número único
        $clientes = Arr::wrap($request->id_cliente);

        $configs = ConfiguracionColumnas::where('id_usuario', $request->id_usuario)
            ->where('id_portal', $request->id_portal)
            ->whereIn('id_cliente', $clientes)
            ->where('modulo', $request->modulo)
            ->get();

        if ($configs->isEmpty()) {
            return response()->json(['columnas' => []], 200);
        }

        // Une todas las columnas, sin duplicados y preservando orden
        $columnas = $configs->reduce(function ($carry, $cfg) {
            $cols = $cfg->columnas;

            // Fallback por si olvidaste el cast o el dato viene como string JSON
            if (is_string($cols)) {
                $decoded = json_decode($cols, true);
                $cols    = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            } elseif ($cols instanceof \Illuminate\Support\Collection) {
                $cols = $cols->all();
            } elseif (! is_array($cols)) {
                $cols = [];
            }

            foreach ($cols as $col) {
                if (! in_array($col, $carry, true)) {
                    $carry[] = $col;
                }
            }
            return $carry;
        }, []);

        return response()->json(['columnas' => $columnas], 200);
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
