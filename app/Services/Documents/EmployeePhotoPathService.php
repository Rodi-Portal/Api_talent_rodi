<?php

namespace App\Services\Documents;

use App\Models\Empleado;
use RuntimeException;

class EmployeePhotoPathService
{
    private const CATEGORY = '_perfilEmpleado';

    /**
     * Ruta relativa definitiva de la foto del empleado.
     *
     * portales/{portal}/_perfilEmpleado/clientes/{cliente}/empleados/{empleado}
     */
    public function activeRelativeDirectory(Empleado $employee): string
    {
        $this->assertEmployeeScope($employee);

        return implode('/', [
            'portales',
            (int) $employee->id_portal,
            self::CATEGORY,
            'clientes',
            (int) $employee->id_cliente,
            'empleados',
            (int) $employee->id,
        ]);
    }

    /**
     * Directorio absoluto definitivo dentro de storagetalentsafe.
     */
    public function activeDirectory(Empleado $employee): string
    {
        $basePath = $this->documentsBasePath();

        return $basePath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $this->activeRelativeDirectory($employee)
            );
    }

    /**
     * Ruta absoluta definitiva de una foto.
     */
    public function activePath(
        Empleado $employee,
        string $filename
    ): string {
        return $this->activeDirectory($employee)
            . DIRECTORY_SEPARATOR
            . $this->normalizeFilename($filename);
    }

    /**
     * Crea el directorio definitivo si todavía no existe.
     */
    public function ensureActiveDirectory(Empleado $employee): string
    {
        $directory = $this->activeDirectory($employee);

        if (
            ! is_dir($directory)
            && ! mkdir($directory, 0770, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'No fue posible crear el directorio de foto de perfil.'
            );
        }

        return $directory;
    }

    /**
     * Ruta legacy:
     *
     * {images_path}/_perfilEmpleado/{archivo}
     */
    public function legacyPath(string $filename): string
    {
        $basePath = $this->imagesBasePath();

        return $basePath
            . DIRECTORY_SEPARATOR
            . self::CATEGORY
            . DIRECTORY_SEPARATOR
            . $this->normalizeFilename($filename);
    }

    /**
     * Lectura dual por empleado.
     *
     * 1. storagetalentsafe
     * 2. _perfilEmpleado legacy
     * 3. perfil.png legacy
     */
    public function resolveReadablePath(
        Empleado $employee,
        ?string $filename
    ): ?string {
        $filename = $this->normalizeOptionalFilename($filename);

        if ($filename !== null) {
            $documentsBasePath = $this->configuredPath(
                'paths.documents_path'
            );

            if ($documentsBasePath !== null) {
                $candidate = $documentsBasePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $this->activeRelativeDirectory($employee)
                    )
                    . DIRECTORY_SEPARATOR
                    . $filename;

                if (is_file($candidate) && is_readable($candidate)) {
                    return $candidate;
                }
            }

            $imagesBasePath = $this->configuredPath(
                'paths.images_path'
            );

            if ($imagesBasePath !== null) {
                $legacy = $imagesBasePath
                    . DIRECTORY_SEPARATOR
                    . self::CATEGORY
                    . DIRECTORY_SEPARATOR
                    . $filename;

                if (is_file($legacy) && is_readable($legacy)) {
                    return $legacy;
                }
            }
        }

        return $this->defaultReadablePath();
    }

    /**
     * Resuelve una foto cuando el endpoint solamente conoce el nombre.
     *
     * Primero localiza al empleado cuyo campo foto coincide.
     * Si no existe registro, todavía intenta el archivo legacy.
     */
    public function resolveReadablePathByFilename(
        ?string $filename
    ): ?string {
        $filename = $this->normalizeOptionalFilename($filename);

        if ($filename === null) {
            return $this->defaultReadablePath();
        }

        $employee = Empleado::query()
            ->where('foto', $filename)
            ->first();

        if ($employee) {
            return $this->resolveReadablePath(
                $employee,
                $filename
            );
        }

        $imagesBasePath = $this->configuredPath(
            'paths.images_path'
        );

        if ($imagesBasePath !== null) {
            $legacy = $imagesBasePath
                . DIRECTORY_SEPARATOR
                . self::CATEGORY
                . DIRECTORY_SEPARATOR
                . $filename;

            if (is_file($legacy) && is_readable($legacy)) {
                return $legacy;
            }
        }

        return $this->defaultReadablePath();
    }

    /**
     * Ruta donde se conservarán fotos reemplazadas.
     *
     * portales/{portal}/_borrados/reemplazados/_perfilEmpleado/
     * clientes/{cliente}/empleados/{empleado}
     */
    public function replacementTrashDirectory(
        Empleado $employee
    ): string {
        $this->assertEmployeeScope($employee);

        $basePath = $this->documentsBasePath();

        $relative = implode('/', [
            'portales',
            (int) $employee->id_portal,
            '_borrados',
            'reemplazados',
            self::CATEGORY,
            'clientes',
            (int) $employee->id_cliente,
            'empleados',
            (int) $employee->id,
        ]);

        return $basePath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relative
            );
    }

    /**
     * Conserva una foto activa de storagetalentsafe antes de reemplazarla.
     *
     * Las fotos legacy no se eliminan durante la transición.
     */
    public function archiveActivePhoto(
        Empleado $employee,
        ?string $filename
    ): ?string {
        $filename = $this->normalizeOptionalFilename($filename);

        if ($filename === null) {
            return null;
        }

        $source = $this->activePath(
            $employee,
            $filename
        );

        if (! is_file($source)) {
            return null;
        }

        $trashDirectory = $this->replacementTrashDirectory(
            $employee
        );

        if (
            ! is_dir($trashDirectory)
            && ! mkdir($trashDirectory, 0770, true)
            && ! is_dir($trashDirectory)
        ) {
            throw new RuntimeException(
                'No fue posible crear el directorio de reemplazados.'
            );
        }

        $destination = $trashDirectory
            . DIRECTORY_SEPARATOR
            . $filename;

        if (file_exists($destination)) {
            $info = pathinfo($filename);

            $name = $info['filename'] ?? 'foto';
            $extension = isset($info['extension'])
                ? '.' . $info['extension']
                : '';

            $destination = $trashDirectory
                . DIRECTORY_SEPARATOR
                . $name
                . '_'
                . date('Ymd_His')
                . $extension;
        }

        if (! rename($source, $destination)) {
            throw new RuntimeException(
                'No fue posible mover la foto reemplazada.'
            );
        }

        return $destination;
    }

    /**
     * Foto predeterminada legacy.
     *
     * Durante la transición perfil.png permanece en _perfilEmpleado.
     */
    /**
     * Resuelve una foto existente usando directamente el scope
     * del empleado, sin realizar consultas adicionales a BD.
     *
     * No aplica perfil.png como fallback.
     */
    public function resolveExistingPathForScope(
        int $portalId,
        int $clientId,
        int $employeeId,
        ?string $filename
    ): ?string {
        $filename = $this->normalizeOptionalFilename($filename);

        if (
            $filename === null
            || $portalId <= 0
            || $clientId <= 0
            || $employeeId <= 0
        ) {
            return null;
        }

        $documentsBasePath = $this->configuredPath(
            'paths.documents_path'
        );

        if ($documentsBasePath !== null) {
            $relative = implode('/', [
                'portales',
                $portalId,
                self::CATEGORY,
                'clientes',
                $clientId,
                'empleados',
                $employeeId,
            ]);

            $candidate = $documentsBasePath
                . DIRECTORY_SEPARATOR
                . str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    $relative
                )
                . DIRECTORY_SEPARATOR
                . $filename;

            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        $imagesBasePath = $this->configuredPath(
            'paths.images_path'
        );

        if ($imagesBasePath !== null) {
            $legacy = $imagesBasePath
                . DIRECTORY_SEPARATOR
                . self::CATEGORY
                . DIRECTORY_SEPARATOR
                . $filename;

            if (is_file($legacy) && is_readable($legacy)) {
                return $legacy;
            }
        }

        return null;
    }

    public function defaultReadablePath(): ?string
    {
        $imagesBasePath = $this->configuredPath(
            'paths.images_path'
        );

        if ($imagesBasePath === null) {
            return null;
        }

        $defaultPath = $imagesBasePath
            . DIRECTORY_SEPARATOR
            . self::CATEGORY
            . DIRECTORY_SEPARATOR
            . 'perfil.png';

        return is_file($defaultPath) && is_readable($defaultPath)
            ? $defaultPath
            : null;
    }

    private function documentsBasePath(): string
    {
        $path = $this->configuredPath(
            'paths.documents_path'
        );

        if ($path === null) {
            throw new RuntimeException(
                'La ruta documents_path no está configurada.'
            );
        }

        return $path;
    }

    private function imagesBasePath(): string
    {
        $path = $this->configuredPath(
            'paths.images_path'
        );

        if ($path === null) {
            throw new RuntimeException(
                'La ruta images_path no está configurada.'
            );
        }

        return $path;
    }

    private function configuredPath(string $key): ?string
    {
        $path = trim((string) config($key));

        if ($path === '') {
            return null;
        }

        return rtrim($path, '/\\');
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = basename(trim($filename));

        if ($filename === '') {
            throw new RuntimeException(
                'Nombre de archivo de foto inválido.'
            );
        }

        return $filename;
    }

    private function normalizeOptionalFilename(
        ?string $filename
    ): ?string {
        if ($filename === null) {
            return null;
        }

        $filename = basename(trim($filename));

        return $filename !== ''
            ? $filename
            : null;
    }

    private function assertEmployeeScope(
        Empleado $employee
    ): void {
        if (
            (int) $employee->id <= 0
            || (int) $employee->id_portal <= 0
            || (int) $employee->id_cliente <= 0
        ) {
            throw new RuntimeException(
                'El empleado no tiene portal, cliente o ID válido.'
            );
        }
    }
}