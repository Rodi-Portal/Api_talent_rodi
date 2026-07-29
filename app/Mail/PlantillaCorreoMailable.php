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
            ? rtrim(env('LOCAL_IMAGE_URL'), '/') .
        '/_plantillas/_logos/' .
        $logoFilename
            : null;

        // Confirmar una sola vez que el archivo existe físicamente.
        $logoDisponible = $logoPathFs && file_exists($logoPathFs);

        // 2) Reemplazo del nombre del destinatario en el cuerpo
        $cuerpoProcesado = str_replace(
            '{{$nombre}}',
            $this->nombreDestinatario ?? '',
            $this->plantilla->cuerpo ?? ''
        );

        // 3) Datos que recibe la plantilla Blade
        $viewData = [
            'titulo'    => $this->plantilla->titulo,
            'cuerpo'    => $cuerpoProcesado,
            'saludo'    => $this->plantilla->saludo,
            'nombre'    => $this->nombreDestinatario,

            /*
         * URL pública:
         * se utiliza como respaldo y en renderizados que no sean correo.
         */
            'logo_src'  => $logoDisponible
                ? $logoUrl
                : null,

            /*
         * Ruta física:
         * la plantilla Blade la utiliza con $message->embed()
         * para incrustar el logo en el correo.
         */
            'logo_path' => $logoDisponible
                ? $logoPathFs
                : null,
        ];

        // 4) Adjuntar archivos asociados con la plantilla
        foreach ($this->plantilla->adjuntos as $adjunto) {
            $path = env('LOCAL_IMAGE_PATH') .
            '/_plantillas/_adjuntos/' .
            $adjunto->archivo;

            if (file_exists($path)) {
                $this->attach($path, [
                    'as'   => $adjunto->nombre_original,
                    'mime' => mime_content_type($path),
                ]);
            }
        }

        // 5) Renderizado para depuración local
        if (app()->environment(['local', 'testing'])) {
            view(
                'emails.plantillas.' .
                $this->plantilla->nombre_plantilla,
                $viewData
            )->render();
        }

        // 6) Construir y devolver el correo
        return $this
            ->from(
                config('mail.from.address'),
                'TalentSafe Comunicación'
            )
            ->subject($this->plantilla->asunto)
            ->view(
                'emails.plantillas.' .
                $this->plantilla->nombre_plantilla
            )
            ->with($viewData);
    }

}
