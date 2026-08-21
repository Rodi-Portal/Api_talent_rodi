<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Auth\EmpleadoAuth;
use App\Models\DocumentoInterno;
use App\Services\Auditoria\AuditoriaService;
use App\Services\Documents\InternalDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class EmpleadoDocumentoCorporativoController extends Controller
{
    public function __construct(
        private InternalDocumentPathService $documentPaths,
        private AuditoriaService $auditoria
    ) {}
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

        try {
            $filePath = $this->documentPaths
                ->existingAbsolutePath(
                    (string) $document->storage_path
                );
        } catch (InvalidArgumentException | RuntimeException $exception) {
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
        $this->auditoria->registrar([
            'id_portal'    => (int) $employee->id_portal,
            'id_cliente'   => (int) $employee->id_cliente,
            'actor_tipo'   => 'empleado',
            'actor_id'     => (int) $employee->id,
            'actor_nombre' => $this->employeeName($employee),

            'modulo'       => 'informacion_interna',
            'entidad_tipo' => 'documento_interno',
            'entidad_id'   => (int) $document->id,
            'accion'       => 'documento_visualizado',
            'resultado'    => 'exitoso',
            'descripcion'  => 'El colaborador visualizó un documento interno.',

            'metadatos'    => [
                'storage_path'   => $document->storage_path,
                'storage_origen' => $this->documentPaths->storageOrigin(
                    (string) $document->storage_path
                ),
                'modo'           => 'visualizacion',
                'mime_type'      => $mimeType,
                'size_bytes'     => (int) $document->size,
            ],
        ], $request);
        return response()->file($filePath, [
            'Content-Type'        => $mimeType,
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
    private function employeeName(
        EmpleadoAuth $employee
    ): ?string {
        $name = trim(implode(' ', array_filter([
            $employee->nombre ?? null,
            $employee->paterno ?? null,
            $employee->materno ?? null,
        ])));

        return $name !== '' ? $name : null;
    }
}
