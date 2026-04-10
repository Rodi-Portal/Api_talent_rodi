<?php
namespace App\Mail;

use App\Models\Plantilla;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PlantillaCorreoMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected $plantilla;
    protected $nombreDestinatario;

    public function __construct(Plantilla $plantilla, $nombreDestinatario = null)
    {
        $this->plantilla          = $plantilla;
        $this->nombreDestinatario = $nombreDestinatario;
    }

    public function build()
    {
                                                     // 1) Rutas
        $logoFilename = $this->plantilla->logo_path; // e.g. "logo_6872a99c0d337.png"
        $logoPathFs   = env('LOCAL_IMAGE_PATH') . '/_plantillas/_logos/' . $logoFilename;
        $logoUrl      = env('LOCAL_IMAGE_PATHA') . '/_plantillas/_logos/' . $logoFilename;

        // logger('Ruta FS del logo: ' . $logoPathFs);
        //logger('URL del logo: ' . $logoUrl);
        // 2) Reemplazo de nombre en el cuerpo
        $cuerpoProcesado = str_replace('{{$nombre}}', $this->nombreDestinatario, $this->plantilla->cuerpo);

        //Log::info("📧 Generando correo para {$this->nombreDestinatario}");

        // 3) Datos que recibe la vista
        $viewData = [
            'titulo'   => $this->plantilla->titulo,
            'cuerpo'   => $cuerpoProcesado,
            'saludo'   => $this->plantilla->saludo,
            'nombre'   => $this->nombreDestinatario,
            'logo_src' => file_exists($logoPathFs) ? $logoUrl : null,
        ];

        // 4) Adjuntar los demás archivos
        foreach ($this->plantilla->adjuntos as $adjunto) {
            $path = env('LOCAL_IMAGE_PATH') . '/_plantillas/_adjuntos/' . $adjunto->archivo;
            if (file_exists($path)) {
                $this->attach($path, [
                    'as'   => $adjunto->nombre_original,
                    'mime' => mime_content_type($path),
                ]);
                //      Log::info('📎 Adjunto agregado', ['nombre' => $adjunto->nombre_original]);
            } else {
                //    Log::warning('⚠️ Adjunto no encontrado', ['path' => $path]);
            }
        }

        // 5) Debug en local/testing
        if (app()->environment(['local', 'testing'])) {
            $html = view('emails.plantillas.' . $this->plantilla->nombre_plantilla, $viewData)->render();
            // Log::info("📝 Vista HTML generada:\n" . $html);
        }

        // 6) Devuelve el mailable
        return $this
            ->from(
                config('mail.from.address'),
                'TalentSafe Comunicación'
            )
            ->subject($this->plantilla->asunto)
            ->view('emails.plantillas.' . $this->plantilla->nombre_plantilla)
            ->with($viewData);
    }

}
