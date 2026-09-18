<?php

namespace App\Services\Documents;

class RodiCandidateDocumentPathService
{
    public function relativeDirectory(
        int $portalId,
        int $rodiCandidateId
    ): string {
        return sprintf(
            'portales/%d/_docs/candidatos/%d',
            $portalId,
            $rodiCandidateId
        );
    }

    public function relativePath(
        int $portalId,
        int $rodiCandidateId,
        string $fileName
    ): string {
        return $this->relativeDirectory(
            $portalId,
            $rodiCandidateId
        ) . '/' . basename($fileName);
    }

    public function absoluteDirectory(
        int $portalId,
        int $rodiCandidateId
    ): string {
        $basePath = rtrim(
            (string) config('paths.documents_path'),
            "/\\"
        );

        if ($basePath === '') {
            throw new \RuntimeException(
                'La ruta documental nueva no está configurada.'
            );
        }

        $relative = str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $this->relativeDirectory(
                $portalId,
                $rodiCandidateId
            )
        );

        return $basePath
            . DIRECTORY_SEPARATOR
            . $relative;
    }

    public function absolutePath(
        int $portalId,
        int $rodiCandidateId,
        string $fileName
    ): string {
        return $this->absoluteDirectory(
            $portalId,
            $rodiCandidateId
        )
            . DIRECTORY_SEPARATOR
            . basename($fileName);
    }

    public function resolveExistingPath(
        int $portalId,
        int $rodiCandidateId,
        string $fileName
    ): ?string {
        $fileName = basename($fileName);

        if (
            $portalId <= 0 ||
            $rodiCandidateId <= 0 ||
            $fileName === ''
        ) {
            return null;
        }

        /*
         * 1. Nueva estructura.
         */
        $newPath = $this->absolutePath(
            $portalId,
            $rodiCandidateId,
            $fileName
        );

        if (is_file($newPath) && is_readable($newPath)) {
            return $newPath;
        }

        /*
         * 2. Fallback legacy _docs.
         */
        $legacyBase = rtrim(
            (string) config('paths.images_path'),
            "/\\"
        );

        if ($legacyBase === '') {
            return null;
        }

        $legacyPath = $legacyBase
            . DIRECTORY_SEPARATOR
            . '_docs'
            . DIRECTORY_SEPARATOR
            . $fileName;

        if (is_file($legacyPath) && is_readable($legacyPath)) {
            return $legacyPath;
        }

        return null;
    }
}
