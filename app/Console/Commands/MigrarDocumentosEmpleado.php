<?php

namespace App\Console\Commands;

use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Services\Documents\EmployeeDocumentPathService;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class MigrarDocumentosEmpleado extends Command
{
    protected $signature = 'talentsafe:documentos:migrar-empleado
                            {employee : ID interno del empleado}
                            {--recover-from-trash : Buscar orígenes faltantes en _borrados}';

    protected $description =
        'Simula la migración documental de un empleado hacia storagetalentsafe';

    public function handle(
        EmployeeDocumentPathService $documentPaths
    ): int {
        $employeeId = (int) $this->argument('employee');

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

        $this->info('SIMULACIÓN: no se modificarán archivos ni base de datos.');
        $this->newLine();

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

        $rows = [];
        $totals = [
            'registros'   => 0,
            'migrables'   => 0,
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

                $storedValue = trim((string) $record->name);
                $sourcePath = null;
                $sourceType = null;
                $targetStoredValue = null;
                $status = null;
                $hash = null;

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

                                if (is_file($targetPath)) {
                                    $status = 'DESTINO_YA_EXISTE';
                                } else {
                                    $status = $sourceType === 'RESPALDO'
                                        ? 'MIGRABLE_DESDE_RESPALDO'
                                        : 'MIGRABLE';
                                }

                                $hash = hash_file('sha256', $sourcePath);
                                $totals['migrables']++;
                            }
                        } elseif ($status === null) {
                            $status = 'ERROR_ORIGEN_NO_EXISTE';
                            $totals['errores']++;
                        }
                    } catch (Throwable $exception) {
                        $status = 'ERROR: '.$exception->getMessage();
                        $totals['errores']++;
                    }
                }

                $rows[] = [
                    $category,
                    (int) $record->id,
                    $storedValue,
                    $sourceType ?: '-',
                    $targetStoredValue ?: '-',
                    $hash ? substr($hash, 0, 16).'…' : '-',
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
                ['Recuperables desde respaldo', $totals['recuperados']],
                ['Pendientes sin archivo', $totals['pendientes']],
                ['Ya migrados', $totals['migrados']],
                ['Errores', $totals['errores']],
            ]
        );

        $this->warn(
            'Simulación finalizada. No se modificó ningún archivo ni registro.'
        );

        return $totals['errores'] > 0
            ? Command::FAILURE
            : Command::SUCCESS;
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
        ]).'/';

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

        $trashRoot = $documentsPath.DIRECTORY_SEPARATOR.'_borrados';

        if (! is_dir($trashRoot)) {
            return [];
        }

        $matches = [];
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
                    '_'.$originalName
                )
            ) {
                $matches[] = $file->getPathname();
            }
        }

        sort($matches);

        return $matches;
    }
}