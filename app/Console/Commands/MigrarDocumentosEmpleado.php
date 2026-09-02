<?php
namespace App\Console\Commands;

use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Documents\EmployeeDocumentPathService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MigrarDocumentosEmpleado extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-empleado
                        {employee : ID interno del empleado}
                        {--recover-from-trash : Buscar orígenes faltantes en _borrados}
                        {--execute : Copiar archivos y actualizar la base de datos}';

    protected $description =
        'Simula la migración documental de un empleado hacia storagetalentsafe';

    public function handle(
        EmployeeDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): int {
        $employeeId = (int) $this->argument('employee');
        $execute    = (bool) $this->option('execute');

        if ($employeeId <= 0) {
            $this->error('El ID del empleado debe ser mayor que cero.');

            return Command::INVALID;
        }

        /** @var Empleado|null $employee */
        $employee = Empleado::query()->find($employeeId);

        if (! $employee) {
            $this->error("No existe el empleado {$employeeId}.");

            return Command::FAILURE;
        }

        if ($execute) {
            $this->warn(
                'EJECUCIÓN ACTIVADA: se copiarán archivos y actualizará la base de datos.'
            );

            if (! $this->confirm(
                "¿Confirmas la migración del empleado {$employeeId}?",
                false
            )) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        } else {
            $this->info(
                'SIMULACIÓN: no se modificarán archivos ni base de datos.'
            );
        }$this->newLine();

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
            '_documentEmpleado' => DocumentEmpleado::class,
            '_cursos'           => CursoEmpleado::class,
            '_examEmpleado'     => ExamEmpleado::class,
        ];

        $rows   = [];
        $totals = [
            'registros'   => 0,
            'migrables'   => 0,
            'ejecutados'  => 0,
            'pendientes'  => 0,
            'migrados'    => 0,
            'errores'     => 0,
            'recuperados' => 0,
        ];

        foreach ($sources as $category => $modelClass) {
            $records = $modelClass::query()
                ->where('employee_id', $employeeId)
                ->orderBy('id')
                ->get();

            foreach ($records as $record) {
                $totals['registros']++;

                $storedValue       = trim((string) $record->name);
                $sourcePath        = null;
                $sourceType        = null;
                $targetStoredValue = null;
                $status            = null;
                $hash              = null;

                if ($storedValue === '') {
                    $status = 'ERROR_NAME_VACIO';
                    $totals['errores']++;
                } elseif (str_contains($storedValue, '_sin_')) {
                    $status = 'PENDIENTE_SIN_ARCHIVO';
                    $totals['pendientes']++;
                } elseif ($documentPaths->isExternalUrl($storedValue)) {
                    $status = 'URL_EXTERNA_OMITIDA';
                } elseif ($this->isDefinitivePath(
                    $storedValue,
                    $category,
                    (int) $employee->id_portal,
                    (int) $employee->id_cliente,
                    $employeeId
                )) {
                    $status = 'YA_MIGRADO';
                    $totals['migrados']++;
                } else {
                    try {
                        $sourcePath = $documentPaths->absolutePath(
                            $category,
                            $storedValue
                        );

                        $sourceType = 'LEGACY';

                        if (! is_file($sourcePath)) {
                            $sourcePath = null;

                            if ($this->option('recover-from-trash')) {
                                $matches = $this->findTrashMatches(
                                    basename($storedValue)
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
                            $targetFileName = sprintf(
                                'registro_%d_%s',
                                (int) $record->id,
                                basename($storedValue)
                            );

                            $targetStoredValue = $documentPaths->storedPath(
                                $category,
                                $employee,
                                $targetFileName
                            );

                            if (strlen($targetStoredValue) > 255) {
                                $status = 'ERROR_RUTA_MAYOR_255';
                                $totals['errores']++;
                            } else {
                                $targetPath = $documentPaths->absolutePath(
                                    $category,
                                    $targetStoredValue
                                );

                                if ($execute) {
                                    $executionResult = $this->executeRecord(
                                        $modelClass,
                                        $record,
                                        $employee,
                                        $category,
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
    private function executeRecord(
        string $modelClass,
        Model $record,
        Empleado $employee,
        string $category,
        string $previousStoredValue,
        string $sourcePath,
        string $targetStoredValue,
        string $sourceType,
        EmployeeDocumentPathService $documentPaths,
        AuditoriaService $auditoria
    ): array {
        $targetPath = $documentPaths->absolutePath(
            $category,
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
                    (string) $current->name
                    !== $previousStoredValue
                ) {
                    throw new RuntimeException(
                        'La ruta cambió después de la simulación.'
                    );
                }

                $current->name = $targetStoredValue;
                $current->save();
            }
        );

        $entityTypes = [
            '_documentEmpleado' => 'documento',
            '_cursos'           => 'curso',
            '_examEmpleado'     => 'examen',
        ];

        $auditResult = $auditoria->registrar([
            'id_portal'        => (int) $employee->id_portal,
            'id_cliente'       => (int) $employee->id_cliente,
            'actor_tipo'       => 'sistema',
            'actor_nombre'     => 'Comando Artisan',

            'modulo'           => 'empleados',
            'entidad_tipo'     => $entityTypes[$category],
            'entidad_id'       => (int) $record->getKey(),

            'accion'           => 'migrar_archivo_legacy',
            'resultado'        => 'exitoso',

            'descripcion'      =>
            'Se migró un archivo legacy a storagetalentsafe.',

            'datos_anteriores' => [
                'name' => $previousStoredValue,
            ],

            'datos_nuevos'     => [
                'name' => $targetStoredValue,
            ],

            'metadatos'        => [
                'employee_id'               => (int) $employee->id,
                'categoria_almacenamiento'  => $category,
                'origen_fisico'             => $sourcePath,
                'destino_fisico'            => $targetPath,
                'origen_tipo'               => $sourceType,
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
            'ruta_anterior'        => $previousStoredValue,
            'ruta_nueva'           => $targetStoredValue,
            'origen_fisico'        => $sourcePath,
            'destino_fisico'       => $targetPath,
            'origen_tipo'          => $sourceType,
            'tamano_bytes'         => filesize($targetPath),
            'sha256_origen'        => $sourceHash,
            'sha256_destino'       => $targetHash,
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
            'status' => $sourceType === 'RESPALDO'
                ? 'MIGRADO_DESDE_RESPALDO'
                : 'MIGRADO',
        ];
    }

    private function isDefinitivePath(
        string $storedValue,
        string $category,
        int $portalId,
        int $clientId,
        int $employeeId
    ): bool {
        $expectedPrefix = implode('/', [
            'portales',
            $portalId,
            $category,
            'clientes',
            $clientId,
            'empleados',
            $employeeId,
        ]) . '/';

        return str_starts_with($storedValue, $expectedPrefix);
    }

    /**
     * @return array<int, string>
     */
    private function findTrashMatches(string $originalName): array
    {
        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        $trashRoot = $documentsPath . DIRECTORY_SEPARATOR . '_borrados';

        if (! is_dir($trashRoot)) {
            return [];
        }

        $matches  = [];
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

        sort($matches);

        return $matches;
    }
}
