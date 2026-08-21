<?php

namespace App\Services\Documents;

use App\Models\Evaluacion;
use InvalidArgumentException;
use RuntimeException;

class EvaluationDocumentPathService
{
    private const CATEGORY = '_evaluacionesPortal';

    public function activeDirectory(
        int $portalId,
        int $clientId
    ): string {
        return $this->newStoragePath(
            $this->activeRelativeDirectory(
                $portalId,
                $clientId
            )
        );
    }

    public function storedPath(
        int $portalId,
        int $clientId,
        string $fileName
    ): string {
        $fileName = basename(
            str_replace('\\', '/', trim($fileName))
        );

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'El nombre físico de la evaluación está vacío.'
            );
        }

        return $this->activeRelativeDirectory(
            $portalId,
            $clientId
        ) . '/' . $fileName;
    }

    public function existingAbsolutePath(
        string $storedValue
    ): string {
        $storedValue = $this->normalize($storedValue);

        if (str_starts_with($storedValue, 'portales/')) {
            return $this->validatedExistingPath(
                $this->documentsRoot(),
                $storedValue
            );
        }

        $legacyRelativePath = self::CATEGORY
            . '/'
            . $storedValue;

        try {
            return $this->validatedExistingPath(
                $this->imagesRoot(),
                $legacyRelativePath
            );
        } catch (RuntimeException $exception) {
            /*
             * El flujo histórico guardaba en BD el nombre original,
             * pero físicamente DocumentController generaba un ZIP.
             */
            return $this->validatedExistingPath(
                $this->imagesRoot(),
                $legacyRelativePath . '.zip'
            );
        }
    }

    public function storageOrigin(
        string $storedValue
    ): string {
        $storedValue = $this->normalize($storedValue);

        return str_starts_with($storedValue, 'portales/')
            ? 'nuevo'
            : 'antiguo';
    }

    public function moveToTrash(
        Evaluacion $evaluation
    ): ?array {
        $storedValue = $this->normalize(
            (string) $evaluation->name_document
        );

        try {
            $sourcePath = $this->existingAbsolutePath(
                $storedValue
            );
        } catch (RuntimeException $exception) {
            return null;
        }

        $portalId = (int) $evaluation->id_portal;
        $clientId = (int) $evaluation->id_cliente;

        $this->validateIds($portalId, $clientId);

        $trashDirectory = implode('/', [
            'portales',
            $portalId,
            '_borrados',
            self::CATEGORY,
            'clientes',
            $clientId,
        ]);

        $trashFileName = date('Ymd_His')
            . '_'
            . basename($sourcePath);

        $trashStoredPath = $this->uniqueTrashStoredPath(
            $trashDirectory,
            $trashFileName
        );

        $trashAbsolutePath = $this->newStoragePath(
            $trashStoredPath
        );

        $hasOtherReferences = Evaluacion::query()
            ->where(
                'name_document',
                (string) $evaluation->name_document
            )
            ->where('eliminado', 0)
            ->where(
                'id',
                '!=',
                (int) $evaluation->id
            )
            ->exists();

        if ($hasOtherReferences) {
            $this->copyFile(
                $sourcePath,
                $trashAbsolutePath
            );
        } else {
            $this->moveFile(
                $sourcePath,
                $trashAbsolutePath
            );
        }

        return [
            'ruta_anterior'       => $storedValue,
            'ruta_borrado'        => $trashStoredPath,
            'origen'              => $this->storageOrigin(
                $storedValue
            ),
            'archivo_compartido'  => $hasOtherReferences,
            'archivo_copiado'     => $hasOtherReferences,
            'source_absolute'     => $sourcePath,
            'trash_absolute'      => $trashAbsolutePath,
        ];
    }

    public function rollbackMovement(
        array $movement
    ): void {
        $trashPath = $movement['trash_absolute'] ?? null;

        if (
            ! is_string($trashPath)
            || ! is_file($trashPath)
        ) {
            return;
        }

        if (
            (bool) ($movement['archivo_copiado'] ?? false)
        ) {
            if (! @unlink($trashPath)) {
                throw new RuntimeException(
                    'No se pudo retirar la copia enviada a borrados.'
                );
            }

            return;
        }

        $sourcePath = $movement['source_absolute'] ?? null;

        if (
            ! is_string($sourcePath)
            || trim($sourcePath) === ''
        ) {
            throw new RuntimeException(
                'No existe una ruta válida para restaurar la evaluación.'
            );
        }

        $this->moveFile(
            $trashPath,
            $sourcePath
        );
    }

    private function activeRelativeDirectory(
        int $portalId,
        int $clientId
    ): string {
        $this->validateIds($portalId, $clientId);

        return implode('/', [
            'portales',
            $portalId,
            self::CATEGORY,
            'clientes',
            $clientId,
        ]);
    }

    private function uniqueTrashStoredPath(
        string $directory,
        string $fileName
    ): string {
        $storedPath = $directory . '/' . $fileName;

        if (
            ! file_exists(
                $this->newStoragePath($storedPath)
            )
        ) {
            return $storedPath;
        }

        $pathInfo = pathinfo($fileName);
        $baseName = $pathInfo['filename'] ?? 'archivo';
        $extension = isset($pathInfo['extension'])
            && $pathInfo['extension'] !== ''
                ? '.' . $pathInfo['extension']
                : '';

        return $directory
            . '/'
            . $baseName
            . '_'
            . bin2hex(random_bytes(3))
            . $extension;
    }

    private function validatedExistingPath(
        string $rootPath,
        string $relativePath
    ): string {
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
                $relativePath
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
                'El archivo de la evaluación no fue encontrado.'
            );
        }

        return $fileRealPath;
    }

    private function newStoragePath(
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

    private function imagesRoot(): string
    {
        return $this->configuredRoot(
            'paths.images_path',
            'La ruta legacy no está configurada.'
        );
    }

    private function documentsRoot(): string
    {
        return $this->configuredRoot(
            'paths.documents_path',
            'La ruta documental nueva no está configurada.'
        );
    }

    private function configuredRoot(
        string $configKey,
        string $message
    ): string {
        $rootPath = rtrim(
            (string) config($configKey),
            '/\\'
        );

        if ($rootPath === '') {
            throw new RuntimeException($message);
        }

        return $rootPath;
    }

    private function normalize(
        string $storedValue
    ): string {
        $storedValue = trim(
            str_replace('\\', '/', $storedValue)
        );

        if (
            $storedValue === ''
            || str_contains($storedValue, "\0")
            || preg_match(
                '#(^|/)\.\.(/|$)#',
                $storedValue
            )
            || filter_var(
                $storedValue,
                FILTER_VALIDATE_URL
            ) !== false
        ) {
            throw new InvalidArgumentException(
                'La ruta de la evaluación no es válida.'
            );
        }

        return ltrim($storedValue, '/');
    }

    private function validateIds(
        int $portalId,
        int $clientId
    ): void {
        if ($portalId <= 0) {
            throw new InvalidArgumentException(
                'La evaluación no tiene un portal válido.'
            );
        }

        if ($clientId <= 0) {
            throw new InvalidArgumentException(
                'La evaluación no tiene un cliente válido.'
            );
        }
    }

    private function copyFile(
        string $sourcePath,
        string $destinationPath
    ): void {
        $this->createDirectory(
            dirname($destinationPath)
        );

        if (! @copy($sourcePath, $destinationPath)) {
            throw new RuntimeException(
                'No se pudo copiar la evaluación a borrados.'
            );
        }

        @chmod($destinationPath, 0664);
    }

    private function moveFile(
        string $sourcePath,
        string $destinationPath
    ): void {
        $this->createDirectory(
            dirname($destinationPath)
        );

        if (! @rename($sourcePath, $destinationPath)) {
            if (
                ! @copy($sourcePath, $destinationPath)
                || ! @unlink($sourcePath)
            ) {
                @unlink($destinationPath);

                throw new RuntimeException(
                    'No se pudo mover la evaluación.'
                );
            }
        }

        @chmod($destinationPath, 0664);
    }

    private function createDirectory(
        string $directory
    ): void {
        if (
            ! is_dir($directory)
            && ! @mkdir($directory, 0755, true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException(
                'No se pudo crear el directorio documental.'
            );
        }
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
