<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class MigrarDocumentosAspirante extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-aspirantes
                            {--portal= : Limitar la migración a un portal}
                            {--aspirante= : Limitar por requisicion_aspirante.id}
                            {--verify-files : Verificar los archivos ya existentes en destino}
                            {--summary : Mostrar únicamente el resumen final}
                            {--execute : Copiar físicamente los archivos}';

    protected $description =
        'Migra documentos_aspirante desde _docs hacia storagetalentsafe';

    public function handle(): int
    {
        $execute   = (bool) $this->option('execute');
        $verify    = (bool) $this->option('verify-files');
        $summary   = (bool) $this->option('summary');
        $portal    = $this->positiveOption('portal');
        $aspirante = $this->positiveOption('aspirante');

        if (
            $this->option('portal') !== null
            && $portal === null
        ) {
            $this->error(
                '--portal debe ser un entero mayor que cero.'
            );

            return Command::INVALID;
        }

        if (
            $this->option('aspirante') !== null
            && $aspirante === null
        ) {
            $this->error(
                '--aspirante debe ser un entero mayor que cero.'
            );

            return Command::INVALID;
        }

        /*
         * documentos_aspirante.id_aspirante corresponde a
         * requisicion_aspirante.id.
         */
        $query = DB::connection('portal_main')
            ->table('documentos_aspirante as da')
            ->leftJoin(
                'requisicion_aspirante as ra',
                'ra.id',
                '=',
                'da.id_aspirante'
            )
            ->leftJoin(
                'bolsa_trabajo as bt',
                'bt.id',
                '=',
                'ra.id_bolsa_trabajo'
            )
            ->leftJoin(
                'requisicion as r',
                'r.id',
                '=',
                'ra.id_requisicion'
            )
            ->select([
                'da.id',
                'da.id_aspirante',
                'da.nombre_archivo',
                'ra.id_bolsa_trabajo',
                'ra.id_requisicion',
                'bt.id_portal as bolsa_portal',
                'r.id_portal as requisicion_portal',
            ])
            ->where('da.eliminado', 0);

        if ($portal !== null) {
            $query->where(function ($q) use ($portal) {
                $q->where('bt.id_portal', $portal)
                    ->orWhere('r.id_portal', $portal);
            });
        }

        if ($aspirante !== null) {
            $query->where(
                'da.id_aspirante',
                $aspirante
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

        $this->line(
            "Documentos encontrados: {$total}"
        );

        if ($portal !== null) {
            $this->line(
                "Portal: {$portal}"
            );
        }

        if ($aspirante !== null) {
            $this->line(
                "Requisicion aspirante: {$aspirante}"
            );
        }

        if ($execute) {
            $this->warn(
                'Los archivos legacy de _docs se conservarán.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración de {$total} documento(s)?",
                false
            )) {
                $this->info(
                    'Operación cancelada.'
                );

                return Command::SUCCESS;
            }
        }

        $this->newLine();

        $totals = [
            'revisados'             => 0,
            'migrables'             => 0,
            'ya_migrados'           => 0,
            'ejecutados'            => 0,
            'urls_externas'         => 0,
            'origen_no_existe'      => 0,
            'portal_inconsistente'  => 0,
            'relacion_invalida'     => 0,
            'destino_diferente'     => 0,
            'errores'               => 0,
        ];

        $rows = [];

        $query
            ->orderBy('da.id')
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

                    if (
                        isset(
                            $totals[$result['counter']]
                        )
                    ) {
                        $totals[
                            $result['counter']
                        ]++;
                    }

                    if (! $summary) {
                        $rows[] = [
                            $document->id,
                            $document->id_aspirante,
                            $result['portal'] ?? '-',
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
                    'RA',
                    'Portal',
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
                [
                    'Revisados',
                    $totals['revisados'],
                ],
                [
                    'Migrables',
                    $totals['migrables'],
                ],
                [
                    'Ya migrados',
                    $totals['ya_migrados'],
                ],
                [
                    'Ejecutados',
                    $totals['ejecutados'],
                ],
                [
                    'URLs externas',
                    $totals['urls_externas'],
                ],
                [
                    'Origen no existe',
                    $totals['origen_no_existe'],
                ],
                [
                    'Portal inconsistente',
                    $totals['portal_inconsistente'],
                ],
                [
                    'Relación inválida',
                    $totals['relacion_invalida'],
                ],
                [
                    'Destino diferente',
                    $totals['destino_diferente'],
                ],
                [
                    'Errores',
                    $totals['errores'],
                ],
            ]
        );

        if (
            $totals['errores'] > 0
            || $totals['destino_diferente'] > 0
            || $totals['portal_inconsistente'] > 0
            || $totals['relacion_invalida'] > 0
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
            $portalBolsa = (int) (
                $document->bolsa_portal ?? 0
            );

            $portalRequisicion = (int) (
                $document->requisicion_portal ?? 0
            );

            /*
             * Si existen ambos portales deben coincidir.
             */
            if (
                $portalBolsa > 0
                && $portalRequisicion > 0
                && $portalBolsa !== $portalRequisicion
            ) {
                return [
                    'status' =>
                        'ERROR_PORTAL_INCONSISTENTE',
                    'counter' =>
                        'portal_inconsistente',
                    'portal' => '-',
                ];
            }

            $portal = $portalBolsa > 0
                ? $portalBolsa
                : $portalRequisicion;

            $idRequisicionAspirante = (int) (
                $document->id_aspirante ?? 0
            );

            if (
                $portal <= 0
                || $idRequisicionAspirante <= 0
            ) {
                return [
                    'status' =>
                        'ERROR_RELACION_INVALIDA',
                    'counter' =>
                        'relacion_invalida',
                    'portal' => $portal ?: '-',
                ];
            }

            $storedValue = trim(
                (string) $document->nombre_archivo
            );

            if (
                filter_var(
                    $storedValue,
                    FILTER_VALIDATE_URL
                ) !== false
                && in_array(
                    strtolower(
                        (string) parse_url(
                            $storedValue,
                            PHP_URL_SCHEME
                        )
                    ),
                    ['http', 'https'],
                    true
                )
            ) {
                return [
                    'status'  => 'URL_EXTERNA',
                    'counter' => 'urls_externas',
                    'portal'  => $portal,
                ];
            }

            $filename = $this->safeFilename(
                $storedValue
            );

            if ($filename === null) {
                return [
                    'status' =>
                        'ERROR_NOMBRE_INVALIDO',
                    'counter' =>
                        'errores',
                    'portal' =>
                        $portal,
                ];
            }

            $sourcePath = $this->legacyPath(
                $filename
            );

            $targetPath = $this->targetPath(
                $portal,
                $idRequisicionAspirante,
                $filename
            );

            /*
             * Primero revisamos el destino.
             * Permite reejecutar el comando de forma segura.
             */
            if (is_file($targetPath)) {
                if (! is_readable($targetPath)) {
                    return [
                        'status' =>
                            'ERROR_DESTINO_NO_LEGIBLE',
                        'counter' =>
                            'errores',
                        'portal' =>
                            $portal,
                    ];
                }

                $targetHash = hash_file(
                    'sha256',
                    $targetPath
                );

                if ($targetHash === false) {
                    return [
                        'status' =>
                            'ERROR_HASH_DESTINO',
                        'counter' =>
                            'errores',
                        'portal' =>
                            $portal,
                    ];
                }

                /*
                 * Si todavía existe legacy,
                 * comparar ambos archivos.
                 */
                if (is_file($sourcePath)) {
                    $sourceHash = hash_file(
                        'sha256',
                        $sourcePath
                    );

                    if ($sourceHash === false) {
                        return [
                            'status' =>
                                'ERROR_HASH_ORIGEN',
                            'counter' =>
                                'errores',
                            'portal' =>
                                $portal,
                        ];
                    }

                    if (
                        $sourceHash !== $targetHash
                    ) {
                        return [
                            'status' =>
                                'ERROR_DESTINO_DIFERENTE',
                            'counter' =>
                                'destino_diferente',
                            'portal' =>
                                $portal,
                        ];
                    }
                }

                if ($verify) {
                    $size = filesize(
                        $targetPath
                    );

                    if (
                        $size === false
                        || $size <= 0
                    ) {
                        return [
                            'status' =>
                                'ERROR_DESTINO_VACIO',
                            'counter' =>
                                'errores',
                            'portal' =>
                                $portal,
                        ];
                    }

                    return [
                        'status' =>
                            'VERIFICADO',
                        'counter' =>
                            'ya_migrados',
                        'portal' =>
                            $portal,
                    ];
                }

                return [
                    'status' =>
                        'DESTINO_EXISTE_MISMO_HASH',
                    'counter' =>
                        'ya_migrados',
                    'portal' =>
                        $portal,
                ];
            }

            if (! is_file($sourcePath)) {
                return [
                    'status' =>
                        'ERROR_ORIGEN_NO_EXISTE',
                    'counter' =>
                        'origen_no_existe',
                    'portal' =>
                        $portal,
                ];
            }

            if (! is_readable($sourcePath)) {
                return [
                    'status' =>
                        'ERROR_ORIGEN_NO_LEGIBLE',
                    'counter' =>
                        'errores',
                    'portal' =>
                        $portal,
                ];
            }

            $sourceSize = filesize(
                $sourcePath
            );

            if (
                $sourceSize === false
                || $sourceSize <= 0
            ) {
                return [
                    'status' =>
                        'ERROR_ORIGEN_VACIO',
                    'counter' =>
                        'errores',
                    'portal' =>
                        $portal,
                ];
            }

            $sourceHash = hash_file(
                'sha256',
                $sourcePath
            );

            if ($sourceHash === false) {
                return [
                    'status' =>
                        'ERROR_HASH_ORIGEN',
                    'counter' =>
                        'errores',
                    'portal' =>
                        $portal,
                ];
            }

            if (! $execute) {
                return [
                    'status' =>
                        'MIGRABLE',
                    'counter' =>
                        'migrables',
                    'portal' =>
                        $portal,
                ];
            }

            $this->copyVerified(
                $sourcePath,
                $targetPath,
                $sourceHash
            );

            return [
                'status' =>
                    'MIGRADO',
                'counter' =>
                    'ejecutados',
                'portal' =>
                    $portal,
            ];
        } catch (Throwable $exception) {
            return [
                'status' =>
                    'ERROR: '
                    . $exception->getMessage(),
                'counter' =>
                    'errores',
                'portal' =>
                    $portal ?? '-',
            ];
        }
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
            && ! mkdir(
                $targetDirectory,
                0775,
                true
            )
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
         * Copia primero a temporal.
         */
        $temporaryPath =
            $targetPath
            . '.tmp.'
            . getmypid()
            . '.'
            . bin2hex(
                random_bytes(4)
            );

        if (! copy(
            $sourcePath,
            $temporaryPath
        )) {
            throw new RuntimeException(
                'No se pudo copiar el archivo temporal.'
            );
        }

        try {
            $temporaryHash = hash_file(
                'sha256',
                $temporaryPath
            );

            if (
                $temporaryHash !== $sourceHash
            ) {
                throw new RuntimeException(
                    'El hash de la copia temporal no coincide con el origen.'
                );
            }

            if (! rename(
                $temporaryPath,
                $targetPath
            )) {
                throw new RuntimeException(
                    'No se pudo establecer el archivo definitivo.'
                );
            }

            @chmod(
                $targetPath,
                0640
            );

            $targetHash = hash_file(
                'sha256',
                $targetPath
            );

            if (
                $targetHash !== $sourceHash
            ) {
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

    private function legacyPath(
        string $filename
    ): string {
        return rtrim(
            (string) config(
                'paths.images_path'
            ),
            '/\\'
        )
            . DIRECTORY_SEPARATOR
            . '_docs'
            . DIRECTORY_SEPARATOR
            . $filename;
    }

    private function targetPath(
        int $portal,
        int $idRequisicionAspirante,
        string $filename
    ): string {
        if (
            $portal <= 0
            || $idRequisicionAspirante <= 0
        ) {
            throw new RuntimeException(
                'Portal o requisicion_aspirante inválidos.'
            );
        }

        return rtrim(
            (string) config(
                'paths.documents_path'
            ),
            '/\\'
        )
            . DIRECTORY_SEPARATOR
            . 'portales'
            . DIRECTORY_SEPARATOR
            . $portal
            . DIRECTORY_SEPARATOR
            . 'reclutamiento'
            . DIRECTORY_SEPARATOR
            . 'aspirantes'
            . DIRECTORY_SEPARATOR
            . $idRequisicionAspirante
            . DIRECTORY_SEPARATOR
            . 'documentos'
            . DIRECTORY_SEPARATOR
            . $filename;
    }

    private function safeFilename(
        string $storedValue
    ): ?string {
        $storedValue = trim(
            $storedValue
        );

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

    private function positiveOption(
        string $name
    ): ?int {
        $value = $this->option(
            $name
        );

        if (
            $value === null
            || $value === ''
        ) {
            return null;
        }

        if (
            filter_var(
                $value,
                FILTER_VALIDATE_INT,
                [
                    'options' => [
                        'min_range' => 1,
                    ],
                ]
            ) === false
        ) {
            return null;
        }

        return (int) $value;
    }
}