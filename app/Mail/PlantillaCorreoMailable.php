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
        // 1) Ruta base correspondiente al ambiente actual
        $imagesPath = rtrim((string) config('paths.images_path'), '/\\');
        $imagesUrl  = rtrim((string) config('paths.images_url'), '/');

        // 2) Ruta física y URL pública del logo
        $logoFilename = $this->plantilla->logo_path;

        $logoPathFs = $logoFilename
            ? $imagesPath . '/_plantillas/_logos/' . $logoFilename
            : null;

        $logoUrl = $logoFilename
            ? $imagesUrl . '/_plantillas/_logos/' . $logoFilename
            : null;

        $logoDisponible = $logoPathFs &&
        file_exists($logoPathFs) &&
        is_readable($logoPathFs);

        // 3) Sustituir el nombre del destinatario
        $cuerpoProcesado = str_replace(
            '{{$nombre}}',
            $this->nombreDestinatario ?? '',
            $this->plantilla->cuerpo ?? ''
        );

        // 4) Datos enviados a la plantilla Blade
        $viewData = [
            'titulo'    => $this->plantilla->titulo,
            'cuerpo'    => $cuerpoProcesado,
            'saludo'    => $this->plantilla->saludo,
            'nombre'    => $this->nombreDestinatario,

            // URL pública para vistas normales.
            'logo_src'  => $logoDisponible
                ? $logoUrl
                : null,

            // Ruta física para incrustarlo en el correo.
            'logo_path' => $logoDisponible
                ? $logoPathFs
                : null,
        ];

        // 5) Adjuntar archivos
        foreach ($this->plantilla->adjuntos as $adjunto) {
            $path = $imagesPath .
            '/_plantillas/_adjuntos/' .
            $adjunto->archivo;

            if (file_exists($path) && is_readable($path)) {
                $this->attach($path, [
                    'as'   => $adjunto->nombre_original,
                    'mime' => mime_content_type($path),
                ]);
            }
        }

        // 6) Renderizado de depuración local
        if (app()->environment(['local', 'testing'])) {
            view(
                'emails.plantillas.' .
                $this->plantilla->nombre_plantilla,
                $viewData
            )->render();
        }

        // 7) Construir el correo
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
