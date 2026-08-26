<?php

namespace App\Services\Documents;

use InvalidArgumentException;
use RuntimeException;

class ReminderDocumentPathService
{
    public function activeRelativeDirectory(
        int $portalId,
        int $clientId
    ): string {
        $this->validateIds($portalId, $clientId);

        return implode('/', [
            'portales',
            $portalId,
            '_recordatorios',
            'clientes',
            $clientId,
        ]);
    }

    public function activeStoredPath(
        int $portalId,
        int $clientId,
        string $fileName
    ): string {
        $fileName = basename(trim($fileName));

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'El nombre físico de la evidencia está vacío.'
            );
        }

        return $this->activeRelativeDirectory(
            $portalId,
            $clientId
        )
            . '/'
            . $fileName;
    }

    public function activeDirectoryPath(
        int $portalId,
        int $clientId
    ): string {
        return $this->documentsPath(
            $this->activeRelativeDirectory(
                $portalId,
                $clientId
            )
        );
    }

    public function existingAbsolutePath(
        string $storedPath,
        int $portalId,
        int $clientId
    ): string {
        $this->validateIds($portalId, $clientId);

        $storedPath = $this->normalizeStoredPath(
            $storedPath
        );

        $expectedPrefix = $this->activeRelativeDirectory(
            $portalId,
            $clientId
        ) . '/';

        if (! str_starts_with($storedPath, $expectedPrefix)) {
            throw new InvalidArgumentException(
                'La evidencia no pertenece al portal y cliente autorizados.'
            );
        }

        $rootPath = $this->documentsRoot();
        $rootRealPath = realpath($rootPath);

        if ($rootRealPath === false) {
            throw new RuntimeException(
                'La raíz documental nueva no está disponible.'
            );
        }

        $candidatePath = $rootRealPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedPath
            );

        $fileRealPath = realpath($candidatePath);

        if (
            $fileRealPath === false
            || ! is_file($fileRealPath)
            || ! $this->isInsideRoot(
                $fileRealPath,
                $rootRealPath
            )
        ) {
            throw new RuntimeException(
                'La evidencia no existe o su ruta no es válida.'
            );
        }

        return $fileRealPath;
    }
        public function moveToTrash(
        string $storedPath,
        int $portalId,
        int $clientId
    ): ?array {
        $this->validateIds($portalId, $clientId);

        $storedPath = $this->normalizeStoredPath(
            $storedPath
        );

        $activePrefix = $this->activeRelativeDirectory(
            $portalId,
            $clientId
        ) . '/';

        if (! str_starts_with($storedPath, $activePrefix)) {
            throw new InvalidArgumentException(
                'La evidencia no pertenece al portal y cliente autorizados.'
            );
        }

        try {
            $sourcePath = $this->existingAbsolutePath(
                $storedPath,
                $portalId,
                $clientId
            );
        } catch (RuntimeException $exception) {
            return null;
        }

        $trashDirectory = implode('/', [
            'portales',
            $portalId,
            '_borrados',
            '_recordatorios',
            'clientes',
            $clientId,
        ]);

        $originalFileName = basename($storedPath);

        $trashFileName = date('Ymd_His')
            . '_'
            . $originalFileName;

        $trashStoredPath = $trashDirectory
            . '/'
            . $trashFileName;

        $trashAbsolutePath = $this->documentsPath(
            $trashStoredPath
        );

        if (file_exists($trashAbsolutePath)) {
            $pathInfo = pathinfo($originalFileName);

            $trashFileName = date('Ymd_His')
                . '_'
                . ($pathInfo['filename'] ?? 'evidencia')
                . '_'
                . bin2hex(random_bytes(3))
                . (
                    isset($pathInfo['extension'])
                    && $pathInfo['extension'] !== ''
                        ? '.' . $pathInfo['extension']
                        : ''
                );

            $trashStoredPath = $trashDirectory
                . '/'
                . $trashFileName;

            $trashAbsolutePath = $this->documentsPath(
                $trashStoredPath
            );
        }

        $this->moveFile(
            $sourcePath,
            $trashAbsolutePath,
            'No se pudo mover la evidencia del recordatorio a borrados.'
        );

        return [
            'ruta_anterior' => $storedPath,
            'ruta_borrado'  => $trashStoredPath,
        ];
    }

    public function restoreMovedFile(
        string $trashStoredPath,
        string $originalStoredPath,
        int $portalId,
        int $clientId
    ): void {
        $this->validateIds($portalId, $clientId);

        $trashStoredPath = $this->normalizeStoredPath(
            $trashStoredPath
        );

        $originalStoredPath = $this->normalizeStoredPath(
            $originalStoredPath
        );

        $expectedTrashPrefix = implode('/', [
            'portales',
            $portalId,
            '_borrados',
            '_recordatorios',
            'clientes',
            $clientId,
        ]) . '/';

        $expectedActivePrefix = $this->activeRelativeDirectory(
            $portalId,
            $clientId
        ) . '/';

        if (
            ! str_starts_with(
                $trashStoredPath,
                $expectedTrashPrefix
            )
            || ! str_starts_with(
                $originalStoredPath,
                $expectedActivePrefix
            )
        ) {
            throw new InvalidArgumentException(
                'Las rutas de restauración de la evidencia no son válidas.'
            );
        }

        $trashAbsolutePath = $this->existingStoredAbsolutePath(
            $trashStoredPath
        );

        $originalAbsolutePath = $this->documentsPath(
            $originalStoredPath
        );

        $this->moveFile(
            $trashAbsolutePath,
            $originalAbsolutePath,
            'No se pudo restaurar la evidencia del recordatorio.'
        );
    }

    private function documentsPath(
        string $relativePath
    ): string {
        return $this->documentsRoot()
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                trim($relativePath, '/')
            );
    }

    private function documentsRoot(): string
    {
        $rootPath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
        );

        if ($rootPath === '') {
            throw new RuntimeException(
                'La raíz documental nueva no está configurada.'
            );
        }

        return $rootPath;
    }

    private function normalizeStoredPath(
        string $storedPath
    ): string {
        $storedPath = trim(
            str_replace('\\', '/', $storedPath)
        );

        if (
            $storedPath === ''
            || str_contains($storedPath, "\0")
            || str_contains($storedPath, '..')
            || filter_var(
                $storedPath,
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            throw new InvalidArgumentException(
                'La ruta de la evidencia no es válida.'
            );
        }

        return ltrim($storedPath, '/');
    }

    private function validateIds(
        int $portalId,
        int $clientId
    ): void {
        if ($portalId <= 0) {
            throw new InvalidArgumentException(
                'El portal de la evidencia no es válido.'
            );
        }

        if ($clientId <= 0) {
            throw new InvalidArgumentException(
                'El cliente de la evidencia no es válido.'
            );
        }
    }
      private function existingStoredAbsolutePath(
        string $storedPath
    ): string {
        $storedPath = $this->normalizeStoredPath(
            $storedPath
        );

        $rootPath = $this->documentsRoot();
        $rootRealPath = realpath($rootPath);

        if ($rootRealPath === false) {
            throw new RuntimeException(
                'La raíz documental nueva no está disponible.'
            );
        }

        $candidatePath = $rootRealPath
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedPath
            );

        $fileRealPath = realpath($candidatePath);

        if (
            $fileRealPath === false
            || ! is_file($fileRealPath)
            || ! $this->isInsideRoot(
                $fileRealPath,
                $rootRealPath
            )
        ) {
            throw new RuntimeException(
                'La evidencia trasladada no existe o su ruta no es válida.'
            );
        }

        return $fileRealPath;
    }

    private function moveFile(
        string $sourcePath,
        string $destinationPath,
        string $errorMessage
    ): void {
        $destinationDirectory = dirname(
            $destinationPath
        );

        if (
            ! is_dir($destinationDirectory)
            && ! @mkdir(
                $destinationDirectory,
                0755,
                true
            )
            && ! is_dir($destinationDirectory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio de almacenamiento.'
            );
        }

        if (! @rename($sourcePath, $destinationPath)) {
            if (
                ! @copy($sourcePath, $destinationPath)
                || ! @unlink($sourcePath)
            ) {
                @unlink($destinationPath);

                throw new RuntimeException(
                    $errorMessage
                );
            }
        }

        @chmod($destinationPath, 0664);
    }
    private function isInsideRoot(
        string $filePath,
        string $rootPath
    ): bool {
        $rootPrefix = rtrim(
            $rootPath,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        return str_starts_with(
            $filePath,
            $rootPrefix
        );
    }
}