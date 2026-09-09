<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MigrarDocumentosBolsa extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-bolsa
                            {--portal= : Limitar la migración a un portal}
                            {--bolsa= : Limitar la migración a una bolsa}
                            {--verify-files : Verificar los archivos ya existentes en destino}
                            {--summary : Mostrar únicamente el resumen final}
                            {--execute : Copiar físicamente los archivos}';

    protected $description =
        'Migra documentos de Bolsa de Trabajo hacia storagetalentsafe';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $verify  = (bool) $this->option('verify-files');
        $summary = (bool) $this->option('summary');
        $portal  = $this->positiveOption('portal');
        $bolsa   = $this->positiveOption('bolsa');

        if ($this->option('portal') !== null && $portal === null) {
            $this->error('--portal debe ser un entero mayor que cero.');

            return Command::INVALID;
        }

        if ($this->option('bolsa') !== null && $bolsa === null) {
            $this->error('--bolsa debe ser un entero mayor que cero.');

            return Command::INVALID;
        }

        $query = DB::connection('portal_main')
            ->table('documentos_bolsa as db')
            ->join('bolsa_trabajo as bt', 'bt.id', '=', 'db.id_bolsa')
            ->select([
                'db.id',
                'db.id_bolsa',
                'db.nombre_archivo',
                'bt.id_portal',
            ])
            ->where('db.eliminado', 0);

        if ($portal !== null) {
            $query->where('bt.id_portal', $portal);
        }

        if ($bolsa !== null) {
            $query->where('db.id_bolsa', $bolsa);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No se encontraron documentos activos para el alcance indicado.');

            return Command::SUCCESS;
        }

        $this->info(
            $execute
                ? 'EJECUCIÓN: se copiarán archivos al almacenamiento definitivo.'
                : 'SIMULACIÓN: no se modificarán archivos ni base de datos.'
        );

        $this->line("Documentos encontrados: {$total}");

        if ($portal !== null) {
            $this->line("Portal: {$portal}");
        }

        if ($bolsa !== null) {
            $this->line("Bolsa: {$bolsa}");
        }

        if ($execute) {
            $this->warn(
                'Los archivos legacy de _documentosBolsa se conservarán.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración de {$total} documento(s)?",
                false
            )) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        }

        $this->newLine();

        $totals = [
            'revisados'         => 0,
            'migrables'         => 0,
            'ya_migrados'       => 0,
            'ejecutados'        => 0,
            'origen_no_existe'  => 0,
            'destino_diferente' => 0,
            'errores'           => 0,
        ];

        $rows = [];

        $query
            ->orderBy('db.id')
            ->chunk(200, function ($documents) use (
                &$totals,
                &$rows,
                $execute,
                $verify,
                $summary
            ) {
                foreach ($documents as $document) {
                    $totals['revisados']++;

                    $result = $this->processDocument(
                        $document,
                        $execute,
                        $verify
                    );

                    if (isset($totals[$result['counter']])) {
                        $totals[$result['counter']]++;
                    }
                    if (! $summary) {
                        $rows[] = [
                            $document->id,
                            $document->id_portal,
                            $document->id_bolsa,
                            $document->nombre_archivo,
                            $result['status'],
                        ];
                    }
                }
            });

        if (! empty($rows)) {
            $this->table(
                [
                    'Documento',
                    'Portal',
                    'Bolsa',
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
                ['Origen no existe', $totals['origen_no_existe']],
                ['Destino diferente', $totals['destino_diferente']],
                ['Errores', $totals['errores']],
            ]
        );

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
        bool $verify
    ): array {
        try {
            $filename = $this->safeFilename(
                (string) $document->nombre_archivo
            );

            if ($filename === null) {
                return [
                    'status'  => 'ERROR_NOMBRE_INVALIDO',
                    'counter' => 'errores',
                ];
            }

            $sourcePath = $this->legacyPath($filename);

            $targetPath = $this->targetPath(
                (int) $document->id_portal,
                (int) $document->id_bolsa,
                $filename
            );

            /*
             * Primero revisamos destino.
             *
             * Esto permite que el comando sea idempotente incluso si
             * posteriormente eliminamos el almacenamiento legacy.
             */
            if (is_file($targetPath)) {
                if (! is_readable($targetPath)) {
                    return [
                        'status'  => 'ERROR_DESTINO_NO_LEGIBLE',
                        'counter' => 'errores',
                    ];
                }

                $targetHash = hash_file('sha256', $targetPath);

                if ($targetHash === false) {
                    return [
                        'status'  => 'ERROR_HASH_DESTINO',
                        'counter' => 'errores',
                    ];
                }

                /*
                 * Si todavía existe el legacy, comparamos ambos.
                 */
                if (is_file($sourcePath)) {
                    $sourceHash = hash_file('sha256', $sourcePath);

                    if ($sourceHash === false) {
                        return [
                            'status'  => 'ERROR_HASH_ORIGEN',
                            'counter' => 'errores',
                        ];
                    }

                    if ($sourceHash !== $targetHash) {
                        return [
                            'status'  => 'ERROR_DESTINO_DIFERENTE',
                            'counter' => 'destino_diferente',
                        ];
                    }
                }

                if ($verify) {
                    $size = filesize($targetPath);

                    if ($size === false || $size <= 0) {
                        return [
                            'status'  => 'ERROR_DESTINO_VACIO',
                            'counter' => 'errores',
                        ];
                    }

                    return [
                        'status'  => 'VERIFICADO',
                        'counter' => 'ya_migrados',
                    ];
                }

                return [
                    'status'  => 'DESTINO_EXISTE_MISMO_HASH',
                    'counter' => 'ya_migrados',
                ];
            }

            if (! is_file($sourcePath)) {
                return [
                    'status'  => 'ERROR_ORIGEN_NO_EXISTE',
                    'counter' => 'origen_no_existe',
                ];
            }

            if (! is_readable($sourcePath)) {
                return [
                    'status'  => 'ERROR_ORIGEN_NO_LEGIBLE',
                    'counter' => 'errores',
                ];
            }

            $sourceSize = filesize($sourcePath);

            if ($sourceSize === false || $sourceSize <= 0) {
                return [
                    'status'  => 'ERROR_ORIGEN_VACIO',
                    'counter' => 'errores',
                ];
            }

            $sourceHash = hash_file('sha256', $sourcePath);

            if ($sourceHash === false) {
                return [
                    'status'  => 'ERROR_HASH_ORIGEN',
                    'counter' => 'errores',
                ];
            }

            if (! $execute) {
                return [
                    'status'  => 'MIGRABLE',
                    'counter' => 'migrables',
                ];
            }

            $this->copyVerified(
                $sourcePath,
                $targetPath,
                $sourceHash
            );

            return [
                'status'  => 'MIGRADO',
                'counter' => 'ejecutados',
            ];
        } catch (Throwable $exception) {
            return [
                'status'  => 'ERROR: ' . $exception->getMessage(),
                'counter' => 'errores',
            ];
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

            /*
             * rename dentro del mismo filesystem evita dejar un destino
             * definitivo parcialmente escrito.
             */
            if (! rename($temporaryPath, $targetPath)) {
                throw new RuntimeException(
                    'No se pudo establecer el archivo definitivo.'
                );
            }

            @chmod($targetPath, 0640);

            $targetHash = hash_file('sha256', $targetPath);

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

    private function legacyPath(string $filename): string
    {
        return rtrim(
            (string) config('paths.images_path'),
            '/\\'
        )
            . DIRECTORY_SEPARATOR
            . '_documentosBolsa'
            . DIRECTORY_SEPARATOR
            . $filename;
    }

    private function targetPath(
        int $portal,
        int $bolsa,
        string $filename
    ): string {
        if ($portal <= 0 || $bolsa <= 0) {
            throw new RuntimeException(
                'Portal o bolsa inválidos.'
            );
        }

        return rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        )
            . DIRECTORY_SEPARATOR
            . 'portales'
            . DIRECTORY_SEPARATOR
            . $portal
            . DIRECTORY_SEPARATOR
            . 'bolsa_trabajo'
            . DIRECTORY_SEPARATOR
            . $bolsa
            . DIRECTORY_SEPARATOR
            . 'documentos'
            . DIRECTORY_SEPARATOR
            . $filename;
    }

    private function safeFilename(string $storedValue): ?string
    {
        $storedValue = trim($storedValue);

        if ($storedValue === '') {
            return null;
        }

        $filename = basename(
            str_replace('\\', '/', $storedValue)
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

    private function positiveOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            ) === false
        ) {
            return null;
        }

        return (int) $value;
    }
}
