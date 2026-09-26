<?php

namespace App\Console\Commands;

use App\Services\Checador\ChecadaEvidencePathService;
use App\Services\Checador\TaskEvidencePathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class MigrarEvidenciasOperacion extends Command
{
    protected $signature = 'talentsafe:evidencias:migrar
                            {--all : Revisar checadas y tareas}
                            {--checadas : Revisar sólo checadas}
                            {--tareas : Revisar sólo tareas}
                            {--verify-files : Verificar archivos ya migrados}
                            {--summary : Mostrar sólo resúmenes}
                            {--execute : Copiar archivos y actualizar BD}';

    protected $description =
        'Migra evidencias legacy de checadas y tareas hacia storagetalentsafe';

    public function handle(
        ChecadaEvidencePathService $checadaPaths,
        TaskEvidencePathService $taskPaths
    ): int {
        try {
            $scope = $this->scope();
            $this->validateConfig();
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());
            return Command::INVALID;
        }

        $execute = (bool) $this->option('execute');
        $verify  = (bool) $this->option('verify-files');
        $summary = (bool) $this->option('summary');

        $this->info(
            $execute
                ? 'EJECUCIÓN: se copiarán archivos y se actualizarán rutas en BD.'
                : 'SIMULACIÓN: no se modificarán archivos ni base de datos.'
        );
        $this->line('Alcance: ' . $scope['description']);
        $this->line(
            'Destino: ' . rtrim((string) config('paths.documents_path'), '/\\')
        );

        if ($execute) {
            $this->warn('Los archivos legacy se conservarán.');
            if (! $this->confirm('¿Confirmas la migración?', false)) {
                $this->info('Operación cancelada.');
                return Command::SUCCESS;
            }
        }

        $manifest = $execute ? $this->manifestPath($scope['name']) : null;
        $failures = 0;

        if ($scope['checadas']) {
            $totals = $this->processChecadas(
                $checadaPaths,
                $execute,
                $verify,
                $summary,
                $manifest
            );
            $failures += $totals['errores'] + $totals['destino_diferente'];
        }

        if ($scope['tareas']) {
            $totals = $this->processTareas(
                $taskPaths,
                $execute,
                $verify,
                $summary,
                $manifest
            );
            $failures += $totals['errores'] + $totals['destino_diferente'];
        }

        if ($manifest !== null) {
            $this->newLine();
            $this->line('Manifiesto: ' . $manifest);
        }

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processChecadas(
        ChecadaEvidencePathService $paths,
        bool $execute,
        bool $verify,
        bool $summary,
        ?string $manifest
    ): array {
        $this->newLine();
        $this->info('===== CHECADAS =====');

        $records = DB::connection('portal_main')
            ->table('checadas')
            ->whereNotNull('evidencia_foto')
            ->whereRaw("TRIM(evidencia_foto) <> ''")
            ->select(
                'id',
                'id_portal',
                'id_cliente',
                'id_empleado',
                'evidencia_foto'
            )
            ->orderBy('id')
            ->get();

        $totals = $this->totals();
        $rows = [];

        foreach ($records as $r) {
            $totals['revisados']++;
            $stored = $this->normalize((string) $r->evidencia_foto);

            if (str_starts_with($stored, '_checadasEvidencia/portales/')) {
                $result = $this->verifyNew($paths, $stored, $verify);
            } else {
                $totals['legacy']++;
                try {
                    $p = $this->parseLegacy($stored, '_checadasEvidencia');

                    if (
                        $p['portal'] !== (int) $r->id_portal
                        || $p['client'] !== (int) $r->id_cliente
                        || $p['employee'] !== (int) $r->id_empleado
                    ) {
                        $result = $this->result(
                            'ERROR_SCOPE_NO_COINCIDE',
                            'errores'
                        );
                    } else {
                        $result = $this->migrate(
                            'checadas',
                            (int) $r->id,
                            'evidencia_foto',
                            $stored,
                            $p,
                            $paths,
                            $execute,
                            $manifest
                        );
                    }
                } catch (Throwable $e) {
                    $result = $this->result(
                        'ERROR: ' . $e->getMessage(),
                        'errores'
                    );
                }
            }

            if (isset($totals[$result['counter']])) {
                $totals[$result['counter']]++;
            }

            if (! $summary) {
                $rows[] = [
                    (int) $r->id,
                    (int) $r->id_portal,
                    (int) $r->id_cliente,
                    (int) $r->id_empleado,
                    $stored,
                    $result['target'] ?? '-',
                    $result['hash']
                        ? substr($result['hash'], 0, 16) . '…'
                        : '-',
                    $result['status'],
                ];
            }
        }

        if ($rows !== []) {
            $this->table(
                [
                    'Checada',
                    'Portal',
                    'Cliente',
                    'Empleado',
                    'Ruta actual',
                    'Ruta nueva',
                    'SHA-256',
                    'Resultado',
                ],
                $rows
            );
        }

        $this->showTotals($totals);
        return $totals;
    }

    private function processTareas(
        TaskEvidencePathService $paths,
        bool $execute,
        bool $verify,
        bool $summary,
        ?string $manifest
    ): array {
        $this->newLine();
        $this->info('===== TAREAS =====');

        $records = DB::connection('portal_main')
            ->table('comunicacion360_empleado_tarea_evidencias as ev')
            ->join(
                'comunicacion360_empleado_tareas as t',
                't.id',
                '=',
                'ev.empleado_tarea_id'
            )
            ->join('empleados as e', 'e.id', '=', 't.empleado_id')
            ->where('ev.activo', 1)
            ->whereNull('ev.deleted_at')
            ->whereNotNull('ev.ruta_archivo')
            ->whereRaw("TRIM(ev.ruta_archivo) <> ''")
            ->select([
                'ev.id',
                'ev.id_portal',
                'ev.ruta_archivo',
                't.empleado_id',
                'e.id_portal as employee_portal',
                'e.id_cliente as employee_client',
            ])
            ->orderBy('ev.id')
            ->get();

        $totals = $this->totals();
        $rows = [];

        foreach ($records as $r) {
            $totals['revisados']++;
            $stored = $this->normalize((string) $r->ruta_archivo);

            if (str_starts_with($stored, '_evidenciasTarea/portales/')) {
                $result = $this->verifyNew($paths, $stored, $verify);
            } else {
                $totals['legacy']++;
                try {
                    $p = $this->parseLegacy($stored, '_evidenciasTarea');

                    if (
                        $p['portal'] !== (int) $r->id_portal
                        || $p['portal'] !== (int) $r->employee_portal
                        || $p['client'] !== (int) $r->employee_client
                        || $p['employee'] !== (int) $r->empleado_id
                    ) {
                        $result = $this->result(
                            'ERROR_SCOPE_NO_COINCIDE',
                            'errores'
                        );
                    } else {
                        $result = $this->migrate(
                            'comunicacion360_empleado_tarea_evidencias',
                            (int) $r->id,
                            'ruta_archivo',
                            $stored,
                            $p,
                            $paths,
                            $execute,
                            $manifest
                        );
                    }
                } catch (Throwable $e) {
                    $result = $this->result(
                        'ERROR: ' . $e->getMessage(),
                        'errores'
                    );
                }
            }

            if (isset($totals[$result['counter']])) {
                $totals[$result['counter']]++;
            }

            if (! $summary) {
                $rows[] = [
                    (int) $r->id,
                    (int) $r->id_portal,
                    (int) $r->employee_client,
                    (int) $r->empleado_id,
                    $stored,
                    $result['target'] ?? '-',
                    $result['hash']
                        ? substr($result['hash'], 0, 16) . '…'
                        : '-',
                    $result['status'],
                ];
            }
        }

        if ($rows !== []) {
            $this->table(
                [
                    'Evidencia',
                    'Portal',
                    'Cliente',
                    'Empleado',
                    'Ruta actual',
                    'Ruta nueva',
                    'SHA-256',
                    'Resultado',
                ],
                $rows
            );
        }

        $this->showTotals($totals);
        return $totals;
    }

    private function migrate(
        string $table,
        int $id,
        string $column,
        string $stored,
        array $p,
        object $paths,
        bool $execute,
        ?string $manifest
    ): array {
        $source = $paths->resolveExisting($stored);

        if ($source === null || ! is_file($source)) {
            return $this->result('ORIGEN_NO_EXISTE', 'origen_no_existe');
        }

        if (! is_readable($source)) {
            return $this->result('ERROR_ORIGEN_NO_LEGIBLE', 'errores');
        }

        $size = filesize($source);
        if ($size === false || $size <= 0) {
            return $this->result('ERROR_ORIGEN_VACIO', 'errores');
        }

        $hash = hash_file('sha256', $source);
        if ($hash === false) {
            return $this->result('ERROR_HASH_ORIGEN', 'errores');
        }

        $relativeDir = $paths->newRelativeDirectory(
            $p['portal'],
            $p['client'],
            $p['employee'],
            $p['month']
        );

        $targetStored = $relativeDir . '/' . $p['filename'];

        $targetDir = $paths->newFullDirectory(
            $p['portal'],
            $p['client'],
            $p['employee'],
            $p['month']
        );

        $target = rtrim($targetDir, '/\\')
            . DIRECTORY_SEPARATOR
            . $p['filename'];

        $targetExists = is_file($target);

        if ($targetExists) {
            if (! is_readable($target)) {
                return $this->result(
                    'ERROR_DESTINO_NO_LEGIBLE',
                    'errores',
                    $targetStored,
                    $hash
                );
            }

            $targetHash = hash_file('sha256', $target);

            if ($targetHash === false) {
                return $this->result(
                    'ERROR_HASH_DESTINO',
                    'errores',
                    $targetStored,
                    $hash
                );
            }

            if ($targetHash !== $hash) {
                return $this->result(
                    'ERROR_DESTINO_DIFERENTE',
                    'destino_diferente',
                    $targetStored,
                    $hash
                );
            }
        }

        if (! $execute) {
            return $this->result(
                $targetExists
                    ? 'MIGRABLE_DESTINO_EXISTE_MISMO_HASH'
                    : 'MIGRABLE',
                'migrables',
                $targetStored,
                $hash
            );
        }

        $created = false;

        try {
            if (! $targetExists) {
                $this->copyVerified($source, $target, $hash);
                $created = true;
            }

            $this->updatePath(
                $table,
                $id,
                $column,
                $stored,
                $targetStored
            );
        } catch (Throwable $e) {
            if ($created && is_file($target)) {
                @unlink($target);
            }
            throw $e;
        }

        if ($manifest !== null) {
            $this->appendManifest($manifest, [
                'fecha' => now()->toIso8601String(),
                'tabla' => $table,
                'registro_id' => $id,
                'columna_ruta' => $column,
                'ruta_anterior' => $stored,
                'ruta_nueva' => $targetStored,
                'id_portal' => $p['portal'],
                'id_cliente' => $p['client'],
                'id_empleado' => $p['employee'],
                'mes' => $p['month'],
                'origen_fisico' => $source,
                'destino_fisico' => $target,
                'tamano_bytes' => filesize($target),
                'sha256_origen' => $hash,
                'sha256_destino' => hash_file('sha256', $target),
                'archivo_legacy_conservado' => true,
                'resultado' => 'MIGRADO',
            ]);
        }

        return $this->result(
            'MIGRADO',
            'ejecutados',
            $targetStored,
            $hash
        );
    }

    private function verifyNew(
        object $paths,
        string $stored,
        bool $verify
    ): array {
        if (! $verify) {
            return $this->result(
                'YA_MIGRADO',
                'ya_migrados',
                $stored
            );
        }

        $path = $paths->resolveExisting($stored);

        if ($path === null || ! is_file($path)) {
            return $this->result(
                'ERROR_DESTINO_NO_EXISTE',
                'errores',
                $stored
            );
        }

        if (! is_readable($path)) {
            return $this->result(
                'ERROR_DESTINO_NO_LEGIBLE',
                'errores',
                $stored
            );
        }

        $size = filesize($path);
        if ($size === false || $size <= 0) {
            return $this->result(
                'ERROR_DESTINO_VACIO',
                'errores',
                $stored
            );
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            return $this->result(
                'ERROR_HASH_DESTINO',
                'errores',
                $stored
            );
        }

        return $this->result(
            'VERIFICADO',
            'ya_migrados',
            $stored,
            $hash
        );
    }

    private function updatePath(
        string $table,
        int $id,
        string $column,
        string $previous,
        string $target
    ): void {
        DB::connection('portal_main')->transaction(
            function () use ($table, $id, $column, $previous, $target): void {
                $current = DB::connection('portal_main')
                    ->table($table)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (! $current) {
                    throw new RuntimeException('El registro dejó de existir.');
                }

                $currentValue = $this->normalize(
                    (string) ($current->{$column} ?? '')
                );

                if ($currentValue !== $previous) {
                    throw new RuntimeException(
                        'La ruta cambió después de la simulación.'
                    );
                }

                $updated = DB::connection('portal_main')
                    ->table($table)
                    ->where('id', $id)
                    ->update([$column => $target]);

                if ($updated !== 1) {
                    throw new RuntimeException(
                        'No fue posible actualizar la ruta en base de datos.'
                    );
                }
            }
        );
    }

    private function copyVerified(
        string $source,
        string $target,
        string $sourceHash
    ): void {
        $dir = dirname($target);

        if (
            ! is_dir($dir)
            && ! mkdir($dir, 0755, true)
            && ! is_dir($dir)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio destino.'
            );
        }

        if (! is_writable($dir)) {
            throw new RuntimeException(
                'El directorio destino no tiene permisos de escritura.'
            );
        }

        $tmp = $target
            . '.tmp.'
            . getmypid()
            . '.'
            . bin2hex(random_bytes(4));

        if (! copy($source, $tmp)) {
            throw new RuntimeException(
                'No se pudo copiar el archivo temporal.'
            );
        }

        try {
            $tmpHash = hash_file('sha256', $tmp);

            if ($tmpHash !== $sourceHash) {
                throw new RuntimeException(
                    'El hash temporal no coincide con el origen.'
                );
            }

            $sourceSize = filesize($source);
            $tmpSize = filesize($tmp);

            if (
                $sourceSize === false
                || $tmpSize === false
                || $sourceSize !== $tmpSize
            ) {
                throw new RuntimeException(
                    'El tamaño temporal no coincide con el origen.'
                );
            }

            if (! rename($tmp, $target)) {
                throw new RuntimeException(
                    'No se pudo establecer el archivo definitivo.'
                );
            }

            @chmod($target, 0640);

            $targetHash = hash_file('sha256', $target);

            if ($targetHash !== $sourceHash) {
                @unlink($target);
                throw new RuntimeException(
                    'Falló la verificación SHA-256 final.'
                );
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function parseLegacy(
        string $stored,
        string $category
    ): array {
        $pattern = '#^'
            . preg_quote($category, '#')
            . '/([1-9][0-9]*)'
            . '/([1-9][0-9]*)'
            . '/([1-9][0-9]*)'
            . '/([0-9]{4}-(?:0[1-9]|1[0-2]))'
            . '/([^/]+)$#';

        if (! preg_match($pattern, $stored, $m)) {
            throw new RuntimeException(
                'La ruta legacy no tiene la estructura esperada.'
            );
        }

        $filename = basename($m[5]);

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, "\0")
        ) {
            throw new RuntimeException(
                'Nombre de archivo no válido.'
            );
        }

        return [
            'portal' => (int) $m[1],
            'client' => (int) $m[2],
            'employee' => (int) $m[3],
            'month' => $m[4],
            'filename' => $filename,
        ];
    }

    private function normalize(string $value): string
    {
        return ltrim(
            str_replace('\\', '/', trim($value)),
            '/'
        );
    }

    private function scope(): array
    {
        $all = (bool) $this->option('all');
        $checadas = (bool) $this->option('checadas');
        $tareas = (bool) $this->option('tareas');

        $count = (int) $all + (int) $checadas + (int) $tareas;

        if ($count === 0) {
            throw new InvalidArgumentException(
                'Indica --all, --checadas o --tareas.'
            );
        }

        if ($count > 1) {
            throw new InvalidArgumentException(
                'Usa sólo uno: --all, --checadas o --tareas.'
            );
        }

        if ($all) {
            return [
                'checadas' => true,
                'tareas' => true,
                'description' => 'checadas y tareas',
                'name' => 'todas',
            ];
        }

        if ($checadas) {
            return [
                'checadas' => true,
                'tareas' => false,
                'description' => 'checadas',
                'name' => 'checadas',
            ];
        }

        return [
            'checadas' => false,
            'tareas' => true,
            'description' => 'tareas',
            'name' => 'tareas',
        ];
    }

    private function validateConfig(): void
    {
        if (trim((string) config('paths.documents_path')) === '') {
            throw new RuntimeException(
                'paths.documents_path no está configurado.'
            );
        }

        if (trim((string) config('paths.images_path')) === '') {
            throw new RuntimeException(
                'paths.images_path no está configurado.'
            );
        }
    }

    private function totals(): array
    {
        return [
            'revisados' => 0,
            'legacy' => 0,
            'migrables' => 0,
            'ya_migrados' => 0,
            'ejecutados' => 0,
            'origen_no_existe' => 0,
            'destino_diferente' => 0,
            'errores' => 0,
        ];
    }

    private function showTotals(array $t): void
    {
        $this->newLine();
        $this->table(
            ['Concepto', 'Total'],
            [
                ['Revisados', $t['revisados']],
                ['Legacy', $t['legacy']],
                ['Migrables', $t['migrables']],
                ['Ya migrados/verificados', $t['ya_migrados']],
                ['Ejecutados', $t['ejecutados']],
                ['Origen no existe', $t['origen_no_existe']],
                ['Destino diferente', $t['destino_diferente']],
                ['Errores', $t['errores']],
            ]
        );
    }

    private function manifestPath(string $name): string
    {
        $dir = storage_path('app/migration-manifests');

        if (
            ! is_dir($dir)
            && ! mkdir($dir, 0750, true)
            && ! is_dir($dir)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de manifiestos.'
            );
        }

        return $dir
            . DIRECTORY_SEPARATOR
            . 'evidencias_operacion_'
            . $name
            . '_'
            . now()->format('Ymd_His')
            . '.jsonl';
    }

    private function appendManifest(
        string $path,
        array $payload
    ): void {
        $written = file_put_contents(
            $path,
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
        ?string $target = null,
        ?string $hash = null
    ): array {
        return [
            'status' => $status,
            'counter' => $counter,
            'target' => $target,
            'hash' => $hash,
        ];
    }
}
