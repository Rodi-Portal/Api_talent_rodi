<?php

namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use App\Support\GeoHelper;
use Illuminate\Http\Request;

class ChecadorValidacionController extends Controller
{
    public function validarUbicacion(Request $request)
    {
        $data = $request->validate([
            'ubicacion_id' => ['required', 'integer'],
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $ubicacion = ChecadorUbicacion::where('id', $data['ubicacion_id'])
            ->where('activa', 1)
            ->firstOrFail();

        $distancia = GeoHelper::distanceInMeters(
            $data['lat'],
            $data['lng'],
            $ubicacion->latitud,
            $ubicacion->longitud
        );

        $dentro = $distancia <= (float) $ubicacion->radio_metros;

        return response()->json([
            'ok' => true,
            'dentro' => $dentro,
            'distancia_metros' => round($distancia, 2),
            'radio_metros' => (float) $ubicacion->radio_metros,
            'ubicacion' => [
                'id' => $ubicacion->id,
                'nombre' => $ubicacion->nombre,
            ],
        ]);
    }
}