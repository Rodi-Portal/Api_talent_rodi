<?php
namespace App\Http\Controllers\Api\Comunicacion360\Checador;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion360\Checador\ChecadorUbicacion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class ChecadorQrController extends Controller
{
    public function generar(Request $request)
    {
        $data = $request->validate([
            'ubicacion_id' => ['required', 'integer'],
        ]);

        $ubicacion = ChecadorUbicacion::where('id', $data['ubicacion_id'])
            ->where('activa', 1)
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación no existe o está inactiva.',
            ], 404);
        }

        $payload = [
            'type'         => 'checador_qr',
            'ubicacion_id' => $ubicacion->id,
            'id_portal'    => $ubicacion->id_portal,
            'id_cliente'   => $ubicacion->id_cliente,
            'generated_at' => now()->toDateTimeString(),
            'expires_at'   => now()->addSeconds(60)->toDateTimeString(),
        ];

        $token = Crypt::encryptString(json_encode($payload));

        return response()->json([
            'ok'                 => true,
            'token'              => $token,
            'expires_in_seconds' => 60,
            'ubicacion'          => [
                'id'     => $ubicacion->id,
                'nombre' => $ubicacion->nombre,
            ],
        ]);
    }

    public function validar(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        try {
            $payload = json_decode(Crypt::decryptString($data['token']), true);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'QR inválido.',
            ], 422);
        }

        if (($payload['type'] ?? null) !== 'checador_qr') {
            return response()->json([
                'ok'      => false,
                'message' => 'El QR no pertenece al checador.',
            ], 422);
        }

        if (Carbon::parse($payload['expires_at'])->isPast()) {
            return response()->json([
                'ok'      => false,
                'message' => 'El QR expiró.',
            ], 410);
        }

        $ubicacion = ChecadorUbicacion::where('id', $payload['ubicacion_id'])
            ->where('id_portal', $payload['id_portal'])
            ->where('id_cliente', $payload['id_cliente'])
            ->where('activa', 1)
            ->first();

        if (! $ubicacion) {
            return response()->json([
                'ok'      => false,
                'message' => 'La ubicación del QR no existe o está inactiva.',
            ], 404);
        }

        return response()->json([
            'ok'         => true,
            'qr_valido'  => true,
            'ubicacion'  => [
                'id'           => $ubicacion->id,
                'nombre'       => $ubicacion->nombre,
                'latitud'      => $ubicacion->latitud,
                'longitud'     => $ubicacion->longitud,
                'radio_metros' => $ubicacion->radio_metros,
            ],
            'expires_at' => $payload['expires_at'],
        ]);
    }
}
