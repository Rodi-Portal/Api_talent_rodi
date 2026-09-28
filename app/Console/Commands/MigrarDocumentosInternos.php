<?php

namespace App\Console\Commands;

use App\Models\DocumentoInterno;
use App\Services\Documents\InternalDocumentPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MigrarDocumentosInternos extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-internos
        {--id= : Migrar o verificar solamente un documento interno}
        {--execute : Ejecutar copia física y actualización de storage_path}
        {--verify-files : Verificar existencia e integridad de archivos}
        {--summary : Mostrar resumen final}';

    protected $description =
        'Migra documentos internos desde _internos legacy hacia storagetalentsafe';

    private array $counters = [
        'revisados'             => 0,
        'migrables'             => 0,
        'migrados'              => 0,
        'ya_migrados'           => 0,
        'verificados'           => 0,
        'origen_no_existe'      => 0,
        'destino_diferente'     => 0,
        'ruta_invalida'         => 0,
        'sin_informacion'       => 0,
        'errores'               => 0,
    ];

    public function __construct(
        private InternalDocumentPathService $documentPaths
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $verify  = (bool) $this->option('verify-files');
        $id      = $this->option('id');

        $query = DocumentoInterno::query()
            ->with('informacionInterna')
            ->orderBy('id');

        if ($id !== null && $id !== '') {
            if (! ctype_digit((string) $id) || (int) $id <= 0) {
                $this->error('El valor de --id debe ser un entero positivo.');

                return self::FAILURE;
            }

            $query->where('id', (int) $id);
        }

        $documents = $query->get();

        if ($documents->isEmpty()) {
            $this->warn('No se encontraron documentos internos.');

            return self::SUCCESS;
        }

        $this->info(
            $execute
                ? 'MODO EJECUCIÓN'
                : 'MODO SIMULACIÓN - no se modificarán archivos ni BD'
        );

        foreach ($documents as $document) {
            $this->processDocument(
                $document,
                $execute,
                $verify
            );
        }

        if ($this->option('summary')) {
            $this->showSummary();
        }

        return $this->counters['errores'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function processDocument(
        DocumentoInterno $document,
        bool $execute,
        bool $verify
    ): void {
        $this->counters['revisados']++;

        $storedPath = trim(
            str_replace(
                '\\',
                '/',
                (string) $document->storage_path
            )
        );

        $information = $document->informacionInterna;

        if (! $information) {
            $this->counters['sin_informacion']++;
            $this->line(
                sprintf(
                    '[SIN_INFORMACION] id=%d storage_path=%s',
                    $document->id,
                    $storedPath
                )
            );

            return;
        }

        /*
         * Ya está en estructura definitiva.
         */
        if (str_starts_with($storedPath, 'portales/')) {
            $this->processNewPath(
                $document,
                $storedPath,
                $verify
            );

            return;
        }

        /*
         * Solamente migramos la estructura legacy conocida.
         */
        if (! str_starts_with($storedPath, '_internos/')) {
            $this->counters['ruta_invalida']++;
            $this->line(
                sprintf(
                    '[RUTA_INVALIDA] id=%d storage_path=%s',
                    $document->id,
                    $storedPath
                )
            );

            return;
        }

        try {
            $sourcePath = $this->documentPaths
                ->existingAbsolutePath($storedPath);
        } catch (Throwable $exception) {
            $this->counters['origen_no_existe']++;

            $this->line(
                sprintf(
                    '[ORIGEN_NO_EXISTE] id=%d storage_path=%s',
                    $document->id,
                    $storedPath
                )
            );

            return;
        }

        $fileName = basename($storedPath);

        try {
            $newStoredPath = $this->documentPaths->activeStoredPath(
                $information,
                $fileName
            );

            $destinationDirectory =
                $this->documentPaths->activeDirectoryPath(
                    $information
                );

            $destinationPath = rtrim(
                $destinationDirectory,
                '/\\'
            )
                . DIRECTORY_SEPARATOR
                . $fileName;
        } catch (Throwable $exception) {
            $this->registerError(
                $document->id,
                $exception
            );

            return;
        }

        /*
         * El archivo nuevo ya existe físicamente.
         * Antes de actualizar BD comprobamos que sea exactamente el mismo.
         */
        if (is_file($destinationPath)) {
            if (! $this->sameFile($sourcePath, $destinationPath)) {
                $this->counters['destino_diferente']++;

                $this->line(
                    sprintf(
                        '[DESTINO_DIFERENTE] id=%d origen=%s destino=%s',
                        $document->id,
                        $storedPath,
                        $newStoredPath
                    )
                );

                return;
            }

            if (! $execute) {
                $this->counters['migrables']++;

                $this->line(
                    sprintf(
                        '[MIGRABLE_BD] id=%d %s -> %s',
                        $document->id,
                        $storedPath,
                        $newStoredPath
                    )
                );

                return;
            }

            try {
                $this->updateStoredPath(
                    $document,
                    $storedPath,
                    $newStoredPath
                );

                $this->counters['migrados']++;

                $this->line(
                    sprintf(
                        '[MIGRADO_BD] id=%d %s',
                        $document->id,
                        $newStoredPath
                    )
                );
            } catch (Throwable $exception) {
                $this->registerError(
                    $document->id,
                    $exception
                );
            }

            return;
        }

        $this->counters['migrables']++;

        if (! $execute) {
            $this->line(
                sprintf(
                    '[MIGRABLE] id=%d %s -> %s',
                    $document->id,
                    $storedPath,
                    $newStoredPath
                )
            );

            return;
        }

        try {
            $this->copyAndVerify(
                $sourcePath,
                $destinationPath
            );

            $this->updateStoredPath(
                $document,
                $storedPath,
                $newStoredPath
            );

            $this->counters['migrados']++;

            $this->line(
                sprintf(
                    '[MIGRADO] id=%d %s',
                    $document->id,
                    $newStoredPath
                )
            );
        } catch (Throwable $exception) {
            /*
             * Si la BD no pudo actualizarse, dejamos el archivo copiado.
             * En la siguiente ejecución se detectará por hash y sólo
             * intentará actualizar storage_path.
             */
            $this->registerError(
                $document->id,
                $exception
            );
        }
    }

    private function processNewPath(
        DocumentoInterno $document,
        string $storedPath,
        bool $verify
    ): void {
        try {
            $filePath = $this->documentPaths
                ->existingAbsolutePath($storedPath);

            $this->counters['ya_migrados']++;

            if ($verify) {
                $hash = hash_file('sha256', $filePath);

                if ($hash === false) {
                    throw new RuntimeException(
                        'No se pudo calcular SHA256.'
                    );
                }

                $this->counters['verificados']++;

                $this->line(
                    sprintf(
                        '[VERIFICADO] id=%d sha256=%s %s',
                        $document->id,
                        $hash,
                        $storedPath
                    )
                );

                return;
            }

            $this->line(
                sprintf(
                    '[YA_MIGRADO] id=%d %s',
                    $document->id,
                    $storedPath
                )
            );
        } catch (Throwable $exception) {
            $this->counters['origen_no_existe']++;

            $this->line(
                sprintf(
                    '[NUEVO_NO_EXISTE] id=%d storage_path=%s',
                    $document->id,
                    $storedPath
                )
            );
        }
    }

    private function copyAndVerify(
        string $sourcePath,
        string $destinationPath
    ): void {
        $destinationDirectory = dirname($destinationPath);

        if (
            ! is_dir($destinationDirectory)
            && ! mkdir(
                $destinationDirectory,
                0755,
                true
            )
            && ! is_dir($destinationDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio destino.'
            );
        }

        $sourceHash = hash_file(
            'sha256',
            $sourcePath
        );

        if ($sourceHash === false) {
            throw new RuntimeException(
                'No se pudo calcular SHA256 del origen.'
            );
        }

        $temporaryPath = $destinationPath
            . '.tmp-'
            . bin2hex(random_bytes(6));

        try {
            if (! copy($sourcePath, $temporaryPath)) {
                throw new RuntimeException(
                    'No se pudo copiar el archivo temporal.'
                );
            }

            $temporaryHash = hash_file(
                'sha256',
                $temporaryPath
            );

            if (
                $temporaryHash === false
                || ! hash_equals(
                    $sourceHash,
                    $temporaryHash
                )
            ) {
                throw new RuntimeException(
                    'El hash del archivo copiado no coincide.'
                );
            }

            if (! rename(
                $temporaryPath,
                $destinationPath
            )) {
                throw new RuntimeException(
                    'No se pudo colocar el archivo en el destino.'
                );
            }

            @chmod($destinationPath, 0664);

            $destinationHash = hash_file(
                'sha256',
                $destinationPath
            );

            if (
                $destinationHash === false
                || ! hash_equals(
                    $sourceHash,
                    $destinationHash
                )
            ) {
                @unlink($destinationPath);

                throw new RuntimeException(
                    'La verificación final SHA256 falló.'
                );
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function updateStoredPath(
        DocumentoInterno $document,
        string $oldStoredPath,
        string $newStoredPath
    ): void {
        DB::transaction(
            function () use (
                $document,
                $oldStoredPath,
                $newStoredPath
            ): void {
                $affected = DocumentoInterno::query()
                    ->where('id', $document->id)
                    ->where(
                        'storage_path',
                        $oldStoredPath
                    )
                    ->update([
                        'storage_path' => $newStoredPath,
                    ]);

                if ($affected !== 1) {
                    throw new RuntimeException(
                        'El registro cambió durante la migración.'
                    );
                }
            }
        );
    }

    private function sameFile(
        string $sourcePath,
        string $destinationPath
    ): bool {
        $sourceHash = hash_file(
            'sha256',
            $sourcePath
        );

        $destinationHash = hash_file(
            'sha256',
            $destinationPath
        );

        return $sourceHash !== false
            && $destinationHash !== false
            && hash_equals(
                $sourceHash,
                $destinationHash
            );
    }

    private function registerError(
        int $documentId,
        Throwable $exception
    ): void {
        $this->counters['errores']++;

        $this->error(
            sprintf(
                '[ERROR] id=%d %s',
                $documentId,
                $exception->getMessage()
            )
        );
    }

    private function showSummary(): void
    {
        $this->newLine();
        $this->info('===== RESUMEN =====');

        foreach ($this->counters as $name => $value) {
            $this->line(
                sprintf(
                    '%-22s %d',
                    $name . ':',
                    $value
                )
            );
        }
    }
}
