<?php
namespace App\Services\Documents;

use App\Models\CalendarioEvento;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use InvalidArgumentException;
use RuntimeException;

class EmployeeDocumentPathService
{
    public function employeeRelativeDirectory(
        Empleado $employee
    ): string {
        $portalId   = (int) $employee->id_portal;
        $clientId   = (int) $employee->id_cliente;
        $employeeId = (int) $employee->id;

        if ($portalId <= 0) {
            throw new InvalidArgumentException(
                'El empleado no tiene un portal válido.'
            );
        }

        if ($clientId <= 0) {
            throw new InvalidArgumentException(
                'El empleado no tiene un cliente válido.'
            );
        }

        if ($employeeId <= 0) {
            throw new InvalidArgumentException(
                'El empleado no tiene una PK válida.'
            );
        }

        return implode('/', [
            'portales',
            $portalId,
            'clientes',
            $clientId,
            'empleados',
            $employeeId,
        ]);
    }

    public function categoryRelativeDirectory(
        string $categoryFolder,
        Empleado $employee
    ): string {
        $categoryFolder = $this->normalizeCategoryFolder(
            $categoryFolder
        );

        // También valida portal, cliente y PK del empleado.
        $this->employeeRelativeDirectory($employee);

        return implode('/', [
            'portales',
            (int) $employee->id_portal,
            $categoryFolder,
            'clientes',
            (int) $employee->id_cliente,
            'empleados',
            (int) $employee->id,
        ]);
    }
    public function storedPath(
        string $categoryFolder,
        Empleado $employee,
        string $fileName
    ): string {
        $fileName = basename(trim($fileName));

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'El nombre físico del archivo está vacío.'
            );
        }

        return $this->categoryRelativeDirectory(
            $categoryFolder,
            $employee
        )
            . '/'
            . $fileName;
    }

    public function uploadFolder(
        string $categoryFolder,
        Empleado $employee
    ): string {
        return $this->categoryRelativeDirectory(
            $categoryFolder,
            $employee
        );
    }
    public function absolutePath(
        string $categoryFolder,
        string $storedValue
    ): string {
        $categoryFolder = $this->normalizeCategoryFolder(
            $categoryFolder
        );

        $storedValue = $this->normalizeStoredValue($storedValue);

        if ($this->isExternalUrl($storedValue)) {
            throw new InvalidArgumentException(
                'Una URL externa no tiene una ruta física local.'
            );
        }

        $imagesPath = rtrim(
            (string) config('paths.images_path'),
            '/\\'
        );

        $documentsPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        if ($imagesPath === '' || $documentsPath === '') {
            throw new RuntimeException(
                'Las rutas documentales no están configuradas.'
            );
        }
        $legacyCategoryFolder = $categoryFolder === '_incidencias'
            ? '_archivo_calendario'
            : $categoryFolder;

        /*
     * Legacy:
     * _categoria/{nombre_simple}
     */
        if (! str_contains($storedValue, '/')) {
            return $imagesPath
            . DIRECTORY_SEPARATOR
            . $legacyCategoryFolder
            . DIRECTORY_SEPARATOR
            . basename($storedValue);
        }

        /*
         * Incidencias legacy:
         * portals/{portal}/clientes/{cliente}/empleados/{empleado}/
         * incidencias/{archivo}
         *
         * Físicamente continúa dentro de _archivo_calendario.
         */
        if (
            $categoryFolder === '_incidencias'
            && preg_match(
                '#^portals/[1-9][0-9]*/clientes/[1-9][0-9]*/'
                . 'empleados/[1-9][0-9]*/incidencias/#',
                $storedValue
            )
        ) {
            return $imagesPath
            . DIRECTORY_SEPARATOR
            . '_archivo_calendario'
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedValue
            );
        }
        $quotedCategory = preg_quote(
            $categoryFolder,
            '#'
        );

        /*
     * Estructura definitiva:
     * portales/{portal}/_categoria/clientes/...
     */
        if (preg_match(
            '#^portales/[1-9][0-9]*/'
            . $quotedCategory
            . '/clientes/[1-9][0-9]*/empleados/[1-9][0-9]*/#',
            $storedValue
        )) {
            return $documentsPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedValue
            );
        }

        /*
     * Estructura transitoria:
     * BD: portales/{portal}/clientes/...
     * Disco: _categoria/portales/{portal}/clientes/...
     */
        if (preg_match(
            '#^portales/[1-9][0-9]*/clientes/[1-9][0-9]*/empleados/[1-9][0-9]*/#',
            $storedValue
        )) {
            return $documentsPath
            . DIRECTORY_SEPARATOR
            . $categoryFolder
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedValue
            );
        }

        throw new InvalidArgumentException(
            'La ruta documental no pertenece a una estructura válida.'
        );
    }
    public function renewalRelativeDirectory(
        Empleado $employee
    ): string {
        return '_renovaciones/'
        . $this->employeeRelativeDirectory($employee);
    }

    public function renewalStoredPath(
        Empleado $employee,
        string $fileName
    ): string {
        $fileName = basename(trim($fileName));

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'El nombre de la propuesta está vacío.'
            );
        }

        return $this->renewalRelativeDirectory($employee)
            . '/'
            . $fileName;
    }

    public function renewalDirectoryPath(
        Empleado $employee
    ): string {
        $basePath = rtrim(
            (string) config('paths.documents_path'),
            "/\\"
        );

        if ($basePath === '') {
            throw new RuntimeException(
                'La ruta documental nueva no está configurada.'
            );
        }

        return $basePath
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->renewalRelativeDirectory($employee)
        );
    }

    public function renewalAbsolutePath(
        string $storedValue
    ): string {
        $storedValue = $this->normalizeStoredValue(
            $storedValue
        );

        if ($this->isExternalUrl($storedValue)) {
            throw new InvalidArgumentException(
                'Una URL externa no tiene una ruta física local.'
            );
        }

        /*
     * Las propuestas nuevas incluyen el segmento "portales".
     * Las anteriores conservan el formato numérico antiguo.
     */
        $isNewPath = str_starts_with(
            $storedValue,
            '_renovaciones/portales/'
        );

        $configKey = $isNewPath
            ? 'paths.documents_path'
            : 'paths.images_path';

        $basePath = rtrim(
            (string) config($configKey),
            "/\\"
        );

        if ($basePath === '') {
            throw new RuntimeException(
                'La ruta de propuestas no está configurada.'
            );
        }

        return $basePath
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $storedValue
        );
    }
    public function moveRenewalToTrash(
        Empleado $employee,
        int $renewalId,
        string $storedValue,
        string $reason = 'rechazados'
    ): ?string {
        if (! in_array(
            $reason,
            ['rechazados', 'cancelados'],
            true
        )) {
            throw new InvalidArgumentException(
                'El motivo de la propuesta descartada no es válido.'
            );
        }

        if ($renewalId <= 0) {
            throw new InvalidArgumentException(
                'El ID de solicitud no es válido.'
            );
        }

        $sourcePath = $this->renewalAbsolutePath(
            $storedValue
        );

        if (! is_file($sourcePath)) {
            return null;
        }

        $documentsBasePath = rtrim(
            (string) config('paths.documents_path'),
            "/\\"
        );

        if ($documentsBasePath === '') {
            throw new RuntimeException(
                'La ruta documental nueva no está configurada.'
            );
        }

        $trashRelativeDirectory = implode('/', [
            '_borrados',
            $reason,
            '_renovaciones',
            $this->employeeRelativeDirectory($employee),
            'solicitud_' . $renewalId,
        ]);

        $trashDirectory = $documentsBasePath
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $trashRelativeDirectory
        );

        if (
            ! is_dir($trashDirectory)
            && ! @mkdir($trashDirectory, 0755, true)
            && ! is_dir($trashDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de propuestas descartadas.'
            );
        }

        $fileName = date('Ymd_His')
        . '_'
        . basename($storedValue);

        $trashPath = $trashDirectory
            . DIRECTORY_SEPARATOR
            . $fileName;

        if (file_exists($trashPath)) {
            $fileName = date('Ymd_His')
            . '_'
            . bin2hex(random_bytes(3))
            . '_'
            . basename($storedValue);

            $trashPath = $trashDirectory
                . DIRECTORY_SEPARATOR
                . $fileName;
        }

        if (! @rename($sourcePath, $trashPath)) {
            if (
                ! @copy($sourcePath, $trashPath)
                || ! @unlink($sourcePath)
            ) {
                @unlink($trashPath);

                throw new RuntimeException(
                    'No se pudo mover la propuesta descartada.'
                );
            }
        }

        @chmod($trashPath, 0664);

        return $trashRelativeDirectory
            . '/'
            . $fileName;
    }
    public function moveToTrash(
        string $categoryFolder,
        Empleado $employee,
        int $documentId,
        string $storedValue,
        string $reason = 'reemplazados'
    ): ?string {
        $categoryFolder = $this->normalizeCategoryFolder(
            $categoryFolder
        );

        if (! in_array(
            $reason,
            ['reemplazados', 'eliminados'],
            true
        )) {
            throw new InvalidArgumentException(
                'El motivo de borrado documental no es válido.'
            );
        }

        if ($documentId <= 0) {
            throw new InvalidArgumentException(
                'El ID documental no es válido.'
            );
        }

        $storedValue = $this->normalizeStoredValue(
            $storedValue
        );

        /*
     * Las URL externas no pertenecen al almacenamiento local.
     */
        if ($this->isExternalUrl($storedValue)) {
            return null;
        }

        /*
     * absolutePath() permite tomar como origen tanto archivos
     * antiguos como archivos de la estructura nueva.
     */
        $sourcePath = $this->absolutePath(
            $categoryFolder,
            $storedValue
        );

        if (! is_file($sourcePath)) {
            return null;
        }

        $documentsBasePath = rtrim(
            (string) config('paths.documents_path'),
            "/\\"
        );

        if ($documentsBasePath === '') {
            throw new RuntimeException(
                'La ruta documental nueva no está configurada.'
            );
        }

        $fileName = basename($storedValue);

        $trashRelativeDirectory = implode('/', [
            'portales',
            (int) $employee->id_portal,
            '_borrados',
            $reason,
            $categoryFolder,
            'clientes',
            (int) $employee->id_cliente,
            'empleados',
            (int) $employee->id,
        ]);

        $trashDirectory = $documentsBasePath
        . DIRECTORY_SEPARATOR
        . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $trashRelativeDirectory
        );

        if (
            ! is_dir($trashDirectory)
            && ! @mkdir($trashDirectory, 0755, true)
            && ! is_dir($trashDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de borrados.'
            );
        }

        $trashFileName = date('Ymd_His')
            . '_'
            . $fileName;

        $trashPath = $trashDirectory
            . DIRECTORY_SEPARATOR
            . $trashFileName;

        /*
     * Evita una colisión si existen dos operaciones
     * dentro del mismo segundo.
     */
        if (file_exists($trashPath)) {
            $pathInfo = pathinfo($fileName);

            $trashFileName = date('Ymd_His')
            . '_'
            . ($pathInfo['filename'] ?? 'archivo')
            . '_'
            . bin2hex(random_bytes(3))
                . (
                isset($pathInfo['extension'])
                && $pathInfo['extension'] !== ''
                    ? '.' . $pathInfo['extension']
                    : ''
            );

            $trashPath = $trashDirectory
                . DIRECTORY_SEPARATOR
                . $trashFileName;
        }

        $hasOtherReferences = $this->hasOtherActiveReferences(
            $categoryFolder,
            $documentId,
            $storedValue
        );

/*
 * Si otros registros activos comparten el mismo archivo,
 * se conserva el origen y únicamente se crea el respaldo.
 */
        if ($hasOtherReferences) {
            if (! @copy($sourcePath, $trashPath)) {
                @unlink($trashPath);

                throw new RuntimeException(
                    'No se pudo respaldar el archivo compartido.'
                );
            }
        } elseif (! @rename($sourcePath, $trashPath)) {
            /*
     * Respaldo para movimientos entre volúmenes:
     * primero copia y después retira el origen.
     */
            if (
                ! @copy($sourcePath, $trashPath)
                || ! @unlink($sourcePath)
            ) {
                @unlink($trashPath);

                throw new RuntimeException(
                    'No se pudo mover el archivo a borrados.'
                );
            }
        }

        @chmod($trashPath, 0664);

        return $trashRelativeDirectory
            . '/'
            . $trashFileName;
    }
    private function hasOtherActiveReferences(
        string $categoryFolder,
        int $documentId,
        string $storedValue
    ): bool {
        if ($categoryFolder === '_incidencias') {
            return CalendarioEvento::query()
                ->where('id', '<>', $documentId)
                ->where('archivo', $storedValue)
                ->where('eliminado', 0)
                ->exists();
        }
        $modelClass = match ($categoryFolder) {
            '_documentEmpleado' => DocumentEmpleado::class,
            '_cursos'           => CursoEmpleado::class,
            '_examEmpleado'     => ExamEmpleado::class,

            default             => throw new InvalidArgumentException(
                'La categoría documental no admite referencias compartidas.'
            ),
        };

        return $modelClass::query()
            ->where('id', '<>', $documentId)
            ->where('name', $storedValue)
            ->where(function ($query) {
                $query
                    ->whereNull('status')
                    ->orWhere('status', '<>', 999);
            })
            ->exists();
    }
    public function isExternalUrl(string $storedValue): bool
    {
        return filter_var(
            $storedValue,
            FILTER_VALIDATE_URL
        ) !== false;
    }

    private function normalizeCategoryFolder(
        string $categoryFolder
    ): string {
        $categoryFolder = trim(
            str_replace('\\', '/', $categoryFolder),
            '/'
        );

        if (
            $categoryFolder === ''
            || ! preg_match(
                '/^_[A-Za-z0-9_]+$/',
                $categoryFolder
            )
        ) {
            throw new InvalidArgumentException(
                'La categoría documental no es válida.'
            );
        }

        return $categoryFolder;
    }

    private function normalizeStoredValue(
        string $storedValue
    ): string {
        $storedValue = trim(
            str_replace('\\', '/', $storedValue)
        );

        if (
            $storedValue === ''
            || str_contains($storedValue, "\0")
            || str_contains($storedValue, '..')
        ) {
            throw new InvalidArgumentException(
                'La ruta documental almacenada no es válida.'
            );
        }

        return ltrim($storedValue, '/');
    }
}
