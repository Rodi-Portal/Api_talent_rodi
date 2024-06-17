<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ClienteRodi;

class ApiClientesController extends Controller
{
    public function VerificarCliente(Request $request)
    {
        // Validar los datos recibidos
        $request->validate([
            'nombre' => 'required|string',
            'clave' => 'required|string'
        ]);

        $nombre = $request->input('nombre');
        $clave = $request->input('clave');

        // Verificar si el cliente existe en la tabla actual
        $client = ClienteRodi::where('nombre', $nombre)->where('clave', $clave)->first();

        if ($client) {
            // Cliente encontrado, devolver el ID del cliente
            return response()->json([
                'success' => true,
                'client_id' => $client->id
            ]);
        } else {
            // Cliente no encontrado
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
            ]);
        }
    }
}