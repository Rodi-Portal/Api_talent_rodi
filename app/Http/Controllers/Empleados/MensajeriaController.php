<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Mail\PlantillaCorreoMailable;
use App\Models\Auth\AdministradorAuth;
use App\Models\Empleado;
use App\Models\Plantilla;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MensajeriaController extends Controller
{
    /**
     * Obtener empleados con información relacionada (domicilio, campos extra, etc.)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    public function obtenerEmpleados(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'message' => 'Token administrativo no válido.',
            ], 403);
        }

        $validated = $request->validate([
            'id_cliente'   => ['required', 'array', 'min:1'],
            'id_cliente.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        $idsCliente = $this->clientScope->authorizeRequestedClients(
            $administrator,
            $validated['id_cliente']
        );

        // Cargar empleados con relaciones, incluyendo cliente
        $empleados = Empleado::with([
            'domicilioEmpleado',
            'camposExtra',
            'cliente', // Importante: para obtener el nombre del cliente
                       // 'informacionMedica' // Si se desea incluir
        ])
            ->where('id_portal', (int) $administrator->id_portal)
            ->whereIn('id_cliente', $idsCliente)
            ->where('eliminado', 0)
            ->get()
            ->map(function ($empleado) {
                return [
                    'id'     => $empleado->id_empleado,
                    'nombre' => trim("{$empleado->nombre} {$empleado->paterno} {$empleado->materno}"),
                    'email'                => $empleado->correo,
                    // 👇 Info de cliente para reutilizar en mensajería / plantillas
                    'cliente_id'           => $empleado->id_cliente,
                    'cliente_nombre'       => optional($empleado->cliente)->nombre ?? 'Sin nombre',
                    'sucursal'             => optional($empleado->cliente)->nombre ?? 'Sin nombre',
                    'camposPersonalizados' => array_merge(
                        [
                            'telefono' => $empleado->telefono,
                            'rfc'      => $empleado->rfc,
                            'curp'     => $empleado->curp,
                        ],
                        optional($empleado->domicilioEmpleado)->only([
                            'calle', 'colonia', 'municipio', 'estado',
                        ]) ?? [],
                        optional($empleado->informacionMedica)->only([
                            'tipo_sangre', 'alergias', 'contacto_emergencia',
                        ]) ?? [],
                        $empleado->camposExtra->mapWithKeys(function ($extra) {
                            return [$extra->nombre => $extra->valor];
                        })->toArray()
                    ),
                ];
            });

        return response()->json($empleados);
    }

    public function enviarCorreos(Request $request)
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            return response()->json([
                'status'  => false,
                'message' => 'Token administrativo no válido.',
            ], 403);
        }
        $data = $request->validate([
            'id_plantilla'           => [
                'required',
                'integer',
                'min:1',
            ],
            'destinatarios'          => 'required|array|min:1',
            'destinatarios.*.correo' => 'required|email',
            'destinatarios.*.nombre' => 'required|string|max:255',
        ]);
        $permittedClientIds = $this->clientScope
            ->permittedClientIds($administrator);
        Log::info('📥 Solicitud de envío recibida', [
            'id_plantilla'  => $data['id_plantilla'],
            'total_correos' => count($data['destinatarios']),
        ]);

        // Cargar plantilla con adjuntos
        $plantilla = Plantilla::with('adjuntos')
            ->where('id_portal', (int) $administrator->id_portal)
            ->where(function ($query) use ($permittedClientIds) {
                $query->whereNull('id_cliente');

                if ($permittedClientIds !== []) {
                    $query->orWhereIn(
                        'id_cliente',
                        $permittedClientIds
                    );
                }
            })
            ->findOrFail($data['id_plantilla']);

        Log::info('📝 Detalles de la plantilla seleccionada', [
            'id'                   => $plantilla->id,
            'nombre_personalizado' => $plantilla->nombre_personalizado,
            'titulo'               => $plantilla->titulo,
            'asunto'               => $plantilla->asunto,
            'nombre_plantilla'     => $plantilla->nombre_plantilla,
            'logo_path'            => $plantilla->logo_path,
            'adjuntos_count'       => $plantilla->adjuntos->count(),
        ]);

        if ($plantilla->adjuntos->isNotEmpty()) {
            foreach ($plantilla->adjuntos as $adjunto) {
                Log::info('📎 Adjunto', [
                    'nombre_original' => $adjunto->nombre_original,
                    'archivo'         => $adjunto->archivo,
                    'ruta_estimada'   => env('LOCAL_IMAGE_PATH') . '/_plantillas/_adjuntos/' . $adjunto->archivo,
                ]);
            }
        }
        $destinatarios = $data['destinatarios'];
        /*$correos = $data['correos'] ?? [
            'luisjorgeti@rodicontrol.com',
            'sistemas@rodicontrol.com',
        ];

        //

        $destinatarios = json_decode('[
        {"nombre": "Juan Pérez", "correo": "Luisjorgeti@rodicontrol.com"},
        {"nombre": "Ana Torres", "correo": "rodi.control@gmail.com"}
        ]', true);
        */
        foreach ($destinatarios as $destinatario) {
            try {
                Mail::to($destinatario['correo'])->send(
                    new PlantillaCorreoMailable($plantilla, $destinatario['nombre'])
                );
                Log::info("✅ Correo enviado a {$destinatario['correo']} ({$destinatario['nombre']})");
            } catch (\Exception $e) {
                Log::error("❌ Error enviando a {$destinatario['correo']}: " . $e->getMessage());
            }
        }

        return response()->json([
            'success'       => true,
            'mensaje'       => 'Correos enviados correctamente.',
            'plantilla'     => $plantilla->id,
            'total_correos' => count($data['destinatarios']),
        ]);
    }

}
