<?php

namespace App\Http\Controllers;

use App\Models\ProyectosHistorial;
use Illuminate\Http\Request;

class ProyectosHistorialController extends Controller
{
    public function getFields()
    {
        // Obtener un campo de un modelo específico
        $fields = ProyectosHistorial::first(); // Obtiene el primer registro

        if (!$fields) {
            return response()->json(['message' => 'No records found'], 404);
        }

        // Devolver los campos como un array
        return response()->json($fields->toArray(), 200);
    }
    public function getAllFields()
    {
        $fields = ProyectosHistorial::all(); // Obtener todos los registros

        return response()->json($fields, 200);
    }


    public function getproyectosPorCliente(Request $request)
    {
        // Inicializar la consulta base
        $query = ProyectosHistorial::query();

        // Aplicar filtro para id_usuario = 1
        $query->where('id_usuario', 1);

        // Aplicar filtros opcionales si se proporcionan
        if ($request->has('id_usuario_cliente')) {
            $query->where('id_usuario_cliente', $request->input('id_usuario_cliente'));
        }

        if ($request->has('id_subcliente')) {
            $query->where('id_usuario_subcliente', $request->input('id_subcliente'));
        }

        if ($request->has('id_cliente')) {
            $query->where('id_cliente', $request->input('id_cliente'));
        }

        // Obtener los resultados
        $fields = $query->get();

        // Devolver los resultados
        return response()->json($fields, 200);
    }
}