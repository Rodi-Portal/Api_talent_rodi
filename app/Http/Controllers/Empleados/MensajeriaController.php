<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Mail\PlantillaCorreoMailable;
use App\Models\Empleado;
use App\Models\Plantilla;
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
    public function obtenerEmpleados(Request $request)
    {
        $id_cliente = $request->input('id_cliente');

        if (! $id_cliente) {
            return response()->json(['error' => 'Falta el parámetro id_cliente'], 400);
        }

        // Cargar empleados con relaciones
        $empleados = Empleado::with([
            'domicilioEmpleado',
            'camposExtra',
            // Descomenta esta línea si tienes esta relación definida
            // 'informacionMedica'
        ])
            ->where('id_cliente', $id_cliente)
            ->where('eliminado', 0)
            ->get()
            ->map(function ($empleado) {
                return [
                    'id'                   => $empleado->id_empleado,
                    'nombre'               => trim("{$empleado->nombre} {$empleado->paterno} {$empleado->materno}"),
                    'email'                => $empleado->correo,
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
        $data = $request->validate([
            'id_plantilla'           => 'required|exists:portal_main.plantillas,id',
            'destinatarios'          => 'required|array|min:1',
            'destinatarios.*.correo' => 'required|email',
            'destinatarios.*.nombre' => 'required|string|max:255',
        ]);

        Log::info('📥 Solicitud de envío recibida', [
            'id_plantilla'  => $data['id_plantilla'],
            'total_correos' => count($data['destinatarios']),
        ]);

        // Cargar plantilla con adjuntos
        $plantilla = Plantilla::with('adjuntos')->findOrFail($data['id_plantilla']);

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
        /*$correos = $data['correos'] ?? [
            'luisjorgeti@rodicontrol.com',
            'sistemas@rodicontrol.com',];*/
            
        $destinatarios = $data['destinatarios'];

        /* $destinatarios = json_decode('[
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
