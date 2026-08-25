<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Documents\EmployeeDocumentPathService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class EmpleadoArchivoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private EmployeeDocumentPathService $documentPaths
    ) {}

    public function document(Request $request, int $id)
    {
        return $this->serveFile(
            $request,
            DocumentEmpleado::class,
            $id,
            '_documentEmpleado',
            'expediente'
        );
    }

    public function formerDocument(
        Request $request,
        int $id
    ) {
        return $this->serveFile(
            $request,
            DocumentEmpleado::class,
            $id,
            '_documentEmpleado',
            'salida'
        );
    }

    public function course(Request $request, int $id)
    {
        return $this->serveFile(
            $request,
            CursoEmpleado::class,
            $id,
            '_cursos'
        );
    }

    public function exam(Request $request, int $id)
    {
        return $this->serveFile(
            $request,
            ExamEmpleado::class,
            $id,
            '_examEmpleado'
        );
    }

    private function serveFile(
        Request $request,
        string $modelClass,
        int $id,
        string $folder,
        ?string $documentContext = null
    ) {
        $administrator = $this->administrator($request);

        $itemQuery = $modelClass::query()
            ->where('id', $id)
            ->where('status', '!=', 999);

        if ($documentContext !== null) {
            $itemQuery->where(
                'document_context',
                $documentContext
            );
        }

        $item = $itemQuery->firstOrFail();

        $employee = Empleado::query()
            ->where('id', (int) $item->employee_id)
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->firstOrFail();

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $employee->id_cliente]
        );

        $storedValue = trim((string) $item->name);

        if (
            $storedValue === ''
            || $this->documentPaths->isExternalUrl($storedValue)
        ) {
            abort(404, 'Archivo local no disponible.');
        }

        try {
            $filePath = $this->documentPaths->absolutePath(
                $folder,
                $storedValue
            );
        } catch (
            InvalidArgumentException | RuntimeException $exception
        ) {
            abort(404, $exception->getMessage());
        }

        $fileName = basename(
            str_replace('\\', '/', $storedValue)
        );

        if (! is_file($filePath)) {
            abort(404, 'Archivo no encontrado.');
        }

        $mimeType = mime_content_type($filePath)
            ?: 'application/octet-stream';

        return response()->file($filePath, [
            'Content-Type'        => $mimeType,
            'Content-Disposition' =>
            'inline; filename="' . $fileName . '"',
        ]);
    }

    private function administrator(
        Request $request
    ): AdministradorAuth {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no válido.'
            );
        }

        return $administrator;
    }
}
