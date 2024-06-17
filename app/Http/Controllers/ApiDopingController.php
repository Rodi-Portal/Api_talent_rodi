<?php

namespace App\Http\Controllers;

use App\Models\Doping;
use Illuminate\Http\Request;

class ApiDopingController extends Controller
{
    /**
     * Actualiza el campo qr_token de un registro de doping.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateQrToken(Request $request, $id)
    {
        // Validar el token QR proporcionado
        $request->validate([
            'qr_token' => 'required|string|size:16',
        ]);

        try {
            // Buscar el registro de doping por su id
            $doping = Doping::findOrFail($id);

            // Obtener el token QR de la solicitud
            $qrToken = $request->input('qr_token');

            // Actualizar el campo qr_token
            $doping->qr_token = $qrToken;
            $doping->save();

            // Retornar una respuesta JSON con el nuevo token QR
            return response()->json(['qr_token' => $qrToken], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al actualizar el QR token: ' . $e->getMessage()], 500);
        }
    }
}