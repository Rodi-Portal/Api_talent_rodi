<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use App\Models\SolicitudRenovacionArchivo;
use App\Services\Auth\AdminClientScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class EmpleadoCompartidoController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope
    ) {}

    public function summary(Request $request)
    {
        $data = $request->validate([
            'id_cliente' => ['required', 'integer', 'min:1'],
            'status'     => ['nullable', 'integer'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $employeeQuery = Empleado::query()
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where('id_cliente', (int) $clientIds[0]);

        if (array_key_exists('status', $data)) {
            $employeeQuery->where('status', (int) $data['status']);
        }

        $employeeIds = $employeeQuery
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values();

        $summary = [];

        foreach ($employeeIds as $employeeId) {
            $summary[$employeeId] = [
                'compartidos'             => 0,
                'documentos'              => 0,
                'cursos'                  => 0,
                'examenes'                => 0,
                'aprobaciones_pendientes' => 0,
            ];
        }

        if ($employeeIds->isEmpty()) {
            return response()->json([
                'empleados' => $summary,
            ]);
        }

        $this->mergeCounts(
            $summary,
            $this->sharedCounts(
                DocumentEmpleado::class,
                $employeeIds
            ),
            'documentos'
        );

        $this->mergeCounts(
            $summary,
            $this->sharedCounts(
                CursoEmpleado::class,
                $employeeIds
            ),
            'cursos'
        );

        $this->mergeCounts(
            $summary,
            $this->sharedCounts(
                ExamEmpleado::class,
                $employeeIds
            ),
            'examenes'
        );
        $pendingApprovals = SolicitudRenovacionArchivo::query()
            ->selectRaw('id_empleado, COUNT(*) AS total')
            ->where(
                'id_portal',
                (int) $administrator->id_portal
            )
            ->where(
                'id_cliente',
                (int) $clientIds[0]
            )
            ->where(
                'estado',
                SolicitudRenovacionArchivo::ESTADO_PENDIENTE
            )
            ->whereIn('id_empleado', $employeeIds)
            ->groupBy('id_empleado')
            ->pluck('total', 'id_empleado');

        foreach ($pendingApprovals as $employeeId => $total) {
            $employeeId = (int) $employeeId;

            if (! array_key_exists($employeeId, $summary)) {
                continue;
            }

            $summary[$employeeId]['aprobaciones_pendientes'] =
            (int) $total;
        }

        return response()->json([
            'empleados' => $summary,
        ]);
    }
    public function index(Request $request, int $empleado)
    {
        $data = $request->validate([
            'id_cliente' => ['required', 'integer', 'min:1'],
        ]);

        $administrator = $this->administrator($request);

        $clientIds = $this->clientScope->authorizeRequestedClients(
            $administrator,
            [(int) $data['id_cliente']]
        );

        $employee = Empleado::query()
            ->where('id', $empleado)
            ->where('id_portal', (int) $administrator->id_portal)
            ->where('id_cliente', (int) $clientIds[0])
            ->firstOrFail();

        $documents = DocumentEmpleado::query()
            ->with('documentOption:id,name')
            ->where('employee_id', (int) $employee->id)
            ->whereIn('share_scope', [2, 3])
            ->where('status', '!=', 999)
            ->orderByDesc('edicion')
            ->get();

        $courses = CursoEmpleado::query()
            ->with('documentOption:id,name')
            ->where('employee_id', (int) $employee->id)
            ->whereIn('share_scope', [2, 3])
            ->where('status', '!=', 999)
            ->orderByDesc('edicion')
            ->get();

        $exams = ExamEmpleado::query()
            ->with('examOption:id,name')
            ->where('employee_id', (int) $employee->id)
            ->whereIn('share_scope', [2, 3])
            ->where('status', '!=', 999)
            ->orderByDesc('edicion')
            ->get();

        return response()->json([
            'empleado'   => [
                'id'     => (int) $employee->id,
                'nombre' => trim(implode(' ', array_filter([
                    $employee->nombre,
                    $employee->paterno,
                    $employee->materno,
                ]))),
            ],
            'documentos' => $this->normalizeItems(
                $documents,
                'documento',
                'documentOption'
            ),
            'cursos'     => $this->normalizeItems(
                $courses,
                'curso',
                'documentOption'
            ),
            'examenes'   => $this->normalizeItems(
                $exams,
                'examen',
                'examOption'
            ),
        ]);
    }

    private function normalizeItems(
        $items,
        string $type,
        string $optionRelation
    ): array {
        return $items->map(function ($item) use (
            $type,
            $optionRelation
        ) {
            $option = $item->{$optionRelation};

            return [
                'id'                => (int) $item->id,
                'tipo'              => $type,
                'nombre'            => $option?->name ?? $item->nameDocument ?? $item->name,
                'descripcion'       => $item->description,
                'fecha_vencimiento' => $item->expiry_date,
                'estado'            => (int) $item->status,
                'share_scope'       => (int) $item->share_scope,
                'edicion'           => $item->edicion,
            ];
        })->values()->all();
    }
    public function file(
        Request $request,
        string $type,
        int $id
    ) {
        $administrator = $this->administrator($request);

        $types = [
            'documento' => [
                'model'  => DocumentEmpleado::class,
                'folder' => '_documentEmpleado',
            ],
            'curso'     => [
                'model'  => CursoEmpleado::class,
                'folder' => '_cursos',
            ],
            'examen'    => [
                'model'  => ExamEmpleado::class,
                'folder' => '_examEmpleado',
            ],
        ];

        if (! array_key_exists($type, $types)) {
            abort(404);
        }

        $configuration = $types[$type];

        $item = $configuration['model']::query()
            ->where('id', $id)
            ->whereIn('share_scope', [2, 3])
            ->where('status', '!=', 999)
            ->firstOrFail();

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

        $fileName = basename((string) $item->name);

        if ($fileName === '') {
            abort(404);
        }

        $basePath = rtrim(
            (string) config('paths.images_path'),
            DIRECTORY_SEPARATOR
        );

        $filePath = $basePath
            . DIRECTORY_SEPARATOR
            . $configuration['folder']
            . DIRECTORY_SEPARATOR
            . $fileName;

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

    private function sharedCounts(
        string $modelClass,
        $employeeIds
    ) {
        return $modelClass::query()
            ->selectRaw('employee_id, COUNT(*) AS total')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('share_scope', [2, 3])
            ->where('status', '!=', 999)
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');
    }

    private function mergeCounts(
        array &$summary,
        $counts,
        string $type
    ): void {
        foreach ($counts as $employeeId => $total) {
            $employeeId = (int) $employeeId;
            $total      = (int) $total;

            if (! array_key_exists($employeeId, $summary)) {
                continue;
            }

            $summary[$employeeId][$type]          = $total;
            $summary[$employeeId]['compartidos'] += $total;
        }
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
