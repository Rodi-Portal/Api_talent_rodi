<?php

namespace App\Http\Controllers\Api\Checador;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;

class ChecadorUbicacionesController extends Controller
{
    public function index(Request $request)
    {
        $idPortal = $request->input('id_portal');
        $idCliente = $request->input('id_cliente');

        $query = ChecadorUbicacion::query()
            ->where('id_portal', $idPortal);

        if ($idCliente) {
            $query->where('id_cliente', $idCliente);
        }

        $ubicaciones = $query
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $ubicaciones,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal' => 'required|integer',
            'id_cliente' => 'required|integer',
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'tipo_zona' => 'required|in:circle,polygon',
            'latitud' => 'required_if:tipo_zona,circle|nullable|numeric',
            'longitud' => 'required_if:tipo_zona,circle|nullable|numeric',
            'radio_metros' => 'required_if:tipo_zona,circle|nullable|integer|min:10',
            'polygon_json' => 'nullable',
            'direccion' => 'nullable|string|max:255',
            'referencia' => 'nullable|string|max:255',
            'activa' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $ubicacion = ChecadorUbicacion::create([
            'id_portal' => $request->id_portal,
            'id_cliente' => $request->id_cliente,
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            'tipo_zona' => $request->tipo_zona ?? 'circle',
            'latitud' => $request->latitud,
            'longitud' => $request->longitud,
            'radio_metros' => $request->radio_metros ?? 100,
            'polygon_json' => $request->polygon_json,
            'direccion' => $request->direccion,
            'referencia' => $request->referencia,
            'activa' => $request->activa ?? 1,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Ubicación creada correctamente.',
            'id' => $ubicacion->id,
        ]);
    }

    public function update(Request $request, $id)
    {
        $ubicacion = ChecadorUbicacion::query()
            ->where('id', $id)
            ->first();

        if (!$ubicacion) {
            return response()->json([
                'ok' => false,
                'message' => 'Ubicación no encontrada.',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string',
            'tipo_zona' => 'required|in:circle,polygon',
            'latitud' => 'required_if:tipo_zona,circle|nullable|numeric',
            'longitud' => 'required_if:tipo_zona,circle|nullable|numeric',
            'radio_metros' => 'required_if:tipo_zona,circle|nullable|integer|min:10',
            'polygon_json' => 'nullable',
            'direccion' => 'nullable|string|max:255',
            'referencia' => 'nullable|string|max:255',
            'activa' => 'nullable|integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $ubicacion->update([
            'nombre' => $request->nombre,
            'descripcion' => $request->descripcion,
            'tipo_zona' => $request->tipo_zona ?? 'circle',
            'latitud' => $request->latitud,
            'longitud' => $request->longitud,
            'radio_metros' => $request->radio_metros ?? 100,
            'polygon_json' => $request->polygon_json,
            'direccion' => $request->direccion,
            'referencia' => $request->referencia,
            'activa' => $request->activa ?? 1,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Ubicación actualizada correctamente.',
        ]);
    }

    public function destroy($id)
    {
        $ubicacion = ChecadorUbicacion::query()
            ->where('id', $id)
            ->first();

        if (!$ubicacion) {
            return response()->json([
                'ok' => false,
                'message' => 'Ubicación no encontrada.',
            ], 404);
        }

        $ubicacion->update([
            'activa' => 0,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Ubicación desactivada correctamente.',
        ]);
    }
}