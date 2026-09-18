<?php

namespace App\Console\Commands;

use App\Services\Documents\RodiCandidateDocumentPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MigrarDocumentosRodi extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-rodi
                            {candidate? : ID del candidato en RODI}
                            {--portal= : Migrar todos los documentos RODI de un portal}
                            {--all : Migrar todos los documentos RODI sincronizados}
                            {--verify-files : Verificar existencia, lectura, tamaño y SHA-256 de destinos existentes}
                            {--summary : Mostrar únicamente el resumen final}
                            {--execute : Copiar físicamente los archivos}';

    protected $description =
        'Migra documentos legacy de candidatos RODI almacenados en TalentSafe _docs hacia storagetalentsafe';

    public function handle(
        RodiCandidateDocumentPathService $documentPaths
    ): int {
        try {
            $scope = $this->resolveScope();
            $sourceDirectory = $this->resolveSourceDirectory();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $verify  = (bool) $this->option('verify-files');
        $summary = (bool) $this->option('summary');

        $query = DB::connection('rodi_main')
            ->table('candidato_documento as d')
            ->join(
                'candidato_sync as s',
                's.id_candidato_rodi',
                '=',
                'd.id_candidato'
            )
            ->select([
                'd.id as document_id',
                'd.id_candidato as id_candidato_rodi',
                'd.id_tipo_documento',
                'd.archivo',
                's.id_portal',
            ])
            ->where('d.eliminado', 0)
            ->whereNotNull('s.id_portal')
            ->where('s.id_portal', '>', 0)
            ->distinct();

        if ($scope['candidate'] !== null) {
            $query->where(
                'd.id_candidato',
                $scope['candidate']
            );
        }

        if ($scope['portal'] !== null) {
            $query->where(
                's.id_portal',
                $scope['portal']
            );
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn(
                'No se encontraron documentos activos para el alcance indicado.'
            );

            return Command::SUCCESS;
        }

        $this->info(
            $execute
                ? 'EJECUCIÓN: se copiarán archivos al almacenamiento definitivo.'
                : 'SIMULACIÓN: no se modificarán archivos ni base de datos.'
        );

        $this->line('Alcance: ' . $scope['description']);
        $this->line("Documentos encontrados: {$total}");

        $this->newLine();
        $this->line(
            'Origen legacy TalentSafe: ' . $sourceDirectory
        );

        if ($execute) {
            $this->newLine();
            $this->warn(
                'Los archivos legacy de _docs se conservarán.'
            );
            $this->warn(
                'La tabla candidato_documento no será modificada.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración de {$total} documento(s)?",
                false
            )) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        $manifestPath = $execute
            ? $this->createManifestPath($scope)
            : null;

        $totals = [
            'revisados'            => 0,
            'migrables'            => 0,
            'ya_migrados'          => 0,
            'ejecutados'           => 0,
            'sin_archivo'          => 0,
            'origen_no_existe'     => 0,
            'destino_diferente'    => 0,
            'errores'              => 0,
        ];

        $rows = [];

        $query
            ->orderBy('d.id')
            ->orderBy('s.id_portal')
            ->chunk(200, function ($documents) use (
                &$totals,
                &$rows,
                $execute,
                $verify,
                $summary,
                $sourceDirectory,
                $documentPaths,
                $manifestPath
            ): void {
                foreach ($documents as $document) {
                    $totals['revisados']++;

                    $result = $this->processDocument(
                        $document,
                        $execute,
                        $verify,
                        $sourceDirectory,
                        $documentPaths
                    );

                    if (isset($totals[$result['counter']])) {
                        $totals[$result['counter']]++;
                    }

                    if ($manifestPath !== null) {
                        $this->appendManifest(
                            $manifestPath,
                            $document,
                            $result
                        );
                    }

                    if (! $summary) {
                        $rows[] = [
                            (int) $document->document_id,
                            (int) $document->id_portal,
                            (int) $document->id_candidato_rodi,
                            (string) $document->archivo,
                            $result['status'],
                        ];
                    }
                }
            });

        if (! empty($rows)) {
            $this->newLine();
            $this->table(
                [
                    'Documento',
                    'Portal',
                    'Candidato RODI',
                    'Archivo',
                    'Resultado',
                ],
                $rows
            );
        }

        $this->newLine();

        $this->table(
            ['Concepto', 'Total'],
            [
                ['Revisados', $totals['revisados']],
                ['Migrables', $totals['migrables']],
                ['Ya migrados', $totals['ya_migrados']],
                ['Ejecutados', $totals['ejecutados']],
                ['Sin archivo', $totals['sin_archivo']],
                ['Origen no existe', $totals['origen_no_existe']],
                ['Destino diferente', $totals['destino_diferente']],
                ['Errores', $totals['errores']],
            ]
        );

        if ($manifestPath !== null) {
            $this->line('Manifiesto: ' . $manifestPath);
        }

        if (
            $totals['errores'] > 0
            || $totals['destino_diferente'] > 0
        ) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function processDocument(
        object $document,
        bool $execute,
        bool $verify,
        string $sourceDirectory,
        RodiCandidateDocumentPathService $documentPaths
    ): array {
        try {
            $storedValue = trim(
                (string) $document->archivo
            );

            $filename = $this->safeFilename($storedValue);

            if ($filename === null) {
                return $this->result(
                    'SIN_ARCHIVO_OMITIDO',
                    'sin_archivo'
                );
            }

            $portalId = (int) $document->id_portal;
            $candidateId = (int) $document->id_candidato_rodi;

            if ($portalId <= 0 || $candidateId <= 0) {
                return $this->result(
                    'ERROR_IDENTIDAD_INVALIDA',
                    'errores'
                );
            }

            $targetPath = $documentPaths->absolutePath(
                $portalId,
                $candidateId,
                $filename
            );

            $sourcePath = $sourceDirectory
                . DIRECTORY_SEPARATOR
                . $filename;

            $sourceHash = null;

            if (is_file($sourcePath)) {
                if (! is_readable($sourcePath)) {
                    return $this->result(
                        'ERROR_ORIGEN_NO_LEGIBLE',
                        'errores',
                        $sourcePath,
                        $targetPath
                    );
                }

                $sourceHash = hash_file(
                    'sha256',
                    $sourcePath
                );

                if ($sourceHash === false) {
                    return $this->result(
                        'ERROR_HASH_ORIGEN',
                        'errores',
                        $sourcePath,
                        $targetPath
                    );
                }
            } else {
                $sourcePath = null;
            }

            /*
             * Primero revisamos destino para que el comando sea idempotente.
             * Un archivo correctamente migrado sigue siendo válido aunque
             * más adelante desaparezca el legacy.
             */
            if (is_file($targetPath)) {
                if (! is_readable($targetPath)) {
                    return $this->result(
                        'ERROR_DESTINO_NO_LEGIBLE',
                        'errores',
                        $sourcePath,
                        $targetPath
                    );
                }

                $targetHash = hash_file(
                    'sha256',
                    $targetPath
                );

                if ($targetHash === false) {
                    return $this->result(
                        'ERROR_HASH_DESTINO',
                        'errores',
                        $sourcePath,
                        $targetPath
                    );
                }

                if (
                    is_string($sourceHash)
                    && $sourceHash !== $targetHash
                ) {
                    return $this->result(
                        'ERROR_DESTINO_DIFERENTE',
                        'destino_diferente',
                        $sourcePath,
                        $targetPath,
                        $targetHash
                    );
                }

                if ($verify) {
                    $size = filesize($targetPath);

                    if ($size === false || $size <= 0) {
                        return $this->result(
                            'ERROR_DESTINO_VACIO',
                            'errores',
                            $sourcePath,
                            $targetPath,
                            $targetHash
                        );
                    }

                    return $this->result(
                        'VERIFICADO',
                        'ya_migrados',
                        $sourcePath,
                        $targetPath,
                        $targetHash
                    );
                }

                return $this->result(
                    'DESTINO_EXISTE_MISMO_HASH',
                    'ya_migrados',
                    $sourcePath,
                    $targetPath,
                    $targetHash
                );
            }

            if ($sourcePath === null) {
                return $this->result(
                    'ERROR_ORIGEN_NO_EXISTE',
                    'origen_no_existe',
                    null,
                    $targetPath
                );
            }

            if (! is_readable($sourcePath)) {
                return $this->result(
                    'ERROR_ORIGEN_NO_LEGIBLE',
                    'errores',
                    $sourcePath,
                    $targetPath
                );
            }

            $sourceSize = filesize($sourcePath);

            if ($sourceSize === false || $sourceSize <= 0) {
                return $this->result(
                    'ERROR_ORIGEN_VACIO',
                    'errores',
                    $sourcePath,
                    $targetPath
                );
            }

            if (! is_string($sourceHash)) {
                $sourceHash = hash_file(
                    'sha256',
                    $sourcePath
                );
            }

            if ($sourceHash === false) {
                return $this->result(
                    'ERROR_HASH_ORIGEN',
                    'errores',
                    $sourcePath,
                    $targetPath
                );
            }

            if (! $execute) {
                return $this->result(
                    'MIGRABLE',
                    'migrables',
                    $sourcePath,
                    $targetPath,
                    $sourceHash
                );
            }

            $this->copyVerified(
                $sourcePath,
                $targetPath,
                $sourceHash
            );

            return $this->result(
                'MIGRADO',
                'ejecutados',
                $sourcePath,
                $targetPath,
                $sourceHash
            );
        } catch (Throwable $exception) {
            return $this->result(
                'ERROR: ' . $exception->getMessage(),
                'errores'
            );
        }
    }

    private function copyVerified(
        string $sourcePath,
        string $targetPath,
        string $sourceHash
    ): void {
        $targetDirectory = dirname($targetPath);

        if (
            ! is_dir($targetDirectory)
            && ! mkdir($targetDirectory, 0775, true)
            && ! is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio destino.'
            );
        }

        if (! is_writable($targetDirectory)) {
            throw new RuntimeException(
                'El directorio destino no tiene permisos de escritura.'
            );
        }

        /*
         * Nunca copiamos directamente al nombre definitivo.
         * Primero escribimos y verificamos un temporal.
         */
        $temporaryPath = $targetPath
            . '.tmp.'
            . getmypid()
            . '.'
            . bin2hex(random_bytes(4));

        if (! copy($sourcePath, $temporaryPath)) {
            throw new RuntimeException(
                'No se pudo copiar el archivo temporal.'
            );
        }

        try {
            $temporaryHash = hash_file(
                'sha256',
                $temporaryPath
            );

            if ($temporaryHash !== $sourceHash) {
                throw new RuntimeException(
                    'El hash de la copia temporal no coincide con el origen.'
                );
            }

            if (! rename($temporaryPath, $targetPath)) {
                throw new RuntimeException(
                    'No se pudo establecer el archivo definitivo.'
                );
            }

            @chmod($targetPath, 0640);

            $targetHash = hash_file(
                'sha256',
                $targetPath
            );

            if ($targetHash !== $sourceHash) {
                @unlink($targetPath);

                throw new RuntimeException(
                    'Falló la verificación final SHA-256.'
                );
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function resolveSourceDirectory(): string
    {
        /*
         * Los documentos legacy _docs están físicamente en TalentSafe.
         * En producción paths.images_path apunta al raíz del portal
         * TalentSafe, por lo que el origen es:
         *
         *   {images_path}/_docs/{archivo}
         */
        $imagesPath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        if ($imagesPath === '') {
            throw new RuntimeException(
                'La ruta legacy de TalentSafe no está configurada.'
            );
        }

        $sourceDirectory = $imagesPath
            . DIRECTORY_SEPARATOR
            . '_docs';

        if (! is_dir($sourceDirectory)) {
            throw new RuntimeException(
                'No existe el directorio legacy TalentSafe _docs: '
                . $sourceDirectory
            );
        }

        if (! is_readable($sourceDirectory)) {
            throw new RuntimeException(
                'El directorio legacy TalentSafe _docs no es legible: '
                . $sourceDirectory
            );
        }

        $realPath = realpath($sourceDirectory);

        return $realPath !== false
            ? $realPath
            : $sourceDirectory;
    }

    /**
     * @return array{
     *     candidate: int|null,
     *     portal: int|null,
     *     all: bool,
     *     description: string
     * }
     */
    private function resolveScope(): array
    {
        $candidateValue = trim(
            (string) ($this->argument('candidate') ?? '')
        );

        $portalValue = trim(
            (string) ($this->option('portal') ?? '')
        );

        $all = (bool) $this->option('all');

        $scopeCount = 0;

        if ($candidateValue !== '') {
            $scopeCount++;
        }

        if ($portalValue !== '') {
            $scopeCount++;
        }

        if ($all) {
            $scopeCount++;
        }

        if ($scopeCount === 0) {
            throw new InvalidArgumentException(
                'Indica un candidato, --portal o --all.'
            );
        }

        if ($scopeCount > 1) {
            throw new InvalidArgumentException(
                'Usa un candidato, --portal o --all, no varios alcances a la vez.'
            );
        }

        if ($candidateValue !== '') {
            $candidate = $this->positiveInteger(
                $candidateValue,
                'candidato'
            );

            $exists = DB::connection('rodi_main')
                ->table('candidato_sync')
                ->where(
                    'id_candidato_rodi',
                    $candidate
                )
                ->exists();

            if (! $exists) {
                throw new RuntimeException(
                    "El candidato RODI {$candidate} no tiene sincronización TalentSafe."
                );
            }

            return [
                'candidate' => $candidate,
                'portal' => null,
                'all' => false,
                'description' => "candidato RODI {$candidate}",
            ];
        }

        if ($portalValue !== '') {
            $portal = $this->positiveInteger(
                $portalValue,
                'portal'
            );

            $exists = DB::connection('rodi_main')
                ->table('candidato_sync')
                ->where(
                    'id_portal',
                    $portal
                )
                ->exists();

            if (! $exists) {
                throw new RuntimeException(
                    "No existen candidatos sincronizados para el portal {$portal}."
                );
            }

            return [
                'candidate' => null,
                'portal' => $portal,
                'all' => false,
                'description' => "portal {$portal}",
            ];
        }

        return [
            'candidate' => null,
            'portal' => null,
            'all' => true,
            'description' => 'todos los candidatos RODI sincronizados',
        ];
    }

    private function positiveInteger(
        string $value,
        string $label
    ): int {
        if (! preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new InvalidArgumentException(
                "El ID de {$label} debe ser un entero mayor que cero."
            );
        }

        return (int) $value;
    }

    private function safeFilename(
        string $storedValue
    ): ?string {
        $storedValue = trim($storedValue);

        if ($storedValue === '') {
            return null;
        }

        $filename = basename(
            str_replace(
                '\\',
                '/',
                $storedValue
            )
        );

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
        ) {
            return null;
        }

        return $filename;
    }

    private function createManifestPath(
        array $scope
    ): string {
        $directory = storage_path(
            'app/migration-manifests'
        );

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0750, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de manifiestos.'
            );
        }

        if ($scope['candidate'] !== null) {
            $name = 'candidato_'
                . $scope['candidate'];
        } elseif ($scope['portal'] !== null) {
            $name = 'portal_'
                . $scope['portal'];
        } else {
            $name = 'todos';
        }

        return $directory
            . DIRECTORY_SEPARATOR
            . 'documentos_rodi_'
            . $name
            . '_'
            . now()->format('Ymd_His')
            . '.jsonl';
    }

    private function appendManifest(
        string $manifestPath,
        object $document,
        array $result
    ): void {
        $payload = [
            'fecha' => now()->toIso8601String(),
            'tabla' => 'candidato_documento',
            'registro_id' => (int) $document->document_id,
            'id_candidato_rodi' => (int) $document->id_candidato_rodi,
            'id_portal' => (int) $document->id_portal,
            'archivo_bd' => (string) $document->archivo,
            'origen_fisico' => $result['source_path'],
            'destino_fisico' => $result['target_path'],
            'sha256' => $result['hash'],
            'resultado' => $result['status'],
            'archivo_legacy_conservado' => true,
            'base_datos_modificada' => false,
        ];

        $written = file_put_contents(
            $manifestPath,
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            )
            . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'No se pudo escribir el manifiesto.'
            );
        }
    }

    private function result(
        string $status,
        string $counter,
        ?string $sourcePath = null,
        ?string $targetPath = null,
        ?string $hash = null
    ): array {
        return [
            'status' => $status,
            'counter' => $counter,
            'source_path' => $sourcePath,
            'target_path' => $targetPath,
            'hash' => $hash,
        ];
    }
}
