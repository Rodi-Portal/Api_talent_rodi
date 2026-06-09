<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Services\Checador\ChecadaDispositivoService;
use Illuminate\Http\Request;

class ChecadaDispositivoController extends Controller
{
    public function store(Request $request)
    {
        $resultado = app(ChecadaDispositivoService::class)
            ->registrar($request);

        return response()->json(
            $resultado,
            $resultado['status'] ?? 200
        );
    }
}