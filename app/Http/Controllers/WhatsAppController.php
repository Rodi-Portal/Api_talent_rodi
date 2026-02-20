<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            'phone'    => 'required|string',
            'template' => 'required|string',
        ]);

        // Obtén el número de teléfono y el nombre de la plantilla de la solicitud
        $phone    = $validated['phone'];
        $template = $validated['template'];

        // Define el URL del endpoint de la API de Facebook
        // ✅ Configuración desde services.php
        $token         = config('services.facebook.access_token');
        $phoneNumberId = config('services.facebook.phone_number_id');
        $baseUrl       = config('services.facebook.base_url');
        if (! $token || ! $phoneNumberId || ! $baseUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configuración de WhatsApp incompleta',
            ], 500);
        }
        // ✅ URL dinámica
        $url = "{$baseUrl}/{$phoneNumberId}/messages";

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'     => $template,
                'language' => ['code' => 'en_US'],
            ],
        ];

        // Realiza la solicitud POST usando Http de Laravel
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data'   => $response->json(),
            ]);
        } else {
            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    // mensaje   hacia  el cliente  cuando   el reclutador   registra  un movimiento
    public function sendMessage_movimiento_aspirante(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone'            => 'required|string',
            'template'         => 'required|string',
            'nombre_cliente'   => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante'          => 'nullable|string',
            'telefono'         => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone            = $validated['phone'];
        $template         = $validated['template'];
        $nombre_cliente   = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante          = $validated['vacante'] ?? '';
        $telefono         = $validated['telefono'] ?? '';

        $token         = config('services.facebook.access_token');
        $phoneNumberId = config('services.facebook.phone_number_id');
        $baseUrl       = config('services.facebook.base_url');
        if (! $token || ! $phoneNumberId || ! $baseUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configuración de WhatsApp incompleta',
            ], 500);
        }
        // ✅ URL dinámica
        $url = "{$baseUrl}/{$phoneNumberId}/messages";

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type'       => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                        ],
                    ],
                    [
                        'type'       => 'body',
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
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data'   => $response->json(),
            ]);
        } else {
            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    // mensaje  hacia  el cliente  cuando   el reclutador  sube  un comentario
    public function sendMessage_comentario_reclu(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone'            => 'required|string',
            'template'         => 'required|string',
            'nombre_cliente'   => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante'          => 'nullable|string',
            'telefono'         => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone            = $validated['phone'];
        $template         = $validated['template'];
        $nombre_cliente   = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante          = $validated['vacante'] ?? '';
        $telefono         = $validated['telefono'] ?? '';

        $token         = config('services.facebook.access_token');
        $phoneNumberId = config('services.facebook.phone_number_id');
        $baseUrl       = config('services.facebook.base_url');
        if (! $token || ! $phoneNumberId || ! $baseUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configuración de WhatsApp incompleta',
            ], 500);
        }
        // ✅ URL dinámica
        $url = "{$baseUrl}/{$phoneNumberId}/messages";

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type'       => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_cliente],
                        ],
                    ],
                    [
                        'type'       => 'body',
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
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data'   => $response->json(),
            ]);
        } else {
            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    public function sendMessage_comentario_cliente(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone'            => 'required|string',
            'template'         => 'required|string',
            'nombre_reclu'     => 'nullable|string',
            'nombre_cliente'   => 'nullable|string',
            'nombre_aspirante' => 'nullable|string',
            'vacante'          => 'nullable|string',
            'telefono'         => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone             = $validated['phone'];
        $template          = $validated['template'];
        $nombre_reclutador = $validated['nombre_reclu'] ?? '';

        $nombre_cliente   = $validated['nombre_cliente'] ?? '';
        $nombre_aspirante = $validated['nombre_aspirante'] ?? '';
        $vacante          = $validated['vacante'] ?? '';
        $telefono         = $validated['telefono'] ?? '';

        $token         = config('services.facebook.access_token');
        $phoneNumberId = config('services.facebook.phone_number_id');
        $baseUrl       = config('services.facebook.base_url');
        if (! $token || ! $phoneNumberId || ! $baseUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configuración de WhatsApp incompleta',
            ], 500);
        }
        // ✅ URL dinámica
        $url = "{$baseUrl}/{$phoneNumberId}/messages";

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type'       => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_reclutador],
                        ],
                    ],
                    [
                        'type'       => 'body',
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
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data'   => $response->json(),
            ]);
        } else {
            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

    public function sendMessage_requisicion_cliente(Request $request)
    {
        // Valida los datos de entrada
        $validated = $request->validate([
            'phone'          => 'required|string',
            'template'       => 'required|string',
            'nombre_gerente' => 'nullable|string',
            'nombre_cliente' => 'nullable|string',
            'vacante'        => 'nullable|string',
            'telefono'       => 'nullable|string',
        ]);

        // Obtén los datos de la solicitud
        $phone          = $validated['phone'];
        $template       = $validated['template'];
        $nombre_gerente = $validated['nombre_gerente'] ?? '';
        $nombre_cliente = $validated['nombre_cliente'] ?? '';
        $vacante        = $validated['vacante'] ?? '';
        $telefono       = $validated['telefono'] ?? '';

        $token         = config('services.facebook.access_token');
        $phoneNumberId = config('services.facebook.phone_number_id');
        $baseUrl       = config('services.facebook.base_url');
        if (! $token || ! $phoneNumberId || ! $baseUrl) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configuración de WhatsApp incompleta',
            ], 500);
        }
        // ✅ URL dinámica
        $url = "{$baseUrl}/{$phoneNumberId}/messages";

        // Define el payload de la solicitud
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $phone,
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => 'es_MX'],
                'components' => [
                    [
                        'type'       => 'header',
                        'parameters' => [
                            ['type' => 'text', 'text' => $nombre_gerente],
                        ],
                    ],
                    [
                        'type'       => 'body',
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
            'Content-Type'  => 'application/json',
        ])->post($url, $payload);

        // Verifica la respuesta
        if ($response->successful()) {
            return response()->json([
                'status' => 'success',
                'data'   => $response->json(),
            ]);
        } else {
            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error', 'Error desconocido'),
            ], $response->status());
        }
    }

//notificacion del modulo empleados  por  whatsAPP
    /*  public function sendMessage_notificacion_talentsafe(Request $request)
    {
        try {
            // Valida los datos de entrada
            $validated = $request->validate([
                'phone' => 'required|string',
                'template' => 'required|string',
                'nombre_cliente' => 'required|string',
                'submodulo' => 'required|string',
                'sucursales' => 'required|string',
            ]);
           // Log::info('Datos recibidos para el registro de empleado: ' . print_r($validated, true));

            // Obtén los datos de la solicitud
            $phone = $validated['phone'];
            $template = $validated['template'];
            $nombre_cliente = $validated['nombre_cliente'];
            $submodulo = $validated['submodulo'];
            $sucursales = $validated['sucursales'];

            // Define el URL del endpoint de la API de Facebook
            $url = 'https://graph.facebook.com/v22.0/648027118401660/messages';

            // Define el token de autorización (se recomienda almacenarlo en .env)

            $token = config('services.facebook.access_token');
           // Log::info('Token de acceso: ' . $token);
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
                            'type' => 'body',
                            'parameters' => [
                                [
                                    'type' => 'text',
                                    'text' => $nombre_cliente,  // Valor para la variable {{nombre_cliente}}
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $submodulo,  // Valor para la variable {{submodulo}}
                                ],
                                [
                                    'type' => 'text',
                                    'text' => $sucursales,  // Valor para la variable {{sucursales}}
                                ],
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
                //Log::info('Mensaje enviado exitosamente a ' . $phone);
                return response()->json([
                    'status' => 'success',
                    'data' => $response->json(),
                ]);
            } else {
                $error = $response->json('error', 'Error desconocido');
               // Log::error('Error al enviar mensaje a ' . $phone . ': ' . json_encode($error));
                return response()->json([
                    'status' => 'error',
                    'message' => $error,
                ], $response->status());
            }
        } catch (ValidationException $e) {
         //   Log::error('Error de validación: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Datos de entrada inválidos',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
           // Log::error('Error inesperado: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Ha ocurrido un error inesperado',
            ], 500);
        }
    }*/
    public function sendMessage_notificacion_talentsafe(Request $request)
    {
        // Correlation ID para seguir esta llamada en logs
        $rid = (string) Str::uuid();

        try {
            // 1) Validación de entrada
            $validated = $request->validate([
                'phone'          => 'required|string', // idealmente E.164 (+52...)
                'template'       => 'required|string',
                'nombre_cliente' => 'required|string',
                'submodulo'      => 'required|string',
                'sucursales'     => 'required|string',
            ]);

            // 2) Config: token y phone_number_id desde .env/services.php
            $token         = config('services.facebook.access_token');
            $phoneNumberId = config('services.facebook.phone_number_id');
            $baseUrl       = config('services.facebook.base_url');
            if (! $token || ! $phoneNumberId || ! $baseUrl) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Configuración de WhatsApp incompleta',
                ], 500);
            }
            // ✅ URL dinámica
            $url = "{$baseUrl}/{$phoneNumberId}/messages";

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $validated['phone'],
                'type'              => 'template',
                'template'          => [
                    'name'       => $validated['template'],
                    'language'   => ['code' => 'es_MX'],
                    'components' => [
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $validated['nombre_cliente']],
                                ['type' => 'text', 'text' => $validated['submodulo']],
                                ['type' => 'text', 'text' => $validated['sucursales']],
                            ],
                        ],
                    ],
                ],
            ];

            // 4) Log de entrada (sin exponer token)
            Log::info('[WA][{rid}] Enviando template', [
                'rid'      => $rid,
                'url'      => $url,
                'phone'    => $validated['phone'],
                'template' => $validated['template'],
                'payload'  => $payload,                          // seguro: no incluye token
                'headers'  => ['Authorization' => 'Bearer ***'], // mascara
            ]);

            // 5) Request HTTP con timeout y retries
            $response = Http::timeout(20)
                ->retry(2, 500) // 2 reintentos, espera 500ms
                ->withToken($token)
                ->acceptJson()
                ->post($url, $payload);

            // 6) Log de respuesta
            Log::info('[WA][{rid}] Respuesta Graph', [
                'rid'     => $rid,
                'status'  => $response->status(),
                'ok'      => $response->successful(),
                'reason'  => $response->reason(), // texto corto del estatus
                'body'    => $response->json() ?? $response->body(),
                'headers' => array_intersect_key($response->headers(), array_flip(['x-fb-trip-id', 'www-authenticate'])),
            ]);

            // 7) Manejo de éxito / error
            if ($response->successful()) {
                return response()->json([
                    'status' => 'success',
                    'data'   => $response->json(),
                    'rid'    => $rid,
                ]);
            }

            // Cuando falla, muchas veces Facebook responde con "error"
            $error = $response->json('error') ?? [
                'status' => $response->status(),
                'body'   => $response->body(),
            ];

            return response()->json([
                'status'  => 'error',
                'message' => $error,
                'rid'     => $rid,
            ], $response->status());

        } catch (ValidationException $e) {
            Log::warning('[WA][{rid}] Validación fallida', [
                'rid'    => $rid,
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Datos de entrada inválidos',
                'errors'  => $e->errors(),
                'rid'     => $rid,
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[WA][{rid}] Excepción no controlada', [
                'rid'       => $rid,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Ha ocurrido un error inesperado',
                'rid'     => $rid,
            ], 500);
        }
    }
    public function sendMessage_notificacion_exempleados(Request $request)
    {
        $rid = (string) Str::uuid();

        try {
            $validated = $request->validate([
                'phone'    => 'required|string',
                'portal'   => 'required|string',
                'modulo'   => 'required|string',
                'sucursal' => 'required|string',
            ]);

            $token         = config('services.facebook.access_token');
            $phoneNumberId = config('services.facebook.phone_number_id');
            $baseUrl       = config('services.facebook.base_url');
            if (! $token || ! $phoneNumberId || ! $baseUrl) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Configuración de WhatsApp incompleta',
                ], 500);
            }
            // ✅ URL dinámica
            $url = "{$baseUrl}/{$phoneNumberId}/messages";

            // Plantilla en inglés
            $template = 'notificacion_exempleados_v2';

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $validated['phone'],
                'type'              => 'template',
                'template'          => [
                    'name'       => $template,
                    'language'   => ['code' => 'es_MX'],
                    'components' => [
                        [
                            'type'       => 'header',
                            'parameters' => [
                                ['type' => 'text', 'text' => $validated['portal']], // {{1}}
                            ],
                        ],
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $validated['modulo']],   // {{2}}
                                ['type' => 'text', 'text' => $validated['sucursal']], // {{3}}
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::timeout(20)
                ->retry(2, 500)
                ->withToken($token)
                ->acceptJson()
                ->post($url, $payload);

            if ($response->successful()) {
                Log::info("[WA][{$rid}] Envío exitoso", ['status' => $response->status()]);
                return response()->json(['status' => 'success', 'rid' => $rid]);
            }

            $error = $response->json('error') ?? $response->body();
            Log::error("[WA][{$rid}] Error", ['error' => $error]);
            return response()->json(['status' => 'error', 'error' => $error], $response->status());

        } catch (Throwable $e) {
            Log::error("[WA][{$rid}] Excepción", ['msg' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'msg' => $e->getMessage()], 500);
        }
    }

    public function sendMessage_recordatorio_portal(Request $request)
    {
        $rid = (string) Str::uuid();

        try {
            $validated = $request->validate([
                'phone'        => 'required|string',
                'template'     => 'required|string',
                'portal'       => 'required|string', // ⬅ header variable
                'cliente'      => 'required|string',
                'recordatorio' => 'required|string',
                'mensaje'      => 'required|string',
                'fecha'        => 'required|string',
                'language'     => 'sometimes|string',
            ]);

            $token         = config('services.facebook.access_token');
            $phoneNumberId = config('services.facebook.phone_number_id');
            $apiVersion    = 'v22.0';

            if (empty($token) || empty($phoneNumberId)) {
                Log::error("[WA][{$rid}] Falta configuración", [
                    'has_token'           => ! empty($token),
                    'has_phone_number_id' => ! empty($phoneNumberId),
                ]);
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Configuración de WhatsApp incompleta (token o phone_number_id).',
                    'rid'     => $rid,
                ], 500);
            }

            // Normaliza todos los parámetros para que nunca estén vacíos
            $portal       = trim($validated['portal']) ?: 'TalentSafe';
            $cliente      = trim($validated['cliente']) ?: 'Sucursal';
            $recordatorio = trim($validated['recordatorio']) ?: 'Recordatorio';
            $mensaje      = trim($validated['mensaje']) ?: 'Sin detalle';
            $fecha        = trim($validated['fecha']) ?: date('d/m/Y');

            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $validated['phone'],
                'type'              => 'template',
                'template'          => [
                    'name'       => 'notificacion_recordatorios_v2',
                    'language'   => ['code' => 'es_MX'],
                    'components' => [
                        [
                            'type'       => 'header',
                            'parameters' => [
                                ['type' => 'text', 'text' => $portal], // {{1}} portal
                            ],
                        ],
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $cliente],      // {{2}} cliente
                                ['type' => 'text', 'text' => $recordatorio], // {{3}} recordatorio
                                ['type' => 'text', 'text' => $mensaje],      // {{4}} mensaje
                                ['type' => 'text', 'text' => $fecha],        // {{5}} fecha
                            ],
                        ],
                    ],
                ],
            ];
            Log::channel('daily')->info("[WA][{$rid}] Payload final a Meta:", [
                'url' => "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages",
                'payload' => $payload,
            ]);
            // Loguea payload final antes de enviar
            Log::info("[WA][{$rid}] Enviando plantilla de recordatorio", [
                'phone'    => $validated['phone'],
                'template' => $validated['template'],
                'payload'  => $payload,
            ]);

            $response = Http::timeout(20)->retry(1, 500)
                ->withToken($token)
                ->acceptJson()
                ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", $payload);

            Log::info("[WA][{$rid}] Respuesta Graph", [
                'status' => $response->status(),
                'ok'     => $response->successful(),
                'body'   => $response->json() ?? $response->body(),
            ]);

            if ($response->successful()) {
                return response()->json(['status' => 'success', 'data' => $response->json(), 'rid' => $rid]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error') ?? ['status' => $response->status(), 'body' => $response->body()],
                'rid'     => $rid,
            ], $response->status());

        } catch (ValidationException $e) {
            Log::warning("[WA][{$rid}] Validación fallida", ['errors' => $e->errors()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Datos de entrada inválidos',
                'errors'  => $e->errors(),
                'rid'     => $rid,
            ], 422);
        } catch (\Throwable $e) {
            Log::error("[WA][{$rid}] Excepción no controlada", [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Ha ocurrido un error inesperado', 'rid' => $rid], 500);
        }
    }

    public function sendMessage_recordatorio_portal1(Request $request)
    {
        $rid = (string) Str::uuid();
        Log::info("[WA][{$rid}] Request recibido", [
            'input_raw' => $request->all(),
        ]);

        try {
            // ✅ Validación
            $validated = $request->validate([
                'phone'        => 'required|string',
                'template'     => 'required|string',
                'portal'       => 'required|string', // HEADER variable
                'cliente'      => 'required|string',
                'recordatorio' => 'required|string',
                'mensaje'      => 'required|string',
                'fecha'        => 'required|string',
                'language'     => 'sometimes|string',
            ]);

            Log::info("[WA][{$rid}] Datos validados", $validated);

            $lang = $request->input('language', 'es_MX');

            $token         = config('services.facebook.access_token');
            $phoneNumberId = config('services.facebook.phone_number_id');
            $baseUrl       = config('services.facebook.base_url');
            if (! $token || ! $phoneNumberId || ! $baseUrl) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Configuración de WhatsApp incompleta',
                ], 500);
            }
            // ✅ URL dinámica
            $url = "{$baseUrl}/{$phoneNumberId}/messages";

            // ✅ Construcción del payload
            $payload = [
                'messaging_product' => 'whatsapp',
                'to'                => $validated['phone'],
                'type'              => 'template',
                'template'          => [
                    'name'       => $validated['template'],
                    'language'   => ['code' => $lang],
                    'components' => [
                        [
                            'type'       => 'header',
                            'parameters' => [
                                ['type' => 'text', 'text' => $validated['portal']],
                            ],
                        ],
                        [
                            'type'       => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => $validated['cliente']],
                                ['type' => 'text', 'text' => $validated['recordatorio']],
                                ['type' => 'text', 'text' => $validated['mensaje']],
                                ['type' => 'text', 'text' => $validated['fecha']],
                            ],
                        ],
                    ],
                ],
            ];

            Log::info("[WA][{$rid}] Payload preparado para enviar a WhatsApp", [
                'url'     => $url,
                'payload' => $payload,
            ]);

            // ✅ Envío HTTP a la API de WhatsApp
            $response = Http::timeout(20)
                ->retry(1, 500)
                ->withToken($token)
                ->acceptJson()
                ->post($url, $payload);

            // ✅ Registro detallado de la respuesta HTTP
            Log::info("[WA][{$rid}] Respuesta de Graph API", [
                'status'    => $response->status(),
                'ok'        => $response->successful(),
                'reason'    => $response->reason(),
                'headers'   => $response->headers(),
                'body_raw'  => $response->body(),
                'body_json' => $response->json(),
            ]);

            if ($response->successful()) {
                return response()->json([
                    'status' => 'success',
                    'data'   => $response->json(),
                    'rid'    => $rid,
                ]);
            }

            return response()->json([
                'status'  => 'error',
                'message' => $response->json('error') ?? [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ],
                'rid'     => $rid,
            ], $response->status());

        } catch (ValidationException $e) {
            Log::warning("[WA][{$rid}] Validación fallida", [
                'errors' => $e->errors(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Datos de entrada inválidos',
                'errors'  => $e->errors(),
                'rid'     => $rid,
            ], 422);

        } catch (\Throwable $e) {
            Log::error("[WA][{$rid}] Excepción no controlada", [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Ha ocurrido un error inesperado',
                'rid'     => $rid,
            ], 500);
        }
    }

}
