<?php

namespace App\Services\Documents;

use App\Models\ClienteInformacionInterna;
use InvalidArgumentException;
use RuntimeException;

class InternalDocumentPathService
{
    public function activeRelativeDirectory(
        ClienteInformacionInterna $information
    ): string {
        [$portalId, $clientId] = $this->informationIds($information);

        return implode('/', [
            'portales',
            $portalId,
            '_internos',
            'clientes',
            $clientId,
        ]);
    }

    public function activeStoredPath(
        ClienteInformacionInterna $information,
        string $fileName
    ): string {
        $fileName = basename(trim($fileName));

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'El nombre físico del archivo está vacío.'
            );
        }

        return $this->activeRelativeDirectory($information)
            . '/'
            . $fileName;
    }

    public function activeDirectoryPath(
        ClienteInformacionInterna $information
    ): string {
        return $this->newStoragePath(
            $this->activeRelativeDirectory($information)
        );
    }

    public function existingAbsolutePath(
        string $storedPath
    ): string {
        $storedPath = $this->normalizeStoredPath($storedPath);

        $rootPath = $this->storageRoot($storedPath);
        $rootRealPath = realpath($rootPath);

        if ($rootRealPath === false) {
            throw new RuntimeException(
                'La raíz de almacenamiento no está disponible.'
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
            || ! $this->isInsideRoot($fileRealPath, $rootRealPath)
        ) {
            throw new RuntimeException(
                'El archivo no existe o su ruta no es válida.'
            );
        }

        return $fileRealPath;
    }

    public function storageOrigin(string $storedPath): string
    {
        $storedPath = $this->normalizeStoredPath($storedPath);

        return str_starts_with($storedPath, '_internos/')
            ? 'antiguo'
            : 'nuevo';
    }

    public function moveToTrash(
        ClienteInformacionInterna $information,
        int $documentId,
        string $storedPath
    ): ?array {
        if ($documentId <= 0) {
            throw new InvalidArgumentException(
                'El ID del documento interno no es válido.'
            );
        }

        $storedPath = $this->normalizeStoredPath($storedPath);

        try {
            $sourcePath = $this->existingAbsolutePath($storedPath);
        } catch (RuntimeException $exception) {
            return null;
        }

        [$portalId, $clientId] = $this->informationIds($information);

        $originalFileName = basename($storedPath);

        $trashDirectory = implode('/', [
            'portales',
            $portalId,
            '_borrados',
            '_internos',
            'clientes',
            $clientId,
        ]);

        $trashFileName = date('Ymd_His')
            . '_'
            . $originalFileName;

        $trashStoredPath = $trashDirectory
            . '/'
            . $trashFileName;

        $trashAbsolutePath = $this->newStoragePath(
            $trashStoredPath
        );

        if (file_exists($trashAbsolutePath)) {
            $pathInfo = pathinfo($originalFileName);

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

            $trashStoredPath = $trashDirectory
                . '/'
                . $trashFileName;

            $trashAbsolutePath = $this->newStoragePath(
                $trashStoredPath
            );
        }

        $this->moveFile(
            $sourcePath,
            $trashAbsolutePath,
            'No se pudo mover el documento interno a borrados.'
        );

        return [
            'ruta_anterior' => $storedPath,
            'ruta_borrado'  => $trashStoredPath,
            'origen'        => $this->storageOrigin($storedPath),
        ];
    }

    public function restoreMovedFile(
        string $trashStoredPath,
        string $originalStoredPath
    ): void {
        $trashPath = $this->existingAbsolutePath(
            $trashStoredPath
        );

        $destinationPath = $this->absolutePathForWrite(
            $originalStoredPath
        );

        $this->moveFile(
            $trashPath,
            $destinationPath,
            'No se pudo restaurar el documento interno a su ruta original.'
        );
    }

    private function absolutePathForWrite(
        string $storedPath
    ): string {
        $storedPath = $this->normalizeStoredPath($storedPath);

        $rootPath = $this->storageRoot($storedPath);

        return rtrim($rootPath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $storedPath
            );
    }

    private function newStoragePath(
        string $relativePath
    ): string {
        $basePath = rtrim(
            (string) config('paths.documents_path'),
            '/\\'
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
                trim($relativePath, '/')
            );
    }

    private function storageRoot(string $storedPath): string
    {
        if (str_starts_with($storedPath, '_internos/')) {
            $configKey = 'paths.images_path';
        } elseif (str_starts_with($storedPath, 'portales/')) {
            $configKey = 'paths.documents_path';
        } else {
            throw new InvalidArgumentException(
                'La ruta del documento interno no pertenece a una estructura válida.'
            );
        }

        $rootPath = rtrim(
            (string) config($configKey),
            '/\\'
        );

        if ($rootPath === '') {
            throw new RuntimeException(
                'La raíz de almacenamiento no está configurada.'
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
            || filter_var($storedPath, FILTER_VALIDATE_URL) !== false
        ) {
            throw new InvalidArgumentException(
                'La ruta del documento interno no es válida.'
            );
        }

        return ltrim($storedPath, '/');
    }

    private function informationIds(
        ClienteInformacionInterna $information
    ): array {
        $portalId = (int) $information->id_portal;
        $clientId = (int) $information->id_cliente;

        if ($portalId <= 0) {
            throw new InvalidArgumentException(
                'La información interna no tiene un portal válido.'
            );
        }

        if ($clientId <= 0) {
            throw new InvalidArgumentException(
                'La información interna no tiene un cliente válido.'
            );
        }

        return [$portalId, $clientId];
    }

    private function moveFile(
        string $sourcePath,
        string $destinationPath,
        string $errorMessage
    ): void {
        $destinationDirectory = dirname($destinationPath);

        if (
            ! is_dir($destinationDirectory)
            && ! @mkdir($destinationDirectory, 0755, true)
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

                throw new RuntimeException($errorMessage);
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