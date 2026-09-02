<?php
namespace App\Console\Commands;

use App\Models\CalendarioEvento;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Documents\EmployeeDocumentPathService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class MigrarDocumentosEmpleado extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-empleado
                        {employee? : ID interno del empleado}
                        {--portal= : Migrar todos los empleados de un portal}
                        {--client= : Migrar todos los empleados de un cliente}
                        {--recover-from-trash : Buscar orígenes faltantes en _borrados}
                        {--execute : Copiar archivos y actualizar la base de datos}';

    protected $description =
        'Migra documentos hacia storagetalentsafe por empleado, cliente o portal';

    public function handle(
        EmployeeDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): int {
        try {
            $employees = $this->resolveEmployeesForScope();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return Command::INVALID;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $scopeDescription = $this->scopeDescription($employees);

        if ($execute) {
            $this->warn(
                'EJECUCIÓN ACTIVADA: se copiarán archivos y actualizará la base de datos.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración de {$scopeDescription}?",
                false
            )) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        } else {
            $this->info(
                'SIMULACIÓN: no se modificarán archivos ni base de datos.'
            );
        }
        $this->newLine();

        $scopeRows = [];
        $failedEmployees = 0;

        foreach ($employees as $employee) {
            $result = $this->migrateEmployee(
                $employee,
                $execute,
                $documentPaths,
                $auditoria
            );

            $successful = $result === Command::SUCCESS;

            if (! $successful) {
                $failedEmployees++;
            }

            $scopeRows[] = [
                (int) $employee->id,
                (int) $employee->id_portal,
                (int) $employee->id_cliente,
                $successful ? 'CORRECTO' : 'CON_ERRORES',
            ];
        }

        if (count($employees) > 1) {
            $this->newLine();
            $this->table(
                ['Empleado', 'Portal', 'Cliente', 'Resultado'],
                $scopeRows
            );
            $this->info(sprintf(
                'Lote finalizado: %d empleados, %d correctos y %d con errores.',
                count($employees),
                count($employees) - $failedEmployees,
                $failedEmployees
            ));
        }

        return $failedEmployees > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function migrateEmployee(
        Empleado $employee,
        bool $execute,
        EmployeeDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): int {
        $employeeId = (int) $employee->id;

        $this->table(
            ['Dato', 'Valor'],
            [
                ['Empleado', $employee->id],
                ['Portal', $employee->id_portal],
                ['Cliente', $employee->id_cliente],
                ['Status', $employee->status],
                ['Eliminado', $employee->eliminado],
            ]
        );

        $sources = [
            '_documentEmpleado' => [
                'model'           => DocumentEmpleado::class,
                'employee_column' => 'employee_id',
                'path_column'     => 'name',
                'entity_type'     => 'documento',
                'deleted_column'  => 'status',
                'deleted_value'   => 999,
            ],
            '_cursos'           => [
                'model'           => CursoEmpleado::class,
                'employee_column' => 'employee_id',
                'path_column'     => 'name',
                'entity_type'     => 'curso',
                'deleted_column'  => 'status',
                'deleted_value'   => 999,
            ],
            '_examEmpleado'     => [
                'model'           => ExamEmpleado::class,
                'employee_column' => 'employee_id',
                'path_column'     => 'name',
                'entity_type'     => 'examen',
                'deleted_column'  => 'status',
                'deleted_value'   => 999,
            ],
            '_incidencias'      => [
                'model'           => CalendarioEvento::class,
                'employee_column' => 'id_empleado',
                'path_column'     => 'archivo',
                'entity_type'     => 'incidencia',
                'deleted_column'  => 'eliminado',
                'deleted_value'   => 1,
            ],
        ];

        $rows   = [];
        $totals = [
            'registros'   => 0,
            'migrables'   => 0,
            'omitidos'    => 0,
            'ejecutados'  => 0,
            'pendientes'  => 0,
            'migrados'    => 0,
            'errores'     => 0,
            'recuperados' => 0,
        ];

        foreach ($sources as $category => $sourceConfig) {
            $modelClass     = $sourceConfig['model'];
            $employeeColumn = $sourceConfig['employee_column'];
            $pathColumn     = $sourceConfig['path_column'];
            $entityType     = $sourceConfig['entity_type'];
            $deletedColumn  = $sourceConfig['deleted_column'];
            $deletedValue   = $sourceConfig['deleted_value'];

            $records = $modelClass::query()
                ->where($employeeColumn, $employeeId)
                ->orderBy('id')
                ->get();

            foreach ($records as $record) {
                $totals['registros']++;

                $storedValue = trim(
                    (string) $record->getAttribute($pathColumn)
                );
                $isDeleted =
                (string) $record->getAttribute($deletedColumn)
                === (string) $deletedValue;
                $sourcePath        = null;
                $sourceType        = null;
                $targetStoredValue = null;
                $status            = null;
                $hash              = null;

                /*
                * Los documentos normales toman el alcance del empleado.
                * Las incidencias también contienen portal y cliente,
                * por lo que se valida que coincidan.
                */

                if ($storedValue === '') {
                    $status = 'SIN_ARCHIVO_OMITIDO';
                    $totals['omitidos']++;
                } elseif (str_contains($storedValue, '_sin_')) {
                    $status = 'PENDIENTE_SIN_ARCHIVO';
                    $totals['pendientes']++;
                } elseif (
                    $category === '_examEmpleado'
                    && ! $this->looksLikeFileReference($storedValue)
                ) {
                    $status = 'EXAMEN_API_SIN_ARCHIVO';
                    $totals['omitidos']++;
                } elseif ($documentPaths->isExternalUrl($storedValue)) {
                    $status = 'URL_EXTERNA_OMITIDA';
                } elseif ($this->isDefinitivePath(
                    $storedValue,
                    $category,
                    (int) $employee->id_portal,
                    (int) $employee->id_cliente,
                    $employeeId,
                    $isDeleted
                )) {
                    $status = 'YA_MIGRADO';
                    $totals['migrados']++;
                } else {
                    try {
                        $sourcePath = $this->resolveSourcePath(
                            $category,
                            $storedValue,
                            $documentPaths
                        );

                        $sourceType = 'LEGACY';

                        if (! is_file($sourcePath)) {
                            $sourcePath = null;

                            if ($this->option('recover-from-trash')) {
                                $matches = $this->findTrashMatches(
                                    basename($storedValue),
                                    $category,
                                    $employee
                                );

                                if (count($matches) === 1) {
                                    $sourcePath = $matches[0];
                                    $sourceType = 'RESPALDO';
                                    $totals['recuperados']++;
                                } elseif (count($matches) > 1) {
                                    $status = 'ERROR_MULTIPLES_RESPALDOS';
                                }
                            }
                        }

                        if ($sourcePath && is_file($sourcePath)) {
                            $baseName =
                            $isDeleted
                            && $sourceType === 'RESPALDO'
                                ? basename($sourcePath)
                                : basename($storedValue);
                            $recordPrefix = sprintf(
                                'registro_%d_',
                                (int) $record->id
                            );

                            $targetFileName = str_starts_with(
                                $baseName,
                                $recordPrefix
                            )
                                ? $baseName
                                : $recordPrefix . $baseName;

                            $targetStoredValue = $this->targetStoredPath(
                                $category,
                                $employee,
                                $targetFileName,
                                $isDeleted,
                                $documentPaths
                            );

                            if (strlen($targetStoredValue) > 255) {
                                $status = 'ERROR_RUTA_MAYOR_255';
                                $totals['errores']++;
                            } else {
                                $targetPath = $this->targetAbsolutePath(
                                    $targetStoredValue
                                );

                                if ($execute) {
                                    $executionResult = $this->executeRecord(
                                        $modelClass,
                                        $record,
                                        $employee,
                                        $category,
                                        $pathColumn,
                                        $entityType,
                                        $isDeleted,
                                        $storedValue,
                                        $sourcePath,
                                        $targetStoredValue,
                                        $sourceType,
                                        $documentPaths,
                                        $auditoria
                                    );

                                    $status = $executionResult['status'];
                                    $hash   = $executionResult['hash'];
                                    $totals['ejecutados']++;
                                } else {
                                    if (is_file($targetPath)) {
                                        $targetHash = hash_file(
                                            'sha256',
                                            $targetPath
                                        );

                                        $sourceHash = hash_file(
                                            'sha256',
                                            $sourcePath
                                        );

                                        $status = $targetHash === $sourceHash
                                            ? 'DESTINO_EXISTE_MISMO_HASH'
                                            : 'ERROR_DESTINO_DIFERENTE';

                                        if ($targetHash !== $sourceHash) {
                                            $totals['errores']++;
                                        }
                                    } else {
                                        $status = $sourceType === 'RESPALDO'
                                            ? 'MIGRABLE_DESDE_RESPALDO'
                                            : 'MIGRABLE';
                                    }

                                    $hash = hash_file('sha256', $sourcePath);
                                }

                                $totals['migrables']++;
                            }
                        } elseif ($status === null) {
                            $status = 'ERROR_ORIGEN_NO_EXISTE';
                            $totals['errores']++;
                        }
                    } catch (Throwable $exception) {
                        $status = 'ERROR: ' . $exception->getMessage();
                        $totals['errores']++;
                    }
                }

                $rows[] = [
                    $category,
                    (int) $record->id,
                    $storedValue,
                    $sourceType ?: '-',
                    $targetStoredValue ?: '-',
                    $hash ? substr($hash, 0, 16) . '…' : '-',
                    $status,
                ];
            }
        }

        $this->newLine();

        $this->table(
            [
                'Módulo',
                'Registro',
                'Valor actual',
                'Origen',
                'Ruta propuesta',
                'SHA-256',
                'Resultado',
            ],
            $rows
        );

        $this->newLine();

        $this->table(
            ['Resultado', 'Cantidad'],
            [
                ['Registros revisados', $totals['registros']],
                ['Migrables', $totals['migrables']],
                ['Ejecutados', $totals['ejecutados']],
                ['Recuperables desde respaldo', $totals['recuperados']],
                ['Omitidos sin archivo', $totals['omitidos']],
                ['Pendientes sin archivo', $totals['pendientes']],
                ['Ya migrados', $totals['migrados']],
                ['Errores', $totals['errores']],
            ]
        );

        if ($execute && $totals['errores'] > 0) {
            $this->error(
                'Ejecución finalizada con errores. Revisa cada registro.'
            );
        } elseif ($execute) {
            $this->info(
                'Ejecución finalizada correctamente. Los archivos legacy se conservaron.'
            );
        } else {
            $this->warn(
                'Simulación finalizada. No se modificó ningún archivo ni registro.'
            );
        }

        return $totals['errores'] > 0
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * @return array<int, Empleado>
     */
    private function resolveEmployeesForScope(): array
    {
        $employeeArgument = trim(
            (string) ($this->argument('employee') ?? '')
        );

        $portalOption = trim(
            (string) ($this->option('portal') ?? '')
        );

        $clientOption = trim(
            (string) ($this->option('client') ?? '')
        );

        if (
            $employeeArgument !== ''
            && ($portalOption !== '' || $clientOption !== '')
        ) {
            throw new InvalidArgumentException(
                'Usa un empleado o un alcance por portal/cliente, no ambos.'
            );
        }

        if (
            $employeeArgument === ''
            && $portalOption === ''
            && $clientOption === ''
        ) {
            throw new InvalidArgumentException(
                'Indica un empleado, --portal o --client.'
            );
        }

        if ($employeeArgument !== '') {
            $employeeId = $this->positiveInteger(
                $employeeArgument,
                'empleado'
            );

            /** @var Empleado|null $employee */
            $employee = Empleado::query()->find($employeeId);

            if (! $employee) {
                throw new RuntimeException(
                    "No existe el empleado {$employeeId}."
                );
            }

            return [$employee];
        }

        $query = Empleado::query();

        if ($portalOption !== '') {
            $portalId = $this->positiveInteger(
                $portalOption,
                'portal'
            );

            $query->where('id_portal', $portalId);
        }

        if ($clientOption !== '') {
            $clientId = $this->positiveInteger(
                $clientOption,
                'cliente'
            );

            $query->where('id_cliente', $clientId);
        }

        $employees = $query
            ->orderBy('id')
            ->get();

        if ($employees->isEmpty()) {
            throw new RuntimeException(
                'No se encontraron empleados para el alcance solicitado.'
            );
        }

        return $employees->all();
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

    /**
     * @param array<int, Empleado> $employees
     */
    private function scopeDescription(array $employees): string
    {
        if (count($employees) === 1) {
            return 'el empleado ' . (int) $employees[0]->id;
        }

        $portalOption = trim(
            (string) ($this->option('portal') ?? '')
        );

        $clientOption = trim(
            (string) ($this->option('client') ?? '')
        );

        if ($portalOption !== '' && $clientOption !== '') {
            return sprintf(
                '%d empleados del portal %s y cliente %s',
                count($employees),
                $portalOption,
                $clientOption
            );
        }

        if ($clientOption !== '') {
            return sprintf(
                '%d empleados del cliente %s',
                count($employees),
                $clientOption
            );
        }

        return sprintf(
            '%d empleados del portal %s',
            count($employees),
            $portalOption
        );
    }

    private function looksLikeFileReference(
        string $storedValue
    ): bool {
        $storedValue = trim(
            str_replace('\\', '/', $storedValue)
        );

        if ($storedValue === '') {
            return false;
        }

        if (str_contains($storedValue, '/')) {
            return true;
        }

        return pathinfo(
            basename($storedValue),
            PATHINFO_EXTENSION
        ) !== '';
    }
    private function resolveSourcePath(
        string $category,
        string $storedValue,
        EmployeeDocumentPathService $documentPaths
    ): string {
        /*
     * Primero se utiliza el resolvedor oficial:
     * - archivos simples en _archivo_calendario;
     * - rutas estructuradas en _archivo_calendario/portals;
     * - archivos de las demás categorías.
     */
        $primaryPath = $documentPaths->absolutePath(
            $category,
            $storedValue
        );

        if (
            $category !== '_incidencias'
            || is_file($primaryPath)
        ) {
            return $primaryPath;
        }

        /*
     * Compatibilidad con la implementación antigua:
     * _calendario_evidencia/Portals/...
     */
        $imagesPath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        $normalizedStoredValue = str_replace(
            '\\',
            '/',
            ltrim($storedValue, '/\\')
        );

        $calendarEvidenceValue = preg_replace(
            '#^portals/#',
            'Portals/',
            $normalizedStoredValue
        );

        $candidates = [
            $imagesPath
            . DIRECTORY_SEPARATOR
            . '_calendario_evidencia'
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $normalizedStoredValue
            ),

            $imagesPath
            . DIRECTORY_SEPARATOR
            . '_calendario_evidencia'
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                (string) $calendarEvidenceValue
            ),

            $imagesPath
            . DIRECTORY_SEPARATOR
            . '_calendario_evidencia'
            . DIRECTORY_SEPARATOR
            . basename($normalizedStoredValue),
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $primaryPath;
    }
    private function executeRecord(
        string $modelClass,
        Model $record,
        Empleado $employee,
        string $category,
        string $pathColumn,
        string $entityType,
        bool $isDeleted,
        string $previousStoredValue,
        string $sourcePath,
        string $targetStoredValue,
        string $sourceType,
        EmployeeDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): array {
        $targetPath = $this->targetAbsolutePath(
            $targetStoredValue
        );

        $sourceHash = hash_file('sha256', $sourcePath);

        if ($sourceHash === false) {
            throw new RuntimeException(
                'No se pudo calcular el hash del origen.'
            );
        }

        $targetDirectory = dirname($targetPath);

        if (
            ! is_dir($targetDirectory)
            && ! mkdir($targetDirectory, 0750, true)
            && ! is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de destino.'
            );
        }

        if (is_file($targetPath)) {
            $targetHash = hash_file('sha256', $targetPath);

            if ($targetHash !== $sourceHash) {
                throw new RuntimeException(
                    'El destino existe con contenido diferente.'
                );
            }
        } else {
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

            $temporaryHash = hash_file(
                'sha256',
                $temporaryPath
            );

            if ($temporaryHash !== $sourceHash) {
                @unlink($temporaryPath);

                throw new RuntimeException(
                    'El hash de la copia no coincide con el origen.'
                );
            }

            if (! rename($temporaryPath, $targetPath)) {
                @unlink($temporaryPath);

                throw new RuntimeException(
                    'No se pudo confirmar el archivo de destino.'
                );
            }

            @chmod($targetPath, 0640);
        }

        $targetHash = hash_file('sha256', $targetPath);

        if ($targetHash !== $sourceHash) {
            throw new RuntimeException(
                'Falló la verificación final del archivo.'
            );
        }

        $connectionName = $record->getConnectionName();

        \Illuminate\Support\Facades\DB::connection(
            $connectionName
        )->transaction(
            function () use (
                $modelClass,
                $record,
                $pathColumn,
                $previousStoredValue,
                $targetStoredValue
            ): void {
                /** @var Model|null $current */
                $current = $modelClass::query()
                    ->lockForUpdate()
                    ->find($record->getKey());

                if (! $current) {
                    throw new RuntimeException(
                        'El registro dejó de existir.'
                    );
                }

                if (
                    (string) $current->getAttribute($pathColumn)
                    !== $previousStoredValue
                ) {
                    throw new RuntimeException(
                        'La ruta cambió después de la simulación.'
                    );
                }

                $current->setAttribute(
                    $pathColumn,
                    $targetStoredValue
                );
                $current->save();
            }
        );

        $auditResult = $auditoria->registrar([
            'id_portal'        => (int) $employee->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'sistema',
            'actor_nombre'     => 'Comando Artisan',

            'modulo'           => 'empleados',
            'entidad_tipo'     => $entityType,
            'entidad_id'       => (int) $record->getKey(),

            'accion'           => 'migrar_archivo_legacy',
            'resultado'        => 'exitoso',

            'descripcion'      => $isDeleted
                ? 'Se migró un archivo legacy eliminado a borrados.'
                : 'Se migró un archivo legacy activo a storagetalentsafe.',

            'datos_anteriores' => [
                $pathColumn => $previousStoredValue,
            ],

            'datos_nuevos'     => [
                $pathColumn => $targetStoredValue,
            ],

            'metadatos'        => [
                'employee_id'               => (int) $employee->id,
                'categoria_almacenamiento'  => $category,
                'origen_fisico'             => $sourcePath,
                'destino_fisico'            => $targetPath,
                'origen_tipo'               => $sourceType,
                'registro_eliminado'        => $isDeleted,
                'tamano_bytes'              => filesize($targetPath),
                'sha256'                    => $targetHash,
                'archivo_legacy_conservado' => true,
            ],
        ]);

        $manifest = [
            'fecha'                => now()->toIso8601String(),
            'tabla'                => $record->getTable(),
            'registro_id'          => (int) $record->getKey(),
            'employee_id'          => (int) $employee->id,
            'id_portal'            => (int) $employee->id_portal,
            'id_cliente'           => (int) $employee->id_cliente,
            'categoria'            => $category,
            'columna_ruta'         => $pathColumn,
            'ruta_anterior'        => $previousStoredValue,
            'ruta_nueva'           => $targetStoredValue,
            'origen_fisico'        => $sourcePath,
            'destino_fisico'       => $targetPath,
            'origen_tipo'          => $sourceType,
            'tamano_bytes'         => filesize($targetPath),
            'sha256_origen'        => $sourceHash,
            'sha256_destino'       => $targetHash,
            'registro_eliminado'   => $isDeleted,
            'auditoria_registrada' => $auditResult !== null,
            'resultado'            => 'MIGRADO',
        ];

        $manifestDirectory = storage_path(
            'app/migration-manifests'
        );

        if (
            ! is_dir($manifestDirectory)
            && ! mkdir($manifestDirectory, 0750, true)
            && ! is_dir($manifestDirectory)
        ) {
            throw new RuntimeException(
                'Migración realizada, pero no se pudo crear el manifiesto.'
            );
        }

        $manifestPath = $manifestDirectory
        . DIRECTORY_SEPARATOR
        . 'documentos_empleado_'
        . (int) $employee->id
            . '.jsonl';

        $written = file_put_contents(
            $manifestPath,
            json_encode(
                $manifest,
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

        return [
            'hash'   => $targetHash,
            'status' => $isDeleted
                ? 'MIGRADO_A_BORRADOS'
                : (
                $sourceType === 'RESPALDO'
                    ? 'MIGRADO_DESDE_RESPALDO'
                    : 'MIGRADO'
            ),
        ];
    }

    private function targetStoredPath(
        string $category,
        Empleado $employee,
        string $fileName,
        bool $isDeleted,
        EmployeeDocumentPathService $documentPaths
    ): string {
        if (! $isDeleted) {
            return $documentPaths->storedPath(
                $category,
                $employee,
                $fileName
            );
        }

        return implode('/', [
            'portales',
            (int) $employee->id_portal,
            '_borrados',
            $category,
            'clientes',
            (int) $employee->id_cliente,
            'empleados',
            (int) $employee->id,
            basename($fileName),
        ]);
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

    private function isDefinitivePath(
        string $storedValue,
        string $category,
        int $portalId,
        int $clientId,
        int $employeeId,
        bool $isDeleted
    ): bool {
        $parts = [
            'portales',
            $portalId,
        ];

        if ($isDeleted) {
            $parts[] = '_borrados';
        }

        $parts[] = $category;
        $parts[] = 'clientes';
        $parts[] = $clientId;
        $parts[] = 'empleados';
        $parts[] = $employeeId;

        $expectedPrefix = implode('/', $parts) . '/';

        return str_starts_with(
            $storedValue,
            $expectedPrefix
        );
    }

    /**
     * @return array<int, string>
     */
    private function findTrashMatches(
        string $originalName,
        string $category,
        Empleado $employee
    ): array {
        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        $roots = [
            /*
         * Estructura definitiva de eliminados.
         */
            implode(DIRECTORY_SEPARATOR, [
                $documentsPath,
                'portales',
                (int) $employee->id_portal,
                '_borrados',
                $category,
                'clientes',
                (int) $employee->id_cliente,
                'empleados',
                (int) $employee->id,
            ]),

            /*
         * Estructura histórica global de reemplazados.
         */
            $documentsPath
            . DIRECTORY_SEPARATOR
            . '_borrados',
        ];

        $matches = [];

        foreach (array_unique($roots) as $trashRoot) {
            if (! is_dir($trashRoot)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $trashRoot,
                    RecursiveDirectoryIterator::SKIP_DOTS
                )
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $candidateName = $file->getFilename();

                if (
                    $candidateName === $originalName
                    || str_ends_with(
                        $candidateName,
                        '_' . $originalName
                    )
                ) {
                    $matches[] = $file->getPathname();
                }
            }
        }

        $matches = array_values(array_unique($matches));
        sort($matches);

        return $matches;
    }
}