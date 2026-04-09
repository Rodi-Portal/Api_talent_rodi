<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class AccesosController extends Controller
{
    public function index(Request $request)
    {
        $idPortal   = (int) $request->query('id_portal');
        $idUsuario  = (int) $request->query('id_usuario');
        $sucursales = $request->query('sucursales', []);

        if (! is_array($sucursales)) {
            $sucursales = [$sucursales];
        }

        $sucursales = collect($sucursales)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->values()
            ->all();

        $query = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.puesto',
                'e.departamento',
                'e.id_cliente',
                'e.status',
                'e.password',
                'e.force_password_change',
                'e.last_login_at',
                'e.password_changed_at'
            ])
            ->where('e.id_portal', $idPortal)
            ->where('e.status', 1)
            ->where('e.eliminado', 0);

        if (! empty($sucursales)) {
            $query->whereIn('e.id_cliente', $sucursales);
        }

        $empleados = $query
            ->orderBy('e.nombre')
            ->orderBy('e.paterno')
            ->orderBy('e.materno')
            ->get();

        $data = $empleados->map(function ($item) {
            $nombreCompleto = trim(collect([
                $item->nombre,
                $item->paterno,
                $item->materno,
            ])->filter()->implode(' '));

            $tieneAcceso = ! empty($item->password) && (int) $item->status === 1;

            return [
                'id'                        => (int) $item->id,
                'id_empleado'               => $item->id_empleado,
                'nombre'                    => $item->nombre,
                'paterno'                   => $item->paterno,
                'materno'                   => $item->materno,
                'nombre_completo'           => $nombreCompleto,
                'correo'                    => $item->correo,
                'puesto'                    => $item->puesto,
                'departamento'              => $item->departamento,
                'id_cliente'                => (int) $item->id_cliente,
                'nombre_sucursal'           => 'Sucursal ' . (int) $item->id_cliente,
                'status'                    => (int) $item->status,
                'tiene_acceso'              => $tieneAcceso,
                'force_password_change'     => (int) ($item->force_password_change ?? 0),
                'last_login_at'             => $item->last_login_at,
                'ultimo_envio_credenciales' => $item->password_changed_at,
            ];
        })->values();

        return response()->json([
            'ok'      => true,
            'message' => 'Accesos obtenidos correctamente',
            'data'    => $data,
        ]);
    }

    public function generar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'   => 'required|integer|min:1',
            'id_usuario'  => 'required|integer|min:1',
            'empleados'   => 'required|array|min:1',
            'empleados.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $idPortal  = (int) $request->input('id_portal');
        $idUsuario = (int) $request->input('id_usuario');

        $empleados = collect($request->input('empleados', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $registros = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id', $empleados->all())
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->get()
            ->keyBy('id');

        $detalle      = [];
        $procesados   = 0;
        $generados    = 0;
        $fallidos     = 0;
        $mailFallidos = 0;

        foreach ($empleados as $empleadoId) {
            $procesados++;

            $empleado = $registros->get($empleadoId);

            if (! $empleado) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleadoId,
                    'ok'   => false,
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            if ((int) $empleado->status !== 1) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_STATUS',
                ];
                continue;
            }

            if (! empty($empleado->fecha_salida)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EXIT_DATE',
                ];
                continue;
            }

            if (empty($empleado->correo)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_EMAIL',
                ];
                continue;
            }

            if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_EMAIL',
                ];
                continue;
            }

            if (! empty($empleado->password)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'ALREADY_HAS_ACCESS',
                ];
                continue;
            }

            $passwordPlano = $this->generarPasswordSeguro(12);

            DB::connection('portal_main')->beginTransaction();

            try {
                DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id', $empleado->id)
                    ->update([
                        'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                        'force_password_change' => 1,
                        'password_changed_at'   => now(),
                        'login_attempts'        => 0,
                        'locked_until'          => null,
                        'id_usuario'            => $idUsuario,
                        'edicion'               => now(),
                    ]);

                $licenciaActiva = DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->where('id_portal', $idPortal)
                    ->where('id_empleado', $empleado->id)
                    ->where('activo_cobrable', 1)
                    ->exists();

                if (! $licenciaActiva) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_licencias')
                        ->insert([
                            'id_portal'         => $idPortal,
                            'id_empleado'       => $empleado->id,
                            'id_cliente'        => $empleado->id_cliente,
                            'tipo_movimiento'   => 'alta',
                            'activo_cobrable'   => 1,
                            'costo_mensual'     => 3.00,
                            'moneda'            => 'USD',
                            'fecha_movimiento'  => now(),
                            'id_usuario_accion' => $idUsuario,
                            'observaciones'     => 'Generación inicial de credenciales',
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                }

                DB::connection('portal_main')->commit();
                try {
                    Mail::send(
                        'emails.comunicacion360.accesos_generados',
                        [
                            'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                            'correo'        => $empleado->correo,
                            'passwordPlano' => $passwordPlano,
                            'loginUrl'      => 'https://miportal.talentsafecontrol.com/login',
                        ],
                        function ($message) use ($empleado) {
                            $message->to($empleado->correo)
                                ->subject('Tus accesos de Communication 360');
                        }
                    );

                    $generados++;

                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'GENERATED',
                    ];
                } catch (\Throwable $mailException) {
                    \Log::error('Error al enviar correo de accesos Comunicación 360', [
                        'empleado_id' => $empleado->id,
                        'error'       => $mailException->getMessage(),
                    ]);

                    $generados++;
                    $mailFallidos++;
                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'GENERATED_MAIL_FAILED',
                    ];
                }
            } catch (\Throwable $e) {
                DB::connection('portal_main')->rollBack();

                \Log::error('Error al generar accesos Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'error'       => $e->getMessage(),
                ]);

                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'PROCESS_FAILED',
                ];
            }
        }

        return response()->json([
            'ok'   => true,
            'code' => ($fallidos > 0 || $mailFallidos > 0)
                ? 'COMPLETED_WITH_ERRORS'
                : 'COMPLETED_SUCCESS',
            'data' => [
                'procesados'    => $procesados,
                'generados'     => $generados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
                'detalle'       => $detalle,
            ],
        ]);
    }

    public function actualizar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'   => 'required|integer|min:1',
            'id_usuario'  => 'required|integer|min:1',
            'empleados'   => 'required|array|min:1',
            'empleados.*' => 'integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $idPortal  = (int) $request->input('id_portal');
        $idUsuario = (int) $request->input('id_usuario');

        $empleados = collect($request->input('empleados', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        $registros = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->whereIn('e.id', $empleados->all())
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->get()
            ->keyBy('id');

        $detalle      = [];
        $procesados   = 0;
        $actualizados = 0;
        $fallidos     = 0;
        $mailFallidos = 0;

        foreach ($empleados as $empleadoId) {
            $procesados++;

            $empleado = $registros->get($empleadoId);

            if (! $empleado) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleadoId,
                    'ok'   => false,
                    'code' => 'NOT_FOUND',
                ];
                continue;
            }

            if ((int) $empleado->status !== 1) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_STATUS',
                ];
                continue;
            }

            if (! empty($empleado->fecha_salida)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'EXIT_DATE',
                ];
                continue;
            }

            if (empty($empleado->correo)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_EMAIL',
                ];
                continue;
            }

            if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'INVALID_EMAIL',
                ];
                continue;
            }

            // 🔴 CLAVE: aquí es lo contrario a generar
            if (empty($empleado->password)) {
                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'NO_ACCESS_TO_UPDATE',
                ];
                continue;
            }

            $passwordPlano = $this->generarPasswordSeguro(12);

            DB::connection('portal_main')->beginTransaction();

            try {
                DB::connection('portal_main')
                    ->table('empleados')
                    ->where('id', $empleado->id)
                    ->update([
                        'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                        'force_password_change' => 1,
                        'password_changed_at'   => now(),
                        'login_attempts'        => 0,
                        'locked_until'          => null,
                        'id_usuario'            => $idUsuario,
                        'edicion'               => now(),
                    ]);

                // 🔵 Registrar rotación (NO crea nueva licencia)
                DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->insert([
                        'id_portal'         => $idPortal,
                        'id_empleado'       => $empleado->id,
                        'id_cliente'        => $empleado->id_cliente,
                        'tipo_movimiento'   => 'rotacion',
                        'activo_cobrable'   => 1,
                        'costo_mensual'     => 3.00,
                        'moneda'            => 'USD',
                        'fecha_movimiento'  => now(),
                        'id_usuario_accion' => $idUsuario,
                        'observaciones'     => 'Rotación de credenciales',
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);

                DB::connection('portal_main')->commit();

                try {
                    Mail::send(
                        'emails.comunicacion360.accesos_generados',
                        [
                            'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                            'correo'        => $empleado->correo,
                            'passwordPlano' => $passwordPlano,
                            'loginUrl'      => 'https://miportal.talentsafecontrol.com/login',
                        ],
                        function ($message) use ($empleado) {
                            $message->to($empleado->correo)
                                ->subject('Actualización de accesos - Communication 360');
                        }
                    );

                    $actualizados++;
                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'UPDATED',
                    ];
                } catch (\Throwable $mailException) {
                    \Log::error('Error al enviar correo de actualización Comunicación 360', [
                        'empleado_id' => $empleado->id,
                        'error'       => $mailException->getMessage(),
                    ]);

                    $actualizados++;
                    $mailFallidos++;

                    $detalle[] = [
                        'id'   => (int) $empleado->id,
                        'ok'   => true,
                        'code' => 'UPDATED_MAIL_FAILED',
                    ];
                }

            } catch (\Throwable $e) {
                DB::connection('portal_main')->rollBack();

                \Log::error('Error al actualizar accesos Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'error'       => $e->getMessage(),
                ]);

                $fallidos++;
                $detalle[] = [
                    'id'   => (int) $empleado->id,
                    'ok'   => false,
                    'code' => 'PROCESS_FAILED',
                ];
            }
        }

        return response()->json([
            'ok'   => true,
            'code' => ($fallidos > 0 || $mailFallidos > 0)
                ? 'COMPLETED_WITH_ERRORS'
                : 'COMPLETED_SUCCESS',
            'data' => [
                'procesados'    => $procesados,
                'actualizados'  => $actualizados,
                'fallidos'      => $fallidos,
                'mail_fallidos' => $mailFallidos,
                'detalle'       => $detalle,
            ],
        ]);
    }

    public function generarIndividual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'             => 'required|integer|min:1',
            'id_usuario'            => 'required|integer|min:1',
            'id_empleado'           => 'required|integer|min:1',
            'password_type'         => 'required|in:auto,manual',
            'password'              => [
                'required',
                'string',
                'confirmed',
                Password::min(10)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'password_confirmation' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $data = $validator->validated();

        $resultado = $this->procesarAccesoIndividual(
            idPortal: (int) $data['id_portal'],
            idUsuario: (int) $data['id_usuario'],
            empleadoId: (int) $data['id_empleado'],
            passwordPlano: $data['password'],
            modo: 'generate'
        );

        $status = $resultado['ok'] ? 200 : 422;

        return response()->json($resultado, $status);
    }

    public function actualizarIndividual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'id_portal'             => 'required|integer|min:1',
            'id_usuario'            => 'required|integer|min:1',
            'id_empleado'           => 'required|integer|min:1',
            'password_type'         => 'required|in:auto,manual',
            'password'              => [
                'required',
                'string',
                'confirmed',
                Password::min(10)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
            'password_confirmation' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'ok'   => false,
                'code' => 'INVALID_PARAMS',
                'data' => [
                    'errors' => $validator->errors(),
                ],
            ], 422);
        }

        $data = $validator->validated();

        $resultado = $this->procesarAccesoIndividual(
            idPortal: (int) $data['id_portal'],
            idUsuario: (int) $data['id_usuario'],
            empleadoId: (int) $data['id_empleado'],
            passwordPlano: $data['password'],
            modo: 'update'
        );

        $status = $resultado['ok'] ? 200 : 422;

        return response()->json($resultado, $status);
    }

    private function procesarAccesoIndividual(
        int $idPortal,
        int $idUsuario,
        int $empleadoId,
        string $passwordPlano,
        string $modo
    ): array {
        $empleado = DB::connection('portal_main')
            ->table('empleados as e')
            ->select([
                'e.id',
                'e.id_portal',
                'e.id_cliente',
                'e.id_empleado',
                'e.nombre',
                'e.paterno',
                'e.materno',
                'e.correo',
                'e.status',
                'e.password',
                'e.fecha_salida',
                'e.eliminado',
            ])
            ->where('e.id_portal', $idPortal)
            ->where('e.id', $empleadoId)
            ->where(function ($q) {
                $q->where('e.eliminado', 0)
                    ->orWhereNull('e.eliminado');
            })
            ->first();

        if (! $empleado) {
            return [
                'ok'   => false,
                'code' => 'NOT_FOUND',
                'data' => [
                    'id' => $empleadoId,
                ],
            ];
        }

        if ((int) $empleado->status !== 1) {
            return [
                'ok'   => false,
                'code' => 'INVALID_STATUS',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (! empty($empleado->fecha_salida)) {
            return [
                'ok'   => false,
                'code' => 'EXIT_DATE',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (empty($empleado->correo)) {
            return [
                'ok'   => false,
                'code' => 'NO_EMAIL',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if (! filter_var($empleado->correo, FILTER_VALIDATE_EMAIL)) {
            return [
                'ok'   => false,
                'code' => 'INVALID_EMAIL',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if ($modo === 'generate' && ! empty($empleado->password)) {
            return [
                'ok'   => false,
                'code' => 'ALREADY_HAS_ACCESS',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        if ($modo === 'update' && empty($empleado->password)) {
            return [
                'ok'   => false,
                'code' => 'NO_ACCESS_TO_UPDATE',
                'data' => [
                    'id' => (int) $empleado->id,
                ],
            ];
        }

        DB::connection('portal_main')->beginTransaction();

        try {
            DB::connection('portal_main')
                ->table('empleados')
                ->where('id', $empleado->id)
                ->update([
                    'password'              => Hash::make($passwordPlano, ['rounds' => 12]),
                    'force_password_change' => 1,
                    'password_changed_at'   => now(),
                    'login_attempts'        => 0,
                    'locked_until'          => null,
                    'id_usuario'            => $idUsuario,
                    'edicion'               => now(),
                ]);

            if ($modo === 'generate') {
                $licenciaActiva = DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->where('id_portal', $idPortal)
                    ->where('id_empleado', $empleado->id)
                    ->where('activo_cobrable', 1)
                    ->exists();

                if (! $licenciaActiva) {
                    DB::connection('portal_main')
                        ->table('comunicacion360_licencias')
                        ->insert([
                            'id_portal'         => $idPortal,
                            'id_empleado'       => $empleado->id,
                            'id_cliente'        => $empleado->id_cliente,
                            'tipo_movimiento'   => 'alta',
                            'activo_cobrable'   => 1,
                            'costo_mensual'     => 3.00,
                            'moneda'            => 'USD',
                            'fecha_movimiento'  => now(),
                            'id_usuario_accion' => $idUsuario,
                            'observaciones'     => 'Generación individual de credenciales',
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                }
            }

            if ($modo === 'update') {
                DB::connection('portal_main')
                    ->table('comunicacion360_licencias')
                    ->insert([
                        'id_portal'         => $idPortal,
                        'id_empleado'       => $empleado->id,
                        'id_cliente'        => $empleado->id_cliente,
                        'tipo_movimiento'   => 'rotacion',
                        'activo_cobrable'   => 1,
                        'costo_mensual'     => 3.00,
                        'moneda'            => 'USD',
                        'fecha_movimiento'  => now(),
                        'id_usuario_accion' => $idUsuario,
                        'observaciones'     => 'Rotación individual de credenciales',
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
            }

            DB::connection('portal_main')->commit();

            try {
                Mail::send(
                    'emails.comunicacion360.accesos_generados',
                    [
                        'nombre'        => trim($empleado->nombre . ' ' . $empleado->paterno),
                        'correo'        => $empleado->correo,
                        'passwordPlano' => $passwordPlano,
                        'loginUrl'      => 'https://miportal.talentsafecontrol.com/login',
                    ],
                    function ($message) use ($empleado, $modo) {
                        $subject = $modo === 'generate'
                            ? 'Tus accesos de Communication 360'
                            : 'Actualización de accesos - Communication 360';

                        $message->to($empleado->correo)
                            ->subject($subject);
                    }
                );

                return [
                    'ok'   => true,
                    'code' => $modo === 'generate' ? 'GENERATED' : 'UPDATED',
                    'data' => [
                        'id'        => (int) $empleado->id,
                        'correo'    => $empleado->correo,
                        'mail_sent' => true,
                    ],
                ];
            } catch (\Throwable $mailException) {
                \Log::error('Error al enviar correo individual Comunicación 360', [
                    'empleado_id' => $empleado->id,
                    'modo'        => $modo,
                    'error'       => $mailException->getMessage(),
                ]);

                return [
                    'ok'   => true,
                    'code' => $modo === 'generate' ? 'GENERATED_MAIL_FAILED' : 'UPDATED_MAIL_FAILED',
                    'data' => [
                        'id'        => (int) $empleado->id,
                        'correo'    => $empleado->correo,
                        'mail_sent' => false,
                    ],
                ];
            }
        } catch (\Throwable $e) {
            DB::connection('portal_main')->rollBack();

            \Log::error('Error al procesar acceso individual Comunicación 360', [
                'empleado_id' => $empleadoId,
                'modo'        => $modo,
                'error'       => $e->getMessage(),
            ]);

            return [
                'ok'   => false,
                'code' => 'PROCESS_FAILED',
                'data' => [
                    'id' => $empleadoId,
                ],
            ];
        }
    }

    private function generarPasswordSeguro($length = 12)
    {
        $mayusculas = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $minusculas = 'abcdefghijklmnopqrstuvwxyz';
        $numeros    = '0123456789';
        $simbolos   = '!@#$%^&*()-_=+[]{}<>?';

        $password = [
            $mayusculas[random_int(0, strlen($mayusculas) - 1)],
            $minusculas[random_int(0, strlen($minusculas) - 1)],
            $numeros[random_int(0, strlen($numeros) - 1)],
            $simbolos[random_int(0, strlen($simbolos) - 1)],
        ];

        $all = $mayusculas . $minusculas . $numeros . $simbolos;

        for ($i = 4; $i < $length; $i++) {
            $password[] = $all[random_int(0, strlen($all) - 1)];
        }

        shuffle($password);

        return implode('', $password);
    }
}
