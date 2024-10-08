<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    public function index()
    {
        return response()->json(['message' => 'Hello from WhatsApp']);
    }

    public function sendMessage(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone' => 'required|string',
            'template' => 'required|string',
        ]);

        // Obtén el número de teléfono y el nombre de la plantilla de la solicitud
        $phone = $validated['phone'];
        $template = $validated['template'];

        // Define el URL del endpoint de la API de Facebook
        $url = 'https://graph.facebook.com/v20.0/391916820677600/messages';

        // Define el token de autorización (se recomienda almacenarlo en .env)
        $token = env('FACEBOOK_ACCESS_TOKEN');

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'en_US'],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data' => $response->json(),
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    // mensaje   hacia  el cliente  cuando   el reclutador   registra  un movimiento
    public function sendMessage_movimiento_aspirante(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone' => 'required|string',
            'template' => 'required|string',
            'nombre_cliente' => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante' => 'nullable|string',
            'telefono' => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone = $validated['phone'];
        $template = $validated['template'];
        $nombre_cliente = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante = $validated['vacante'] ?? '';
        $telefono = $validated['telefono'] ?? '';

        // Define el URL del endpoint de la API de Facebook
        $url = 'https://graph.facebook.com/v20.0/391916820677600/messages';

        // Define el token de autorización (se recomienda almacenarlo en .env)
        $token = env('FACEBOOK_ACCESS_TOKEN');

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                        ],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [

                            ['type' => 'text', 'text' => $nombre_aspirante],
                            ['type' => 'text', 'text' => $vacante],

                        ],
                    ],
                ],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data' => $response->json(),
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    // mensaje  hacia  el cliente  cuando   el reclutador  sube  un comentario
    public function sendMessage_comentario_reclu(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone' => 'required|string',
            'template' => 'required|string',
            'nombre_cliente' => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante' => 'nullable|string',
            'telefono' => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone = $validated['phone'];
        $template = $validated['template'];
        $nombre_cliente = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante = $validated['vacante'] ?? '';
        $telefono = $validated['telefono'] ?? '';

        // Define el URL del endpoint de la API de Facebook
        $url = 'https://graph.facebook.com/v20.0/391916820677600/messages';

        // Define el token de autorización (se recomienda almacenarlo en .env)
        $token = env('FACEBOOK_ACCESS_TOKEN');

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                        ],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [

                            ['type' => 'text', 'text' => $vacante],
                            ['type' => 'text', 'text' => $nombre_aspirante],

                        ],
                    ],
                ],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data' => $response->json(),
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    public function sendMessage_comentario_cliente(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone' => 'required|string',
            'template' => 'required|string',
            'nombre_reclu' => 'nullable|string',
            'nombre_cliente' => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante' => 'nullable|string',
            'telefono' => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone = $validated['phone'];
        $template = $validated['template'];
        $nombre_reclutador = $validated['nombre_reclu'] ?? '';

        $nombre_cliente = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante = $validated['vacante'] ?? '';
        $telefono = $validated['telefono'] ?? '';

        // Define el URL del endpoint de la API de Facebook
        $url = 'https://graph.facebook.com/v20.0/391916820677600/messages';

        // Define el token de autorización (se recomienda almacenarlo en .env)
        $token = env('FACEBOOK_ACCESS_TOKEN');

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_reclutador],
                        ],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                            ['type' => 'text', 'text' => $nombre_aspirante],
                            ['type' => 'text', 'text' => $vacante],
                           

                        ],
                    ],
                ],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data' => $response->json(),
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    public function sendMessage_requisicion_cliente(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone' => 'required|string',
            'template' => 'required|string',
            'nombre_gerente' => 'nullable|string',
            'nombre_cliente' => 'nullable|string',
            'vacante' => 'nullable|string',
            'telefono' => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone = $validated['phone'];
        $template = $validated['template'];
        $nombre_gerente = $validated['nombre_gerente'] ?? '';
        $nombre_cliente = $validated['nombre_cliente'] ?? '';
        $vacante = $validated['vacante'] ?? '';
        $telefono = $validated['telefono'] ?? '';

        // Define el URL del endpoint de la API de Facebook
        $url = 'https://graph.facebook.com/v20.0/391916820677600/messages';

        // Define el token de autorización (se recomienda almacenarlo en .env)
        $token = env('FACEBOOK_ACCESS_TOKEN');

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type' => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_gerente],
                        ],
                    ],
                    [
                        'type' => 'body',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                        ],
                    ],
                ],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data' => $response->json(),
            ]);
        } else {
            return response()->json([
                'status' => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

}