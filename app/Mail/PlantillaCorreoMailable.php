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
        $this->plantilla = $plantilla;
        $this->nombreDestinatario = $nombreDestinatario;
    }

    public function build()
    {
        $logoAsset = null;

        if ($this->plantilla->logo_path) {
            $logoAsset = $this->resolvePlantillaAsset(
                (int) $this->plantilla->id_portal,
                '_logos',
                (string) $this->plantilla->logo_path
            );
        }

        $cuerpoProcesado = str_replace(
            '{{$nombre}}',
            $this->nombreDestinatario ?? '',
            $this->plantilla->cuerpo ?? ''
        );

        $viewData = [
            'titulo'   => $this->plantilla->titulo,
            'cuerpo'   => $cuerpoProcesado,
            'saludo'   => $this->plantilla->saludo,
            'nombre'   => $this->nombreDestinatario,

            // URL como respaldo para renderizados fuera del correo.
            'logo_src' => $logoAsset['url'] ?? null,

            // Ruta física: el correo la incrusta mediante CID/embed().
            'logo_path' => $logoAsset['path'] ?? null,
        ];

        foreach ($this->plantilla->adjuntos as $adjunto) {
            $asset = $this->resolvePlantillaAsset(
                (int) $this->plantilla->id_portal,
                '_adjuntos',
                (string) $adjunto->archivo
            );

            if ($asset === null) {
                continue;
            }

            $this->attach($asset['path'], [
                'as'   => $adjunto->nombre_original,
                'mime' => mime_content_type($asset['path'])
                    ?: 'application/octet-stream',
            ]);
        }

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

    /**
     * Lectura dual durante la migración:
     * 1. storagetalentsafe
     * 2. storage legacy
     *
     * Las nuevas escrituras se realizan únicamente en storagetalentsafe.
     */
    private function resolvePlantillaAsset(
        int $portalId,
        string $directory,
        string $filename
    ): ?array {
        $filename = trim($filename);

        if (
            $portalId <= 0
            || $filename === ''
            || basename($filename) !== $filename
            || ! in_array($directory, ['_logos', '_adjuntos'], true)
        ) {
            return null;
        }

        $documentsPath = rtrim(
            str_replace('\\', '/', (string) config('paths.documents_path')),
            '/'
        );

        $documentsUrl = rtrim(
            (string) config('paths.documents_url'),
            '/'
        );

        $imagesPath = rtrim(
            str_replace('\\', '/', (string) config('paths.images_path')),
            '/'
        );

        $imagesUrl = rtrim(
            (string) config('paths.images_url'),
            '/'
        );

        if ($documentsPath !== '') {
            $path = $documentsPath
                . '/portales/'
                . $portalId
                . '/_plantillas/'
                . $directory
                . '/'
                . $filename;

            if (is_file($path) && is_readable($path)) {
                return [
                    'path' => $path,
                    'url'  => $documentsUrl !== ''
                        ? $documentsUrl
                            . '/portales/'
                            . $portalId
                            . '/_plantillas/'
                            . $directory
                            . '/'
                            . $filename
                        : null,
                ];
            }
        }

        if ($imagesPath !== '') {
            $path = $imagesPath
                . '/_plantillas/'
                . $directory
                . '/'
                . $filename;

            if (is_file($path) && is_readable($path)) {
                return [
                    'path' => $path,
                    'url'  => $imagesUrl !== ''
                        ? $imagesUrl
                            . '/_plantillas/'
                            . $directory
                            . '/'
                            . $filename
                        : null,
                ];
            }
        }

        return null;
    }
}
