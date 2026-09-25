<?php

namespace App\Console\Commands;

use App\Models\Evaluacion;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Documents\EvaluationDocumentPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class MigrarEvaluacionesPortal extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-evaluaciones
                            {--evaluacion= : Migrar una evaluación específica}
                            {--portal= : Migrar las evaluaciones de un portal}
                            {--all : Migrar todas las evaluaciones activas con archivo}
                            {--verify-files : Verificar existencia, lectura, tamaño y SHA-256 de rutas definitivas}
                            {--summary : Mostrar únicamente el resumen final}
                            {--execute : Extraer/copiar archivos y actualizar la base de datos}';

    protected $description =
        'Migra evaluaciones legacy de _evaluacionesPortal hacia storagetalentsafe';

    public function handle(
        EvaluationDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): int {
        try {
            $scope = $this->resolveScope();
            $this->validateStorageConfiguration();
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $verify  = (bool) $this->option('verify-files');
        $summary = (bool) $this->option('summary');

        $query = Evaluacion::query()
            ->where('eliminado', 0)
            ->whereNotNull('name_document')
            ->whereRaw("TRIM(name_document) <> ''");

        if ($scope['evaluation'] !== null) {
            $query->where('id', $scope['evaluation']);
        }

        if ($scope['portal'] !== null) {
            $query->where('id_portal', $scope['portal']);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn(
                'No se encontraron evaluaciones activas con archivo para el alcance indicado.'
            );

            return Command::SUCCESS;
        }

        $this->info(
            $execute
                ? 'EJECUCIÓN: se migrarán archivos y se actualizará evaluaciones.name_document.'
                : 'SIMULACIÓN: no se modificarán archivos ni base de datos.'
        );

        $this->line('Alcance: ' . $scope['description']);
        $this->line("Evaluaciones encontradas: {$total}");
        $this->line(
            'Origen legacy: '
            . $this->legacyDirectory()
        );
        $this->line(
            'Destino nuevo: '
            . rtrim((string) config('paths.documents_path'), '/\\')
        );

        if ($execute) {
            $this->newLine();
            $this->warn(
                'Los archivos legacy de _evaluacionesPortal se conservarán durante esta ejecución.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración de {$total} evaluación(es)?",
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
            'revisados'           => 0,
            'legacy'              => 0,
            'migrables'           => 0,
            'ya_migrados'         => 0,
            'ejecutados'          => 0,
            'origen_no_existe'    => 0,
            'destino_diferente'   => 0,
            'errores'             => 0,
        ];

        $rows = [];

        $query
            ->orderBy('id')
            ->chunkById(100, function ($evaluaciones) use (
                &$totals,
                &$rows,
                $execute,
                $verify,
                $summary,
                $documentPaths,
                $auditoria,
                $manifestPath
            ): void {
                foreach ($evaluaciones as $evaluacion) {
                    $totals['revisados']++;

                    $result = $this->processEvaluation(
                        $evaluacion,
                        $execute,
                        $verify,
                        $documentPaths,
                        $auditoria,
                        $manifestPath
                    );

                    if (($result['is_legacy'] ?? false) === true) {
                        $totals['legacy']++;
                    }

                    if (isset($totals[$result['counter']])) {
                        $totals[$result['counter']]++;
                    }

                    if (! $summary) {
                        $rows[] = [
                            (int) $evaluacion->id,
                            (int) $evaluacion->id_portal,
                            (int) $evaluacion->id_cliente,
                            (string) $evaluacion->name_document,
                            $result['target_stored'] ?? '-',
                            $result['hash']
                                ? substr($result['hash'], 0, 16) . '…'
                                : '-',
                            $result['status'],
                        ];
                    }
                }
            });

        if (! empty($rows)) {
            $this->newLine();
            $this->table(
                [
                    'Evaluación',
                    'Portal',
                    'Cliente',
                    'Valor actual',
                    'Ruta propuesta',
                    'SHA-256',
                    'Resultado',
                ],
                $rows
            );
        }

        $this->newLine();
        $this->table(
            ['Concepto', 'Total'],
            [
                ['Revisadas', $totals['revisados']],
                ['Legacy', $totals['legacy']],
                ['Migrables', $totals['migrables']],
                ['Ya migradas', $totals['ya_migrados']],
                ['Ejecutadas', $totals['ejecutados']],
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

    private function processEvaluation(
        Evaluacion $evaluacion,
        bool $execute,
        bool $verify,
        EvaluationDocumentPathService $documentPaths,
        AuditoriaService $auditoria,
        ?string $manifestPath
    ): array {
        $storedValue = trim(
            str_replace(
                '\\',
                '/',
                (string) $evaluacion->name_document
            )
        );

        if (str_starts_with($storedValue, 'portales/')) {
            return $this->processDefinitivePath(
                $storedValue,
                $verify,
                $documentPaths
            );
        }

        $prepared = null;

        try {
            $prepared = $this->prepareLegacySource($storedValue);

            if ($prepared === null) {
                return $this->result(
                    'ORIGEN_NO_EXISTE',
                    'origen_no_existe',
                    true
                );
            }

            $sourcePath = $prepared['materialized_path'];

            if (! is_file($sourcePath)) {
                return $this->result(
                    'ORIGEN_NO_EXISTE',
                    'origen_no_existe',
                    true
                );
            }

            if (! is_readable($sourcePath)) {
                return $this->result(
                    'ERROR_ORIGEN_NO_LEGIBLE',
                    'errores',
                    true
                );
            }

            $sourceSize = filesize($sourcePath);

            if ($sourceSize === false || $sourceSize <= 0) {
                return $this->result(
                    'ERROR_ORIGEN_VACIO',
                    'errores',
                    true
                );
            }

            $sourceHash = hash_file('sha256', $sourcePath);

            if ($sourceHash === false) {
                return $this->result(
                    'ERROR_HASH_ORIGEN',
                    'errores',
                    true
                );
            }

            $extension = $this->safeExtension(
                (string) $prepared['extension']
            );

            if ($extension === null) {
                return $this->result(
                    'ERROR_EXTENSION_NO_PERMITIDA',
                    'errores',
                    true,
                    null,
                    $sourceHash
                );
            }

            $physicalName = 'eval_'
                . (int) $evaluacion->id
                . '_'
                . substr($sourceHash, 0, 24)
                . '.'
                . $extension;

            $targetStored = $documentPaths->storedPath(
                (int) $evaluacion->id_portal,
                (int) $evaluacion->id_cliente,
                $physicalName
            );

            if (strlen($targetStored) > 255) {
                return $this->result(
                    'ERROR_RUTA_MAYOR_255',
                    'errores',
                    true,
                    $targetStored,
                    $sourceHash
                );
            }

            $targetPath = $this->targetAbsolutePath(
                $targetStored
            );

            $targetExists = is_file($targetPath);

            if ($targetExists) {
                if (! is_readable($targetPath)) {
                    return $this->result(
                        'ERROR_DESTINO_NO_LEGIBLE',
                        'errores',
                        true,
                        $targetStored,
                        $sourceHash
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
                        true,
                        $targetStored,
                        $sourceHash
                    );
                }

                if ($targetHash !== $sourceHash) {
                    return $this->result(
                        'ERROR_DESTINO_DIFERENTE',
                        'destino_diferente',
                        true,
                        $targetStored,
                        $sourceHash
                    );
                }
            }

            if (! $execute) {
                return $this->result(
                    $targetExists
                        ? 'MIGRABLE_DESTINO_EXISTE_MISMO_HASH'
                        : 'MIGRABLE',
                    'migrables',
                    true,
                    $targetStored,
                    $sourceHash
                );
            }

            $targetCreated = false;

            if (! $targetExists) {
                $this->copyVerified(
                    $sourcePath,
                    $targetPath,
                    $sourceHash
                );

                $targetCreated = true;
            }

            try {
                $this->updateStoredPath(
                    (int) $evaluacion->id,
                    $storedValue,
                    $targetStored
                );
            } catch (Throwable $exception) {
                if ($targetCreated) {
                    @unlink($targetPath);
                }

                throw $exception;
            }

            $auditResult = $auditoria->registrar([
                'id_portal'    => (int) $evaluacion->id_portal,
                'id_cliente'   => (int) $evaluacion->id_cliente,
                'actor_tipo'   => 'sistema',
                'actor_nombre' => 'Comando Artisan',

                'modulo'       => 'empleados',
                'entidad_tipo' => 'evaluacion',
                'entidad_id'   => (int) $evaluacion->id,

                'accion'       => 'migrar_archivo_legacy',
                'resultado'    => 'exitoso',

                'descripcion'  =>
                    'Se migró una evaluación legacy a storagetalentsafe.',

                'datos_anteriores' => [
                    'name_document' => $storedValue,
                ],

                'datos_nuevos' => [
                    'name_document' => $targetStored,
                ],

                'metadatos' => [
                    'origen_fisico' =>
                        $prepared['legacy_path'],
                    'origen_tipo' =>
                        $prepared['source_type'],
                    'entrada_zip' =>
                        $prepared['zip_entry'],
                    'destino_fisico' =>
                        $targetPath,
                    'tamano_bytes' =>
                        filesize($targetPath),
                    'sha256' =>
                        $sourceHash,
                    'archivo_legacy_conservado' =>
                        true,
                ],
            ]);

            if ($manifestPath !== null) {
                $this->appendManifest(
                    $manifestPath,
                    $evaluacion,
                    $storedValue,
                    $targetStored,
                    $prepared,
                    $targetPath,
                    $sourceHash,
                    $auditResult !== null
                );
            }

            return $this->result(
                'MIGRADO',
                'ejecutados',
                true,
                $targetStored,
                $sourceHash
            );
        } catch (Throwable $exception) {
            return $this->result(
                'ERROR: ' . $exception->getMessage(),
                'errores',
                true,
                null,
                null
            );
        } finally {
            if (
                is_array($prepared)
                && ($prepared['cleanup'] ?? false) === true
                && isset($prepared['materialized_path'])
                && is_string($prepared['materialized_path'])
                && is_file($prepared['materialized_path'])
            ) {
                @unlink($prepared['materialized_path']);
            }
        }
    }

    private function processDefinitivePath(
        string $storedValue,
        bool $verify,
        EvaluationDocumentPathService $documentPaths
    ): array {
        if (! $verify) {
            return $this->result(
                'YA_MIGRADO',
                'ya_migrados',
                false,
                $storedValue
            );
        }

        try {
            $absolutePath = $documentPaths->existingAbsolutePath(
                $storedValue
            );

            if (! is_readable($absolutePath)) {
                return $this->result(
                    'ERROR_DESTINO_NO_LEGIBLE',
                    'errores',
                    false,
                    $storedValue
                );
            }

            $size = filesize($absolutePath);

            if ($size === false || $size <= 0) {
                return $this->result(
                    'ERROR_DESTINO_VACIO',
                    'errores',
                    false,
                    $storedValue
                );
            }

            $hash = hash_file(
                'sha256',
                $absolutePath
            );

            if ($hash === false) {
                return $this->result(
                    'ERROR_HASH_DESTINO',
                    'errores',
                    false,
                    $storedValue
                );
            }

            return $this->result(
                'VERIFICADO',
                'ya_migrados',
                false,
                $storedValue,
                $hash
            );
        } catch (Throwable $exception) {
            return $this->result(
                'ERROR_VERIFICACION: '
                . $exception->getMessage(),
                'errores',
                false,
                $storedValue
            );
        }
    }

    /**
     * @return array{
     *     materialized_path: string,
     *     legacy_path: string,
     *     source_type: string,
     *     zip_entry: string|null,
     *     extension: string,
     *     cleanup: bool
     * }|null
     */
    private function prepareLegacySource(
        string $storedValue
    ): ?array {
        $fileName = $this->safeFilename(
            $storedValue
        );

        if ($fileName === null) {
            throw new InvalidArgumentException(
                'El nombre legacy de la evaluación no es válido.'
            );
        }

        $legacyDirectory = $this->legacyDirectory();

        $directPath = $legacyDirectory
            . DIRECTORY_SEPARATOR
            . $fileName;

        if (is_file($directPath)) {
            return [
                'materialized_path' => $directPath,
                'legacy_path'       => $directPath,
                'source_type'       => 'ARCHIVO_LEGACY',
                'zip_entry'         => null,
                'extension'         => strtolower(
                    (string) pathinfo(
                        $directPath,
                        PATHINFO_EXTENSION
                    )
                ),
                'cleanup'           => false,
            ];
        }

        $zipPath = $directPath . '.zip';

        if (! is_file($zipPath)) {
            return null;
        }

        if (! is_readable($zipPath)) {
            throw new RuntimeException(
                'El ZIP legacy no es legible: ' . $zipPath
            );
        }

        $zip = new ZipArchive();

        $openResult = $zip->open(
            $zipPath,
            ZipArchive::RDONLY
        );

        if ($openResult !== true) {
            throw new RuntimeException(
                'No se pudo abrir el ZIP legacy: ' . $zipPath
            );
        }

        $tempPath = null;

        try {
            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);

                if (
                    $entryName === false
                    || $entryName === ''
                    || str_ends_with($entryName, '/')
                ) {
                    continue;
                }

                $entries[] = $entryName;
            }

            if (count($entries) !== 1) {
                throw new RuntimeException(
                    'El ZIP legacy debe contener exactamente un archivo; contiene '
                    . count($entries)
                    . '.'
                );
            }

            $entryName = $entries[0];

            $extension = strtolower(
                (string) pathinfo(
                    $entryName,
                    PATHINFO_EXTENSION
                )
            );

            if ($extension === '') {
                $extension = strtolower(
                    (string) pathinfo(
                        $fileName,
                        PATHINFO_EXTENSION
                    )
                );
            }

            $tempDirectory = storage_path(
                'app/migration-temp/evaluaciones'
            );

            if (
                ! is_dir($tempDirectory)
                && ! mkdir($tempDirectory, 0750, true)
                && ! is_dir($tempDirectory)
            ) {
                throw new RuntimeException(
                    'No se pudo crear el directorio temporal de migración.'
                );
            }

            $tempPath = tempnam(
                $tempDirectory,
                'eval_'
            );

            if ($tempPath === false) {
                throw new RuntimeException(
                    'No se pudo crear el archivo temporal de extracción.'
                );
            }

            $input = $zip->getStream($entryName);

            if ($input === false) {
                throw new RuntimeException(
                    'No se pudo abrir la entrada del ZIP legacy.'
                );
            }

            $output = fopen($tempPath, 'wb');

            if ($output === false) {
                fclose($input);

                throw new RuntimeException(
                    'No se pudo abrir el temporal para escritura.'
                );
            }

            try {
                $copied = stream_copy_to_stream(
                    $input,
                    $output
                );

                if ($copied === false || $copied <= 0) {
                    throw new RuntimeException(
                        'No se pudo extraer el contenido del ZIP legacy.'
                    );
                }
            } finally {
                fclose($input);
                fclose($output);
            }

            return [
                'materialized_path' => $tempPath,
                'legacy_path'       => $zipPath,
                'source_type'       => 'ZIP_LEGACY',
                'zip_entry'         => $entryName,
                'extension'         => $extension,
                'cleanup'           => true,
            ];
        } catch (Throwable $exception) {
            if (
                is_string($tempPath)
                && is_file($tempPath)
            ) {
                @unlink($tempPath);
            }

            throw $exception;
        } finally {
            $zip->close();
        }
    }

    private function updateStoredPath(
        int $evaluationId,
        string $previousStoredValue,
        string $targetStoredValue
    ): void {
        DB::connection('portal_main')->transaction(
            function () use (
                $evaluationId,
                $previousStoredValue,
                $targetStoredValue
            ): void {
                /** @var Evaluacion|null $current */
                $current = Evaluacion::query()
                    ->lockForUpdate()
                    ->find($evaluationId);

                if (! $current) {
                    throw new RuntimeException(
                        'La evaluación dejó de existir.'
                    );
                }

                if ((int) $current->eliminado !== 0) {
                    throw new RuntimeException(
                        'La evaluación fue eliminada durante la migración.'
                    );
                }

                $currentStoredValue = trim(
                    str_replace(
                        '\\',
                        '/',
                        (string) $current->name_document
                    )
                );

                if ($currentStoredValue !== $previousStoredValue) {
                    throw new RuntimeException(
                        'La ruta cambió después de la simulación.'
                    );
                }

                $current->name_document = $targetStoredValue;
                $current->save();
            }
        );
    }

    private function copyVerified(
        string $sourcePath,
        string $targetPath,
        string $sourceHash
    ): void {
        $targetDirectory = dirname(
            $targetPath
        );

        if (
            ! is_dir($targetDirectory)
            && ! mkdir($targetDirectory, 0750, true)
            && ! is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de destino.'
            );
        }

        if (! is_writable($targetDirectory)) {
            throw new RuntimeException(
                'El directorio de destino no tiene permisos de escritura.'
            );
        }

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

    private function targetAbsolutePath(
        string $targetStoredValue
    ): string {
        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        if ($documentsPath === '') {
            throw new RuntimeException(
                'La ruta documental nueva no está configurada.'
            );
        }

        return $documentsPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                ltrim($targetStoredValue, '/\\')
            );
    }

    private function legacyDirectory(): string
    {
        $imagesPath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        if ($imagesPath === '') {
            throw new RuntimeException(
                'La ruta legacy no está configurada.'
            );
        }

        return $imagesPath
            . DIRECTORY_SEPARATOR
            . '_evaluacionesPortal';
    }

    private function validateStorageConfiguration(): void
    {
        $legacyDirectory = $this->legacyDirectory();

        if (! is_dir($legacyDirectory)) {
            throw new RuntimeException(
                'No existe el directorio legacy _evaluacionesPortal: '
                . $legacyDirectory
            );
        }

        if (! is_readable($legacyDirectory)) {
            throw new RuntimeException(
                'El directorio legacy _evaluacionesPortal no es legible.'
            );
        }

        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        if ($documentsPath === '') {
            throw new RuntimeException(
                'La ruta storagetalentsafe no está configurada.'
            );
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(
                'La extensión PHP ZipArchive no está disponible.'
            );
        }
    }

    private function safeFilename(
        string $storedValue
    ): ?string {
        $storedValue = trim(
            str_replace('\\', '/', $storedValue)
        );

        if (
            $storedValue === ''
            || str_contains($storedValue, "\0")
            || str_contains($storedValue, '/')
            || $storedValue === '.'
            || $storedValue === '..'
        ) {
            return null;
        }

        return basename($storedValue);
    }

    private function safeExtension(
        string $extension
    ): ?string {
        $extension = strtolower(
            preg_replace(
                '/[^a-z0-9]/',
                '',
                $extension
            ) ?? ''
        );

        return in_array(
            $extension,
            ['pdf', 'jpg', 'jpeg', 'png'],
            true
        )
            ? $extension
            : null;
    }

    /**
     * @return array{
     *     evaluation: int|null,
     *     portal: int|null,
     *     all: bool,
     *     description: string
     * }
     */
    private function resolveScope(): array
    {
        $evaluationValue = trim(
            (string) ($this->option('evaluacion') ?? '')
        );

        $portalValue = trim(
            (string) ($this->option('portal') ?? '')
        );

        $all = (bool) $this->option('all');

        $scopeCount = 0;

        if ($evaluationValue !== '') {
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
                'Indica --evaluacion, --portal o --all.'
            );
        }

        if ($scopeCount > 1) {
            throw new InvalidArgumentException(
                'Usa --evaluacion, --portal o --all, no varios alcances a la vez.'
            );
        }

        if ($evaluationValue !== '') {
            $evaluationId = $this->positiveInteger(
                $evaluationValue,
                'evaluación'
            );

            return [
                'evaluation' => $evaluationId,
                'portal'      => null,
                'all'         => false,
                'description' => "evaluación {$evaluationId}",
            ];
        }

        if ($portalValue !== '') {
            $portalId = $this->positiveInteger(
                $portalValue,
                'portal'
            );

            return [
                'evaluation' => null,
                'portal'      => $portalId,
                'all'         => false,
                'description' => "portal {$portalId}",
            ];
        }

        return [
            'evaluation' => null,
            'portal'      => null,
            'all'         => true,
            'description' => 'todas las evaluaciones activas con archivo',
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

        if ($scope['evaluation'] !== null) {
            $name = 'evaluacion_'
                . $scope['evaluation'];
        } elseif ($scope['portal'] !== null) {
            $name = 'portal_'
                . $scope['portal'];
        } else {
            $name = 'todas';
        }

        return $directory
            . DIRECTORY_SEPARATOR
            . 'evaluaciones_portal_'
            . $name
            . '_'
            . now()->format('Ymd_His')
            . '.jsonl';
    }

    private function appendManifest(
        string $manifestPath,
        Evaluacion $evaluacion,
        string $previousStoredValue,
        string $targetStoredValue,
        array $prepared,
        string $targetPath,
        string $hash,
        bool $auditRegistered
    ): void {
        $payload = [
            'fecha'                => now()->toIso8601String(),
            'tabla'                => 'evaluaciones',
            'registro_id'          => (int) $evaluacion->id,
            'id_portal'            => (int) $evaluacion->id_portal,
            'id_cliente'           => (int) $evaluacion->id_cliente,
            'columna_ruta'         => 'name_document',
            'ruta_anterior'        => $previousStoredValue,
            'ruta_nueva'           => $targetStoredValue,
            'origen_fisico'        => $prepared['legacy_path'],
            'origen_tipo'          => $prepared['source_type'],
            'entrada_zip'          => $prepared['zip_entry'],
            'destino_fisico'       => $targetPath,
            'tamano_bytes'         => filesize($targetPath),
            'sha256_origen'        => $hash,
            'sha256_destino'       => hash_file('sha256', $targetPath),
            'auditoria_registrada' => $auditRegistered,
            'archivo_legacy_conservado' => true,
            'resultado'            => 'MIGRADO',
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
                'Migración realizada, pero no se pudo escribir el manifiesto.'
            );
        }
    }

    private function result(
        string $status,
        string $counter,
        bool $isLegacy,
        ?string $targetStored = null,
        ?string $hash = null
    ): array {
        return [
            'status'        => $status,
            'counter'       => $counter,
            'is_legacy'     => $isLegacy,
            'target_stored' => $targetStored,
            'hash'          => $hash,
        ];
    }
}
