<?php

namespace App\Console\Commands;

use App\Models\Empleado;
use App\Services\Documents\EmployeePhotoPathService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MigrarFotosPerfil extends Command
{
    protected $signature = 'talentsafe:fotos-perfil:migrar
                            {employee? : ID interno del empleado}
                            {--portal= : Migrar empleados de un portal}
                            {--client= : Migrar empleados de un cliente}
                            {--execute : Copiar físicamente las fotos legacy}';

    protected $description =
        'Migra fotos activas de _perfilEmpleado hacia storagetalentsafe';

    public function handle(
        EmployeePhotoPathService $photoPaths
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

        if ($execute) {
            $this->warn(
                'EJECUCIÓN ACTIVADA: se copiarán fotos hacia storagetalentsafe.'
            );

            if (! $this->confirm(
                '¿Confirmas la migración ' .
                $this->scopeDescription($employees) .
                '?',
                false
            )) {
                $this->info('Operación cancelada.');

                return Command::SUCCESS;
            }
        } else {
            $this->info(
                'SIMULACIÓN: no se modificará ningún archivo.'
            );
        }

        $this->newLine();

        $rows = [];

        $totals = [
            'migrables'        => 0,
            'copiados'         => 0,
            'ya_migrados'      => 0,
            'solo_nueva'       => 0,
            'sin_foto'         => 0,
            'default'          => 0,
            'sin_cliente'      => 0,
            'archivo_no_existe'=> 0,
            'origen_faltante'  => 0,
            'conflictos'       => 0,
            'errores'          => 0,
        ];

        foreach ($employees as $employee) {
            $result = $this->processEmployee(
                $employee,
                $execute,
                $photoPaths
            );

            if (isset($totals[$result['counter']])) {
                $totals[$result['counter']]++;
            }

            $rows[] = [
                (int) $employee->id,
                (int) $employee->id_portal,
                (int) $employee->id_cliente,
                $employee->foto ?: '-',
                $result['status'],
            ];
        }

        $this->table(
            [
                'Empleado',
                'Portal',
                'Cliente',
                'Foto',
                'Resultado',
            ],
            $rows
        );

        $this->newLine();

        $this->table(
            ['Concepto', 'Total'],
            [
                ['Migrables', $totals['migrables']],
                ['Copiados', $totals['copiados']],
                ['Ya migrados', $totals['ya_migrados']],
                ['Sólo almacenamiento nuevo', $totals['solo_nueva']],
                ['Sin foto', $totals['sin_foto']],
                ['Default omitido', $totals['default']],
                ['Sin cliente omitido', $totals['sin_cliente']],
                ['Archivo no existe', $totals['archivo_no_existe']],
                ['Origen faltante activo', $totals['origen_faltante']],
                ['Conflictos', $totals['conflictos']],
                ['Errores', $totals['errores']],
            ]
        );

        $hasErrors =
            $totals['origen_faltante'] > 0
            || $totals['conflictos'] > 0
            || $totals['errores'] > 0;

        return $hasErrors
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    private function processEmployee(
        Empleado $employee,
        bool $execute,
        EmployeePhotoPathService $photoPaths
    ): array {
        $filename = trim((string) ($employee->foto ?? ''));

        if (
            $filename === ''
            || strtolower($filename) === 'null'
        ) {
            return [
                'status'  => 'SIN_FOTO',
                'counter' => 'sin_foto',
            ];
        }

        $filename = basename($filename);

        if (strtolower($filename) === 'perfil.png') {
            return [
                'status'  => 'DEFAULT_OMITIDO',
                'counter' => 'default',
            ];
        }

        if ((int) $employee->id_cliente <= 0) {
            return [
                'status'  => 'SIN_CLIENTE_OMITIDO',
                'counter' => 'sin_cliente',
            ];
        }

        try {
            $sourcePath = $photoPaths->legacyPath(
                $filename
            );

            $targetPath = $photoPaths->activePath(
                $employee,
                $filename
            );

            $sourceExists = is_file($sourcePath);
            $targetExists = is_file($targetPath);

            /*
             * Foto creada directamente en la nueva estructura.
             * Es un estado válido y esperado durante la transición.
             */
            if (! $sourceExists && $targetExists) {
                if (! is_readable($targetPath)) {
                    return [
                        'status'  => 'DESTINO_NO_LEIBLE',
                        'counter' => 'errores',
                    ];
                }

                return [
                    'status'  => 'SOLO_NUEVA',
                    'counter' => 'solo_nueva',
                ];
            }

            if (! $sourceExists && ! $targetExists) {
                if (
                    (int) $employee->status !== 1
                    || (int) $employee->eliminado === 1
                ) {
                    return [
                        'status'  => 'ARCHIVO_NO_EXISTE',
                        'counter' => 'archivo_no_existe',
                    ];
                }

                return [
                    'status'  => 'ORIGEN_NO_EXISTE',
                    'counter' => 'origen_faltante',
                ];
            }

            if (! is_readable($sourcePath)) {
                return [
                    'status'  => 'ORIGEN_NO_LEIBLE',
                    'counter' => 'errores',
                ];
            }

            $sourceHash = hash_file(
                'sha256',
                $sourcePath
            );

            if ($sourceHash === false) {
                return [
                    'status'  => 'HASH_ORIGEN_ERROR',
                    'counter' => 'errores',
                ];
            }

            if ($targetExists) {
                if (! is_readable($targetPath)) {
                    return [
                        'status'  => 'DESTINO_NO_LEIBLE',
                        'counter' => 'errores',
                    ];
                }

                $targetHash = hash_file(
                    'sha256',
                    $targetPath
                );

                if ($targetHash === false) {
                    return [
                        'status'  => 'HASH_DESTINO_ERROR',
                        'counter' => 'errores',
                    ];
                }

                if ($targetHash !== $sourceHash) {
                    return [
                        'status'  => 'CONFLICTO_HASH',
                        'counter' => 'conflictos',
                    ];
                }

                return [
                    'status'  => 'YA_MIGRADO',
                    'counter' => 'ya_migrados',
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
                'status'  => 'COPIADO_OK',
                'counter' => 'copiados',
            ];
        } catch (Throwable $exception) {
            $this->error(sprintf(
                'Empleado %d: %s',
                (int) $employee->id,
                $exception->getMessage()
            ));

            return [
                'status'  => 'ERROR',
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
            && ! mkdir($targetDirectory, 0770, true)
            && ! is_dir($targetDirectory)
        ) {
            throw new RuntimeException(
                'No fue posible crear el directorio de destino.'
            );
        }

        $temporaryPath = $targetPath
            . '.tmp.'
            . getmypid()
            . '.'
            . bin2hex(random_bytes(4));

        try {
            if (! copy($sourcePath, $temporaryPath)) {
                throw new RuntimeException(
                    'No fue posible copiar el archivo temporal.'
                );
            }

            $temporaryHash = hash_file(
                'sha256',
                $temporaryPath
            );

            if ($temporaryHash !== $sourceHash) {
                throw new RuntimeException(
                    'El hash temporal no coincide con el origen.'
                );
            }

            if (! rename($temporaryPath, $targetPath)) {
                throw new RuntimeException(
                    'No fue posible confirmar el archivo de destino.'
                );
            }

            @chmod($targetPath, 0660);

            $targetHash = hash_file(
                'sha256',
                $targetPath
            );

            if ($targetHash !== $sourceHash) {
                throw new RuntimeException(
                    'Falló la verificación SHA-256 final.'
                );
            }

            $sourceSize = filesize($sourcePath);
            $targetSize = filesize($targetPath);

            if (
                $sourceSize === false
                || $targetSize === false
                || $sourceSize !== $targetSize
            ) {
                throw new RuntimeException(
                    'El tamaño del destino no coincide con el origen.'
                );
            }
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }

            /*
             * Si el rename ya ocurrió pero la verificación final falló,
             * retiramos esa copia defectuosa. El legacy nunca se toca.
             */
            if (
                is_file($targetPath)
                && hash_file('sha256', $targetPath) !== $sourceHash
            ) {
                @unlink($targetPath);
            }

            throw $exception;
        }
    }

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

            $employee = Empleado::query()->find(
                $employeeId
            );

            if (! $employee) {
                throw new RuntimeException(
                    "No existe el empleado {$employeeId}."
                );
            }

            return [$employee];
        }

        $query = Empleado::query()
            ->whereNotNull('foto')
            ->where('foto', '<>', '');

        if ($portalOption !== '') {
            $portalId = $this->positiveInteger(
                $portalOption,
                'portal'
            );

            $query->where(
                'id_portal',
                $portalId
            );
        }

        if ($clientOption !== '') {
            $clientId = $this->positiveInteger(
                $clientOption,
                'cliente'
            );

            $query->where(
                'id_cliente',
                $clientId
            );
        }

        $employees = $query
            ->orderBy('id')
            ->get();

        if ($employees->isEmpty()) {
            throw new RuntimeException(
                'No se encontraron empleados con foto para el alcance solicitado.'
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

    private function scopeDescription(
        array $employees
    ): string {
        if (count($employees) === 1) {
            return 'del empleado '
                . (int) $employees[0]->id;
        }

        $portalOption = trim(
            (string) ($this->option('portal') ?? '')
        );

        $clientOption = trim(
            (string) ($this->option('client') ?? '')
        );

        if (
            $portalOption !== ''
            && $clientOption !== ''
        ) {
            return sprintf(
                'de %d empleados del portal %s y cliente %s',
                count($employees),
                $portalOption,
                $clientOption
            );
        }

        if ($clientOption !== '') {
            return sprintf(
                'de %d empleados del cliente %s',
                count($employees),
                $clientOption
            );
        }

        return sprintf(
            'de %d empleados del portal %s',
            count($employees),
            $portalOption
        );
    }
}