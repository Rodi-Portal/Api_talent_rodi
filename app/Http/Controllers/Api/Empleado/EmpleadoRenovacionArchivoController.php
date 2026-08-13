<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\Auth\EmpleadoAuth;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\ExamEmpleado;
use App\Models\SolicitudRenovacionArchivo;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmpleadoRenovacionArchivoController extends Controller
{

    public function index(Request $request)
    {
        $data = $request->validate([
            'estado' => [
                'nullable',
                'string',
                'in:pendiente,aprobada,rechazada,cancelada',
            ],
        ]);

        $employee = $this->employee($request);

        $query = SolicitudRenovacionArchivo::query()
            ->where(
                'id_portal',
                (int) $employee->id_portal
            )
            ->where(
                'id_cliente',
                (int) $employee->id_cliente
            )
            ->where(
                'id_empleado',
                (int) $employee->id
            );

        if (! empty($data['estado'])) {
            $query->where('estado', $data['estado']);
        }

        $requests = $query
            ->orderByDesc('creacion')
            ->orderByDesc('id')
            ->get([
                'id',
                'tipo',
                'id_origen',
                'nombre_original',
                'estado',
                'comentario_colaborador',
                'comentario_resolucion',
                'fecha_vencimiento_aprobada',
                'creacion',
                'resolucion',
            ])
            ->map(function (
                SolicitudRenovacionArchivo $renewal
            ) {
                return [
                    'id'                         => (int) $renewal->id,
                    'tipo'                       => $renewal->tipo,
                    'id_origen'                  =>
                    (int) $renewal->id_origen,
                    'nombre_original'            =>
                    $renewal->nombre_original,
                    'estado'                     => $renewal->estado,
                    'comentario_colaborador'     =>
                    $renewal->comentario_colaborador,
                    'comentario_resolucion'      =>
                    $renewal->comentario_resolucion,
                    'fecha_vencimiento_aprobada' =>
                    $renewal->fecha_vencimiento_aprobada,
                    'creacion'                   => $renewal->creacion,
                    'resolucion'                 => $renewal->resolucion,
                ];
            })
            ->values();

        return response()->json([
            'solicitudes' => $requests,
        ]);
    }
    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo'       => [
                'required',
                'string',
                'in:documento,curso,examen',
            ],
            'id_origen'  => [
                'required',
                'integer',
                'min:1',
            ],
            'archivo'    => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png',
                'max:15360',
            ],
            'comentario' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ]);

        $employee = $this->employee($request);

        [$modelClass] = $this->originConfiguration(
            $data['tipo']
        );

        $storedPath = null;

        try {
            $renewal = DB::connection('portal_main')->transaction(
                function () use (
                    $request,
                    $data,
                    $employee,
                    $modelClass,
                    &$storedPath
                ) {
                    $origin = $modelClass::query()
                        ->where('id', (int) $data['id_origen'])
                        ->where(
                            'employee_id',
                            (int) $employee->id
                        )
                        ->whereIn('share_scope', [1, 3])
                        ->where(
                            'collaborator_can_replace',
                            1
                        )
                        ->where('status', '!=', 999)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if (empty($origin->expiry_date)) {
                        throw ValidationException::withMessages([
                            'id_origen' => [
                                'El archivo no tiene fecha de vencimiento.',
                            ],
                        ]);
                    }

                    $today = now()->startOfDay();

                    $expiryDate = Carbon::parse(
                        $origin->expiry_date
                    )->startOfDay();

                    $reminderDays = max(
                        0,
                        (int) ($origin->expiry_reminder ?? 0)
                    );

                    $renewalAvailableFrom = $expiryDate
                        ->copy()
                        ->subDays($reminderDays);

                    $isExpired = $expiryDate->lt($today);

                    $isInsideReminderWindow =
                    $reminderDays > 0 &&
                    $today->gte($renewalAvailableFrom);

                    if (! $isExpired && ! $isInsideReminderWindow) {
                        throw ValidationException::withMessages([
                            'id_origen' => [
                                sprintf(
                                    'La renovación estará disponible a partir del %s.',
                                    $renewalAvailableFrom->format('Y-m-d')
                                ),
                            ],
                        ]);
                    }

                    $pendingExists =
                    SolicitudRenovacionArchivo::query()
                        ->where(
                            'tipo',
                            $data['tipo']
                        )
                        ->where(
                            'id_origen',
                            (int) $origin->id
                        )
                        ->where(
                            'estado',
                            SolicitudRenovacionArchivo::ESTADO_PENDIENTE
                        )
                        ->exists();

                    if ($pendingExists) {
                        throw ValidationException::withMessages([
                            'id_origen' => [
                                'Este archivo ya tiene una renovación pendiente.',
                            ],
                        ]);
                    }

                    $uploadedFile = $request->file('archivo');

                    $renewal =
                    SolicitudRenovacionArchivo::create([
                        'id_portal'              =>
                        (int) $employee->id_portal,
                        'id_cliente'             =>
                        (int) $employee->id_cliente,
                        'id_empleado'            =>
                        (int) $employee->id,
                        'tipo'                   => $data['tipo'],
                        'id_origen'              =>
                        (int) $origin->id,
                        'archivo_actual'         =>
                        basename(
                            (string) $origin->name
                        ),
                        'edicion_origen'         =>
                        $origin->getRawOriginal(
                            'edicion'
                        ),
                        'archivo_propuesto'      =>
                        'pendiente',
                        'nombre_original'        =>
                        basename(
                            $uploadedFile
                                ->getClientOriginalName()
                        ),
                        'mime_type'              =>
                        $uploadedFile->getMimeType()
                            ?: 'application/octet-stream',
                        'size_bytes'             =>
                        (int) $uploadedFile->getSize(),
                        'storage_path'           => 'pendiente',
                        'estado'                 =>
                        SolicitudRenovacionArchivo::ESTADO_PENDIENTE,
                        'comentario_colaborador' =>
                        isset($data['comentario'])
                            ? trim($data['comentario'])
                            : null,
                    ]);

                    $extension = strtolower(
                        $uploadedFile
                            ->getClientOriginalExtension()
                    );

                    $fileName =
                    'solicitud_'
                    . $renewal->id
                    . '_'
                    . $data['tipo']
                    . '_'
                    . $origin->id
                    . '_'
                    . Str::lower(Str::random(10))
                        . '.'
                        . $extension;

                    $relativeDirectory = implode('/', [
                        '_renovaciones',
                        (int) $employee->id_portal,
                        (int) $employee->id_cliente,
                        (int) $employee->id,
                    ]);

                    $relativePath =
                        $relativeDirectory
                        . '/'
                        . $fileName;

                    $basePath = rtrim(
                        (string) config('paths.images_path'),
                        '/\\'
                    );

                    if ($basePath === '') {
                        abort(
                            500,
                            'La ruta de archivos no está configurada.'
                        );
                    }

                    $destinationDirectory =
                    $basePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $relativeDirectory
                    );

                    if (
                        ! is_dir($destinationDirectory) &&
                        ! mkdir(
                            $destinationDirectory,
                            0755,
                            true
                        ) &&
                        ! is_dir($destinationDirectory)
                    ) {
                        abort(
                            500,
                            'No se pudo crear el directorio de renovaciones.'
                        );
                    }

                    $uploadedFile->move(
                        $destinationDirectory,
                        $fileName
                    );

                    $storedPath =
                    $basePath
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        $relativePath
                    );

                    $renewal->update([
                        'archivo_propuesto' => $fileName,
                        'storage_path'      => $relativePath,
                    ]);

                    return $renewal->fresh();
                }
            );
        } catch (QueryException $exception) {
            if (
                is_string($storedPath) &&
                is_file($storedPath)
            ) {
                @unlink($storedPath);
            }

            if (
                (int) $exception->errorInfo[1] === 1062
            ) {
                throw ValidationException::withMessages([
                    'id_origen' => [
                        'Este archivo ya tiene una renovación pendiente.',
                    ],
                ]);
            }

            throw $exception;
        } catch (\Throwable $exception) {
            if (
                is_string($storedPath) &&
                is_file($storedPath)
            ) {
                @unlink($storedPath);
            }

            throw $exception;
        }

        return response()->json([
            'message'   =>
            'Solicitud de renovación enviada correctamente.',
            'solicitud' => [
                'id'              => (int) $renewal->id,
                'tipo'            => $renewal->tipo,
                'id_origen'       =>
                (int) $renewal->id_origen,
                'estado'          => $renewal->estado,
                'nombre_original' =>
                $renewal->nombre_original,
                'creacion'        => $renewal->creacion,
            ],
        ], 201);
    }

    private function originConfiguration(
        string $type
    ): array {
        return match ($type) {
            SolicitudRenovacionArchivo::TIPO_DOCUMENTO => [
                DocumentEmpleado::class,
            ],
            SolicitudRenovacionArchivo::TIPO_CURSO     => [
                CursoEmpleado::class,
            ],
            SolicitudRenovacionArchivo::TIPO_EXAMEN    => [
                ExamEmpleado::class,
            ],
            default                                    => throw ValidationException::withMessages([
                'tipo' => ['Tipo de archivo no válido.'],
            ]),
        };
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
