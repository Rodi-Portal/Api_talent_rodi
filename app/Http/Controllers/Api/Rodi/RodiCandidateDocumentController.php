<?php

namespace App\Http\Controllers\Api\Rodi;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CandidatoDocumento;
use App\Models\CandidatoSync;
use App\Services\Documents\RodiCandidateDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RodiCandidateDocumentController extends Controller
{
    public function show(
        Request $request,
        int $documentId,
        RodiCandidateDocumentPathService $paths
    ) {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        $idPortal = (int) $administrator->id_portal;

        if ($idPortal <= 0) {
            abort(403, 'Portal administrativo no válido.');
        }

        $document = $this->getRodiDocumentMetadata(
            $documentId
        );

        if ($document === null) {
            abort(404, 'Documento no encontrado.');
        }

        if (
            (int) $document['id_documento'] !== $documentId
            || (int) $document['id_portal'] !== $idPortal
        ) {
            abort(404, 'Documento no encontrado.');
        }

        $idCandidatoRodi = (int) $document['id_candidato_rodi'];
        $fileName = basename((string) $document['archivo']);

        if ($idCandidatoRodi <= 0 || $fileName === '') {
            abort(404, 'Documento no encontrado.');
        }

        $filePath = $paths->resolveExistingPath(
            $idPortal,
            $idCandidatoRodi,
            $fileName
        );

        if ($filePath === null) {
            abort(404, 'Archivo no encontrado.');
        }

        $mime = mime_content_type($filePath)
            ?: 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline; filename="'
                . $fileName
                . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function showForClient(
        Request $request,
        int $documentId,
        RodiCandidateDocumentPathService $paths
    ) {
        $configuredKey = (string) config(
            'integrations.portal.document_key'
        );

        $providedKey = (string) $request->header(
            'X-Portal-Integration-Key'
        );

        if (
            $configuredKey === ''
            || $providedKey === ''
            || ! hash_equals($configuredKey, $providedKey)
        ) {
            abort(401);
        }

        $idPortal = (int) $request->header(
            'X-Talent-Portal-Id'
        );

        $idClient = (int) $request->header(
            'X-Talent-Client-Id'
        );

        if (
            $documentId <= 0
            || $idPortal <= 0
            || $idClient <= 0
        ) {
            abort(404);
        }

        $document = $this->getRodiDocumentMetadata(
            $documentId
        );

        if ($document === null) {
            abort(404);
        }

        if (
            (int) $document['id_documento'] !== $documentId
            || (int) $document['id_portal'] !== $idPortal
            || (int) $document['id_cliente_talent'] !== $idClient
        ) {
            abort(404);
        }

        $candidateId = (int) $document['id_candidato_rodi'];
        $fileName = basename((string) $document['archivo']);

        if ($candidateId <= 0 || $fileName === '') {
            abort(404);
        }

        $filePath = $paths->resolveExistingPath(
            $idPortal,
            $candidateId,
            $fileName
        );

        if ($filePath === null) {
            abort(404);
        }

        $mime = mime_content_type($filePath)
            ?: 'application/octet-stream';

        return response()->file(
            $filePath,
            [
                'Content-Type' => $mime,
                'Content-Disposition' =>
                    'inline; filename="' .
                    addcslashes($fileName, '"\\') .
                    '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store, max-age=0',
            ]
        );
    }

    private function getRodiDocumentMetadata(
        int $documentId
    ): ?array {
        $baseUrl = rtrim(
            (string) config('services.rodi_integration.base_url'),
            '/'
        );

        $integrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if ($baseUrl === '' || $integrationKey === '') {
            Log::error(
                'Configuración incompleta para consultar documentos RODI.',
                [
                    'document_id' => $documentId,
                ]
            );

            abort(503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' => $integrationKey,
                ])
                ->timeout(20)
                ->get(
                    $baseUrl .
                    '/documentos/' .
                    $documentId
                );
        } catch (\Throwable $e) {
            Log::error(
                'Error consultando metadata documental en RODI.',
                [
                    'document_id' => $documentId,
                    'error' => $e->getMessage(),
                ]
            );

            abort(502);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning(
                'RODI rechazó consulta de metadata documental.',
                [
                    'document_id' => $documentId,
                    'http_status' => $response->status(),
                ]
            );

            abort(502);
        }

        $data = $response->json('data');

        if (
            ! is_array($data)
            || ! isset(
                $data['id_documento'],
                $data['id_candidato_rodi'],
                $data['archivo'],
                $data['id_portal'],
                $data['id_cliente_talent']
            )
        ) {
            Log::error(
                'Respuesta inválida al consultar metadata documental en RODI.',
                [
                    'document_id' => $documentId,
                ]
            );

            abort(502);
        }

        return $data;
    }

    private function getRodiCandidateDocumentsMetadata(
        int $candidateId
    ): ?array {
        $baseUrl = rtrim(
            (string) config('services.rodi_integration.base_url'),
            '/'
        );

        $integrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if ($baseUrl === '' || $integrationKey === '') {
            Log::error(
                'Configuración incompleta para consultar documentos del candidato en RODI.',
                [
                    'candidate_id' => $candidateId,
                ]
            );

            abort(503);
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' => $integrationKey,
                ])
                ->timeout(20)
                ->get(
                    $baseUrl .
                    '/candidatos/' .
                    $candidateId .
                    '/documentos'
                );
        } catch (\Throwable $e) {
            Log::error(
                'Error consultando documentos del candidato en RODI.',
                [
                    'candidate_id' => $candidateId,
                    'error' => $e->getMessage(),
                ]
            );

            abort(502);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            Log::warning(
                'RODI rechazó consulta de documentos del candidato.',
                [
                    'candidate_id' => $candidateId,
                    'http_status' => $response->status(),
                ]
            );

            abort(502);
        }

        $data = $response->json('data');

        if (
            ! is_array($data)
            || ! isset(
                $data['id_candidato_rodi'],
                $data['id_portal'],
                $data['id_cliente_talent'],
                $data['documentos']
            )
            || ! is_array($data['documentos'])
        ) {
            Log::error(
                'Respuesta inválida al consultar documentos del candidato en RODI.',
                [
                    'candidate_id' => $candidateId,
                ]
            );

            abort(502);
        }

        return $data;
    }
    public function downloadZipForClient(
        Request $request,
        int $candidateId,
        RodiCandidateDocumentPathService $paths
    ) {
        $configuredKey = (string) config(
            'integrations.portal.document_key'
        );

        $providedKey = (string) $request->header(
            'X-Portal-Integration-Key'
        );

        if (
            $configuredKey === ''
            || $providedKey === ''
            || ! hash_equals($configuredKey, $providedKey)
        ) {
            abort(401);
        }

        $idPortal = (int) $request->header(
            'X-Talent-Portal-Id'
        );

        $idClient = (int) $request->header(
            'X-Talent-Client-Id'
        );

        if (
            $candidateId <= 0
            || $idPortal <= 0
            || $idClient <= 0
        ) {
            abort(404);
        }

        $candidate = $this->getRodiCandidateDocumentsMetadata(
            $candidateId
        );

        if ($candidate === null) {
            abort(404);
        }

        if (
            (int) $candidate['id_candidato_rodi'] !== $candidateId
            || (int) $candidate['id_portal'] !== $idPortal
            || (int) $candidate['id_cliente_talent'] !== $idClient
        ) {
            abort(404);
        }

        $documents = $candidate['documentos'];

        if (empty($documents)) {
            abort(404, 'No hay documentos disponibles.');
        }

        $tmpDir = storage_path('app/tmp');

        if (
            ! is_dir($tmpDir)
            && ! mkdir($tmpDir, 0775, true)
            && ! is_dir($tmpDir)
        ) {
            abort(500, 'No fue posible preparar el archivo ZIP.');
        }

        $zipPath = $tmpDir
            . DIRECTORY_SEPARATOR
            . 'documentos_candidato_'
            . $candidateId
            . '_'
            . bin2hex(random_bytes(8))
            . '.zip';

        $zip = new \ZipArchive();

        if (
            $zip->open(
                $zipPath,
                \ZipArchive::CREATE | \ZipArchive::OVERWRITE
            ) !== true
        ) {
            abort(500, 'No fue posible crear el archivo ZIP.');
        }

        $added = 0;

        try {
            foreach ($documents as $document) {
                $fileName = basename(
                    (string) ($document['archivo'] ?? '')
                );

                if ($fileName === '') {
                    continue;
                }

                $filePath = $paths->resolveExistingPath(
                    $idPortal,
                    $candidateId,
                    $fileName
                );

                if ($filePath === null) {
                    continue;
                }

                if ($zip->addFile($filePath, $fileName)) {
                    $added++;
                }
            }
        } finally {
            $zip->close();
        }

        if ($added === 0) {
            @unlink($zipPath);

            abort(
                404,
                'No se encontraron archivos disponibles.'
            );
        }

        return response()
            ->download(
                $zipPath,
                'documentos_candidato_' . $candidateId . '.zip',
                [
                    'Content-Type' => 'application/zip',
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    public function downloadZip(
        Request $request,
        int $candidateId,
        RodiCandidateDocumentPathService $paths
    ) {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        $idPortal = (int) $administrator->id_portal;

        if ($idPortal <= 0 || $candidateId <= 0) {
            abort(404, 'Candidato no encontrado.');
        }

        $authorized = CandidatoSync::query()
            ->where('id_candidato_rodi', $candidateId)
            ->where('id_portal', $idPortal)
            ->exists();

        if (! $authorized) {
            abort(404, 'Candidato no encontrado.');
        }

        $documents = CandidatoDocumento::query()
            ->where('id_candidato', $candidateId)
            ->where('eliminado', 0)
            ->orderBy('id_tipo_documento')
            ->orderBy('id')
            ->get();

        if ($documents->isEmpty()) {
            abort(404, 'No hay documentos disponibles.');
        }

        $tmpDir = storage_path('app/tmp');

        if (! is_dir($tmpDir) && ! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            abort(500, 'No fue posible preparar el archivo ZIP.');
        }

        $zipPath = $tmpDir
            . DIRECTORY_SEPARATOR
            . 'documentos_candidato_'
            . $candidateId
            . '_'
            . bin2hex(random_bytes(8))
            . '.zip';

        $zip = new \ZipArchive();

        if (
            $zip->open(
                $zipPath,
                \ZipArchive::CREATE | \ZipArchive::OVERWRITE
            ) !== true
        ) {
            abort(500, 'No fue posible crear el archivo ZIP.');
        }

        $added = 0;

        try {
            foreach ($documents as $document) {
                $fileName = basename((string) $document->archivo);

                $filePath = $paths->resolveExistingPath(
                    $idPortal,
                    $candidateId,
                    $fileName
                );

                if ($filePath === null) {
                    continue;
                }

                if ($zip->addFile($filePath, $fileName)) {
                    $added++;
                }
            }
        } finally {
            $zip->close();
        }

        if ($added === 0) {
            @unlink($zipPath);
            abort(404, 'No se encontraron archivos disponibles.');
        }

        return response()
            ->download(
                $zipPath,
                'documentos_candidato_' . $candidateId . '.zip',
                [
                    'Content-Type'              => 'application/zip',
                    'X-Content-Type-Options'    => 'nosniff',
                    'Cache-Control'             => 'private, no-store, no-cache, must-revalidate',
                    'Pragma'                    => 'no-cache',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    public function store(
        Request $request,
        RodiCandidateDocumentPathService $paths
    ): JsonResponse {
        $traceId = (string) Str::ulid();

        $configuredKey = (string) config(
            'integrations.rodi.document_key',
            ''
        );

        $receivedKey = (string) $request->header(
            'X-RODI-Integration-Key',
            ''
        );

        if (
            $configuredKey === '' ||
            $receivedKey === '' ||
            ! hash_equals($configuredKey, $receivedKey)
        ) {
            Log::warning('Integración RODI documental rechazada', [
                'trace_id' => $traceId,
                'ip'       => $request->ip(),
            ]);

            return response()->json([
                'status'   => false,
                'trace_id' => $traceId,
                'message'  => 'Credenciales de integración no válidas.',
            ], 401);
        }

        $data = $request->validate([
            'file'               => 'required|file|mimes:pdf,jpg,jpeg,png|max:15360',
            'file_name'          => 'required|string|max:255',
            'id_candidato_rodi'  => 'required|integer|min:1',
            'id_portal'          => 'required|integer|min:1',
        ]);

        $idCandidatoRodi = (int) $data['id_candidato_rodi'];
        $idPortal        = (int) $data['id_portal'];
        $fileName        = basename((string) $data['file_name']);

        $candidate = $this->getRodiCandidateDocumentsMetadata(
            $idCandidatoRodi
        );

        $syncValid = $candidate !== null
            && (int) $candidate['id_candidato_rodi'] === $idCandidatoRodi
            && (int) $candidate['id_portal'] === $idPortal;

        if (! $syncValid) {
            Log::warning('Integración RODI con candidato/portal no válido', [
                'trace_id'          => $traceId,
                'id_candidato_rodi' => $idCandidatoRodi,
                'id_portal'         => $idPortal,
                'ip'                => $request->ip(),
            ]);

            return response()->json([
                'status'   => false,
                'trace_id' => $traceId,
                'message'  => 'La relación candidato/portal no es válida.',
            ], 422);
        }

        $destinationDirectory = $paths->absoluteDirectory(
            $idPortal,
            $idCandidatoRodi
        );

        $destinationFile = $paths->absolutePath(
            $idPortal,
            $idCandidatoRodi,
            $fileName
        );

        if (! is_dir($destinationDirectory)) {
            if (
                ! mkdir($destinationDirectory, 0755, true) &&
                ! is_dir($destinationDirectory)
            ) {
                Log::error('No fue posible crear directorio documental RODI', [
                    'trace_id' => $traceId,
                    'dir'      => $destinationDirectory,
                ]);

                return response()->json([
                    'status'   => false,
                    'trace_id' => $traceId,
                    'message'  => 'No fue posible preparar el almacenamiento documental.',
                ], 500);
            }
        }

        if (is_file($destinationFile)) {
            return response()->json([
                'status'   => false,
                'trace_id' => $traceId,
                'message'  => 'Ya existe un archivo con ese nombre.',
            ], 409);
        }

        try {
            $request->file('file')->move(
                $destinationDirectory,
                $fileName
            );

            @chmod($destinationFile, 0664);

            $relativePath = $paths->relativePath(
                $idPortal,
                $idCandidatoRodi,
                $fileName
            );

            Log::info('Documento RODI almacenado en TalentSafe', [
                'trace_id'          => $traceId,
                'id_candidato_rodi' => $idCandidatoRodi,
                'id_portal'         => $idPortal,
                'path'              => $relativePath,
            ]);

            return response()->json([
                'status'        => true,
                'trace_id'      => $traceId,
                'message'       => 'Documento guardado correctamente.',
                'relative_path' => $relativePath,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error almacenando documento RODI', [
                'trace_id'  => $traceId,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'status'   => false,
                'trace_id' => $traceId,
                'message'  => 'No fue posible guardar el documento.',
            ], 500);
        }
    }
}
