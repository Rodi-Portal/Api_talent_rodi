<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorMetodo;
use Illuminate\Http\Request;

class ChecadorMetodoController extends Controller
{
    public function index(Request $request)
    {
        $soloActivos = $request->boolean('solo_activos', true);

        $query = ChecadorMetodo::query();

        if ($soloActivos) {
            $query->where('activo', 1);
        }

        $metodos = $query->orderBy('id')->get();

        return response()->json([
            'ok' => true,
            'data' => $metodos
        ]);
    }
}