<?php

namespace App\Services\Checador;

use RuntimeException;
class TaskEvidencePathService
{
    public function newRelativeDirectory(
        int $portalId,
        int $clientId,
        int $employeeId,
        string $month
    ): string {
        return implode('/', [
            '_evidenciasTarea',
            'portales',
            $portalId,
            'clientes',
            $clientId,
            'empleados',
            $employeeId,
            $month,
        ]);
    }

    public function newFullDirectory(
        int $portalId,
        int $clientId,
        int $employeeId,
        string $month
    ): string {
        $basePath = $this->documentsBasePath();

        return $this->join(
            $basePath,
            $this->newRelativeDirectory(
                $portalId,
                $clientId,
                $employeeId,
                $month
            )
        );
    }

    public function resolveExisting(
        string $storedPath
    ): ?string {
        $relativePath = $this->normalizeRelativePath(
            $storedPath
        );

        if ($relativePath === null) {
            return null;
        }

        $isNewPath = str_starts_with(
            $relativePath,
            '_evidenciasTarea/portales/'
        );

        $basePaths = $isNewPath
            ? [
                config('paths.documents_path'),
                config('paths.images_path'),
            ]
            : [
                config('paths.images_path'),
                config('paths.documents_path'),
            ];

        foreach ($basePaths as $basePath) {
            $resolved = $this->resolveInsideBase(
                $basePath,
                $relativePath
            );

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function documentsBasePath(): string
    {
        $basePath = trim(
            (string) config('paths.documents_path')
        );

        if ($basePath === '') {
            throw new RuntimeException(
                'La ruta base de documentos no está configurada.'
            );
        }

        return rtrim($basePath, '/\\');
    }

    private function normalizeRelativePath(
        string $storedPath
    ): ?string {
        $relativePath = ltrim(
            str_replace('\\', '/', trim($storedPath)),
            '/'
        );

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || preg_match(
                '#(^|/)\.\.(/|$)#',
                $relativePath
            )
        ) {
            return null;
        }

        return $relativePath;
    }

    private function resolveInsideBase(
        mixed $basePath,
        string $relativePath
    ): ?string {
        $basePath = trim((string) $basePath);

        if ($basePath === '') {
            return null;
        }

        $baseRealPath = realpath($basePath);

        if ($baseRealPath === false) {
            return null;
        }

        $candidate = $this->join(
            $baseRealPath,
            $relativePath
        );

        $candidateRealPath = realpath($candidate);

        if (
            $candidateRealPath === false
            || ! is_file($candidateRealPath)
        ) {
            return null;
        }

        $basePrefix = rtrim(
            $baseRealPath,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        $candidateForComparison = $candidateRealPath;
        $baseForComparison = $basePrefix;

        if (DIRECTORY_SEPARATOR === '\\') {
            $candidateForComparison = strtolower(
                $candidateForComparison
            );
            $baseForComparison = strtolower(
                $baseForComparison
            );
        }

        if (
            ! str_starts_with(
                $candidateForComparison,
                $baseForComparison
            )
        ) {
            return null;
        }

        return $candidateRealPath;
    }

    private function join(
        string $basePath,
        string $relativePath
    ): string {
        return rtrim($basePath, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativePath
            );
    }
}