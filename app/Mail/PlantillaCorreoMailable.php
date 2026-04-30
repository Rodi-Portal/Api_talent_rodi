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
        // 1) Ruta física y URL pública del logo
        $logoFilename = $this->plantilla->logo_path;

        $logoPathFs = $logoFilename
            ? env('LOCAL_IMAGE_PATH') . '/_plantillas/_logos/' . $logoFilename
            : null;

        $logoUrl = $logoFilename
            ? rtrim(env('LOCAL_IMAGE_URL'), '/') . '/_plantillas/_logos/' . $logoFilename
            : null;

        // 2) Reemplazo de nombre en el cuerpo
        $cuerpoProcesado = str_replace(
            '{{$nombre}}',
            $this->nombreDestinatario,
            $this->plantilla->cuerpo
        );

        // 3) Datos que recibe la vista
        $viewData = [
            'titulo'   => $this->plantilla->titulo,
            'cuerpo'   => $cuerpoProcesado,
            'saludo'   => $this->plantilla->saludo,
            'nombre'   => $this->nombreDestinatario,
            'logo_src' => $logoPathFs && file_exists($logoPathFs)
                ? $logoUrl
                : null,
        ];

        // 4) Adjuntar archivos
        foreach ($this->plantilla->adjuntos as $adjunto) {
            $path = env('LOCAL_IMAGE_PATH') . '/_plantillas/_adjuntos/' . $adjunto->archivo;

            if (file_exists($path)) {
                $this->attach($path, [
                    'as'   => $adjunto->nombre_original,
                    'mime' => mime_content_type($path),
                ]);
            }
        }

        // 5) Debug local/testing
        if (app()->environment(['local', 'testing'])) {
            $html = view(
                'emails.plantillas.' . $this->plantilla->nombre_plantilla,
                $viewData
            )->render();
        }

        // 6) Devuelve el mailable
        return $this
            ->from(config('mail.from.address'), 'TalentSafe Comunicación')
            ->subject($this->plantilla->asunto)
            ->view('emails.plantillas.' . $this->plantilla->nombre_plantilla)
            ->with($viewData);
    }

}
