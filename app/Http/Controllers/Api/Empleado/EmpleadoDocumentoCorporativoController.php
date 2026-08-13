<?php

namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Auth\EmpleadoAuth;
use App\Models\DocumentoInterno;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class EmpleadoDocumentoCorporativoController extends Controller
{
    public function index(Request $request)
    {
        $employee = $this->employee($request);

        $documents = $this->availableDocuments($employee)
            ->with([
                'informacionInterna:id,id_portal,id_cliente,nombre,descripcion',
            ])
            ->orderByDesc('creacion')
            ->get()
            ->map(function (DocumentoInterno $document) {
                return [
                    'id'              => (int) $document->id,
                    'name'            => $document->nombre,
                    'document'        => $document->nombre,
                    'description'     =>
                        $document->informacionInterna?->descripcion,
                    'directory'       =>
                        $document->informacionInterna?->nombre,
                    'mime_type'       => $document->typo,
                    'size_bytes'      => (int) $document->size,
                    'expiry_date'     =>
                        $document->fecha_vencimiento?->format('Y-m-d'),
                    'expiry_reminder' =>
                        (int) ($document->dias_antes ?? 0),
                    'status'          => 1,
                    'share_scope'     => (int) $document->share_scope,
                    'corporate'       => true,
                    'file_url'        => url(
                        "/api/empleado/documentos-corporativos/{$document->id}/ver"
                    ),
                ];
            })
            ->values();

        return response()->json([
            'documentos' => $documents,
        ]);
    }

    public function file(
        Request $request,
        int $documento
    ) {
        $employee = $this->employee($request);

        $document = $this->availableDocuments($employee)
            ->where('id', $documento)
            ->firstOrFail();

        $basePath = realpath(
            (string) config('paths.images_path')
        );

        if ($basePath === false) {
            abort(
                500,
                'La ruta de archivos no está disponible.'
            );
        }

        $relativePath = ltrim(
            str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                (string) $document->storage_path
            ),
            DIRECTORY_SEPARATOR
        );

        $filePath = realpath(
            $basePath
            . DIRECTORY_SEPARATOR
            . $relativePath
        );

        $basePrefix = rtrim(
            $basePath,
            DIRECTORY_SEPARATOR
        ) . DIRECTORY_SEPARATOR;

        if (
            $filePath === false ||
            ! str_starts_with($filePath, $basePrefix) ||
            ! is_file($filePath)
        ) {
            abort(404, 'Archivo no encontrado.');
        }

        $mimeType = mime_content_type($filePath)
            ?: (
                $document->typo
                ?: 'application/octet-stream'
            );

        $displayName = basename(
            (string) $document->nombre
        );

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' =>
                'inline; filename="' . $displayName . '"',
        ]);
    }

    private function availableDocuments(
        EmpleadoAuth $employee
    ) {
        return DocumentoInterno::query()
            ->whereIn('share_scope', [1, 3])
            ->whereHas(
                'informacionInterna',
                function ($query) use ($employee) {
                    $query
                        ->where(
                            'id_portal',
                            (int) $employee->id_portal
                        )
                        ->where(
                            'id_cliente',
                            (int) $employee->id_cliente
                        )
                        ->where('eliminado', 0);
                }
            )
            ->whereHas(
                'asignacionesEmpleados',
                function ($query) use ($employee) {
                    $query->where(
                        'id_empleado',
                        (int) $employee->id
                    );
                }
            );
    }

    private function employee(
        Request $request
    ): EmpleadoAuth {
        $employee = $request->user();

        if (! $employee instanceof EmpleadoAuth) {
            throw new AuthorizationException(
                'Token de colaborador no válido.'
            );
        }

        return $employee;
    }
}