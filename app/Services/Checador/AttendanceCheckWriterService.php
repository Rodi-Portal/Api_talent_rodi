<?php
namespace App\Services\Checador;

use App\Models\Comunicacion360\Checador\Checada;
use Carbon\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AttendanceCheckWriterService
{
    public function __construct(
        protected AttendanceAdminAuditService $auditService,
    ) {
    }
    public function createWorkCheckIn(array $payload, array $context): Checada
    {
        $hora = $payload['data']['hora'] ?? null;

        if (! $hora) {
            throw new InvalidArgumentException('La hora de entrada es obligatoria.');
        }

        $hora = $this->normalizeHour($hora);

        $timezone = $context['plantillaHorario']->timezone ?? $payload['data']['timezone'] ?? 'America/Mexico_City';

        $checkTime = Carbon::parse($payload['fecha'] . ' ' . $hora, $timezone);
        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkTime,
            'in',
            'work'
        );
        $metadata = $this->buildCreateAuditMetadata(
            payload: $payload,
            checkTime: $checkTime,
            tipo: 'in',
            clase: 'work',
            origen: 'manual'
        );

        return Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkTime->toDateTimeString(),
            'tipo'               => 'in',
            'clase'              => 'work',
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash($payload, $checkTime, 'in', 'work'),
            'metadata'           => $metadata,
        ]);
    }
    public function createWorkPair(array $payload, array $context): array
    {
        $horaEntrada = $payload['data']['hora_entrada'] ?? null;
        $horaSalida  = $payload['data']['hora_salida'] ?? null;

        if (! $horaEntrada) {
            throw new InvalidArgumentException('WORK_CHECK_IN_TIME_REQUIRED');
        }

        if (! $horaSalida) {
            throw new InvalidArgumentException('WORK_CHECK_OUT_TIME_REQUIRED');
        }

        $horaEntrada = $this->normalizeHour($horaEntrada);
        $horaSalida  = $this->normalizeHour($horaSalida);

        $timezone = $context['plantillaHorario']->timezone ?? $payload['data']['timezone'] ?? 'America/Mexico_City';

        $checkInTime  = Carbon::parse($payload['fecha'] . ' ' . $horaEntrada, $timezone);
        $checkOutTime = Carbon::parse($payload['fecha'] . ' ' . $horaSalida, $timezone);

        if ($checkOutTime->lessThanOrEqualTo($checkInTime)) {
            throw new InvalidArgumentException('WORK_CHECK_OUT_MUST_BE_AFTER_CHECK_IN');
        }

        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkInTime,
            'in',
            'work'
        );

        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkOutTime,
            'out',
            'work'
        );

        $checkIn = Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkInTime->toDateTimeString(),
            'tipo'               => 'in',
            'clase'              => 'work',
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash($payload, $checkInTime, 'in', 'work'),
            'metadata'           => $this->buildCreateAuditMetadata(
                payload: $payload,
                checkTime: $checkInTime,
                tipo: 'in',
                clase: 'work',
                origen: 'manual'
            ),
        ]);

        $checkOut = Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkOutTime->toDateTimeString(),
            'tipo'               => 'out',
            'clase'              => 'work',
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash($payload, $checkOutTime, 'out', 'work'),
            'metadata'           => $this->buildCreateAuditMetadata(
                payload: $payload,
                checkTime: $checkOutTime,
                tipo: 'out',
                clase: 'work',
                origen: 'manual'
            ),
        ]);

        return [
            'entrada' => $checkIn,
            'salida'  => $checkOut,
        ];
    }

    public function createIntermediatePair(array $payload, array $context): array
    {
        $clase       = $payload['data']['clase'] ?? null;
        $horaSalida  = $payload['data']['hora_salida'] ?? null;
        $horaEntrada = $payload['data']['hora_entrada'] ?? null;

        $allowedClasses = [
            'meal',
            'break',
            'personal',
            'transfer',
        ];

        if (! in_array($clase, $allowedClasses, true)) {
            throw new InvalidArgumentException('INVALID_INTERMEDIATE_CLASS');
        }

        if (! $horaSalida) {
            throw new InvalidArgumentException('INTERMEDIATE_CHECK_OUT_TIME_REQUIRED');
        }

        if (! $horaEntrada) {
            throw new InvalidArgumentException('INTERMEDIATE_CHECK_IN_TIME_REQUIRED');
        }

        $horaSalida  = $this->normalizeHour($horaSalida);
        $horaEntrada = $this->normalizeHour($horaEntrada);

        $timezone = $context['plantillaHorario']->timezone ?? $payload['data']['timezone'] ?? 'America/Mexico_City';

        $checkOutTime = Carbon::parse($payload['fecha'] . ' ' . $horaSalida, $timezone);
        $checkInTime  = Carbon::parse($payload['fecha'] . ' ' . $horaEntrada, $timezone);

        if ($checkInTime->lessThanOrEqualTo($checkOutTime)) {
            throw new InvalidArgumentException('INTERMEDIATE_CHECK_IN_MUST_BE_AFTER_CHECK_OUT');
        }

        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkOutTime,
            'out',
            $clase
        );

        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkInTime,
            'in',
            $clase
        );

        $checkOut = Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkOutTime->toDateTimeString(),
            'tipo'               => 'out',
            'clase'              => $clase,
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash($payload, $checkOutTime, 'out', $clase),
            'metadata'           => $this->buildCreateAuditMetadata(
                payload: $payload,
                checkTime: $checkOutTime,
                tipo: 'out',
                clase: $clase,
                origen: 'manual'
            ),
        ]);

        $checkIn = Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkInTime->toDateTimeString(),
            'tipo'               => 'in',
            'clase'              => $clase,
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash($payload, $checkInTime, 'in', $clase),
            'metadata'           => $this->buildCreateAuditMetadata(
                payload: $payload,
                checkTime: $checkInTime,
                tipo: 'in',
                clase: $clase,
                origen: 'manual'
            ),
        ]);

        return [
            'salida'  => $checkOut,
            'entrada' => $checkIn,
        ];
    }

    public function closeOpenMovement(
        array $payload,
        array $context,
        Checada $openCheck
    ): Checada {
        $hora = $payload['data']['hora'] ?? null;

        if (! $hora) {
            throw new InvalidArgumentException('OPEN_MOVEMENT_CLOSE_TIME_REQUIRED');
        }

        $hora = $this->normalizeHour($hora);

        [$tipoCierre, $claseCierre] = $this->resolveCloseMovementType($openCheck);

        $timezone = $context['plantillaHorario']->timezone ?? $openCheck->timezone ?? $payload['data']['timezone'] ?? 'America/Mexico_City';

        $checkTime = Carbon::parse(
            $payload['fecha'] . ' ' . $hora,
            $timezone
        );

        $openCheckTime = Carbon::parse(
            $openCheck->check_time,
            $timezone
        );

        if ($checkTime->lessThanOrEqualTo($openCheckTime)) {
            throw new InvalidArgumentException(
                'OPEN_MOVEMENT_CLOSE_TIME_MUST_BE_AFTER_OPEN_TIME'
            );
        }

        /*
          * Cuando se cierra la jornada laboral:
          *
          * in/work → out/work
          *
          * la salida laboral debe quedar después de todas las checadas
          * ya registradas para el día.
          *
          * Esto evita secuencias inválidas como:
          *
          * 09:02 in/work
          * 10:00 out/work
          * 11:00 out/meal
          * 11:02 in/meal
          */
        if ($tipoCierre === 'out' && $claseCierre === 'work') {
            $this->ensureWorkCheckoutIsAfterExistingChecks(
                $context['checadas'] ?? collect(),
                $checkTime,
                $timezone
            );
        }

        $this->ensureNaturalCheckDoesNotExist(
            (int) $payload['id_portal'],
            (int) $payload['id_cliente'],
            (int) $payload['id_empleado'],
            $checkTime,
            $tipoCierre,
            $claseCierre
        );

        return Checada::create([
            'id_portal'          => (int) $payload['id_portal'],
            'id_cliente'         => (int) $payload['id_cliente'],
            'id_empleado'        => (int) $payload['id_empleado'],
            'id_asignacion'      => $context['asignacion']->id ?? $openCheck->id_asignacion ?? null,
            'fecha'              => $payload['fecha'],
            'check_time'         => $checkTime->toDateTimeString(),
            'tipo'               => $tipoCierre,
            'clase'              => $claseCierre,
            'origen'             => 'manual',
            'metodo_validacion'  => 'admin',
            'estatus_validacion' => 'valida',
            'timezone'           => $timezone,
            'observacion'        => $payload['motivo'],
            'hash'               => $this->generateHash(
                $payload,
                $checkTime,
                $tipoCierre,
                $claseCierre
            ),
            'metadata'           => $this->buildCreateAuditMetadata(
                payload: $payload,
                checkTime: $checkTime,
                tipo: $tipoCierre,
                clase: $claseCierre,
                origen: 'manual'
            ),
        ]);
    }

    public function updateCheckTime(
        array $payload,
        array $context,
        Checada $check
    ): Checada {
        $hora = $payload['data']['hora'] ?? null;

        if (! $hora) {
            throw new InvalidArgumentException('CHECK_TIME_REQUIRED');
        }

        $hora = $this->normalizeHour($hora);

        $timezone = $context['plantillaHorario']->timezone ?? $check->timezone ?? $payload['data']['timezone'] ?? 'America/Mexico_City';

        $newCheckTime = Carbon::parse(
            $payload['fecha'] . ' ' . $hora,
            $timezone
        );

        /*
     * La edición sólo modifica la hora.
     * La checada debe permanecer en la misma fecha administrativa.
     */
        if ($newCheckTime->toDateString() !== $payload['fecha']) {
            throw new InvalidArgumentException('CHECK_DATE_CHANGE_NOT_ALLOWED');
        }

        $originalCheckTime = Carbon::parse(
            $check->check_time,
            $timezone
        );

        /*
     * Si la hora realmente no cambia, no tiene sentido generar
     * una actualización ni una nueva entrada de auditoría.
     */
        if ($newCheckTime->equalTo($originalCheckTime)) {
            throw new InvalidArgumentException('CHECK_TIME_HAS_NOT_CHANGED');
        }

        $this->ensureEditedCheckKeepsChronologicalPosition(
            checks: $context['checadas'] ?? collect(),
            editedCheck: $check,
            newCheckTime: $newCheckTime,
            timezone: $timezone
        );
        $this->ensureNaturalCheckDoesNotExistForUpdate(
            idPortal: (int) $payload['id_portal'],
            idCliente: (int) $payload['id_cliente'],
            idEmpleado: (int) $payload['id_empleado'],
            checkTime: $newCheckTime,
            tipo: $check->tipo,
            clase: $check->clase,
            ignoredCheckId: (int) $check->id
        );

        $original = [
            'fecha'      => $check->fecha instanceof \DateTimeInterface
                ? $check->fecha->format('Y-m-d')
                : (string) $check->fecha,
            'check_time' => $originalCheckTime->toDateTimeString(),
            'tipo'       => $check->tipo,
            'clase'      => $check->clase,
            'origen'     => $check->origen,
            'timezone'   => $check->timezone,
            'hash'       => $check->hash,
        ];

        $new = [
            'fecha'        => $payload['fecha'],
            'check_time'   => $newCheckTime->toDateTimeString(),
            'tipo'         => $check->tipo,
            'clase'        => $check->clase,
            'origen'       => $check->origen,
            'timezone'     => $timezone,
            'origen_admin' => true,
            'admin_source' => 'comunicacion360_admin',
        ];

        $metadata = $this->auditService->append(
            metadata: $check->metadata,
            action: $payload['action'],
            adminUser: $this->buildAdminUser($payload),
            reason: $payload['motivo'],
            original: $original,
            new : $new
        );

        $check->check_time  = $newCheckTime->toDateTimeString();
        $check->timezone    = $timezone;
        $check->observacion = $payload['motivo'];
        $check->hash        = $this->generateHash(
            $payload,
            $newCheckTime,
            $check->tipo,
            $check->clase
        );
        $check->metadata = $metadata;

        $check->save();

        return $check->fresh();
    }

    private function resolveCloseMovementType($openCheck): array
    {
        if ($openCheck->tipo === 'in') {
            return ['out', $openCheck->clase];
        }

        if ($openCheck->tipo === 'out' && $openCheck->clase !== 'work') {
            return ['in', $openCheck->clase];
        }

        throw new InvalidArgumentException('NO_OPEN_MOVEMENT');
    }

    private function normalizeHour(string $hora): string
    {
        if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $hora)) {
            throw new InvalidArgumentException('La hora debe tener formato HH:mm o HH:mm:ss.');
        }

        if (strlen($hora) === 5) {
            return $hora . ':00';
        }

        return $hora;
    }

    private function buildCreateAuditMetadata(
        array $payload,
        Carbon $checkTime,
        string $tipo,
        string $clase,
        string $origen
    ): array {
        return $this->auditService->append(
            metadata: null,
            action: $payload['action'],
            adminUser: $this->buildAdminUser($payload),
            reason: $payload['motivo'],
            original: null,
            new : [
                'fecha'        => $payload['fecha'],
                'check_time'   => $checkTime->toDateTimeString(),
                'tipo'         => $tipo,
                'clase'        => $clase,
                'origen'       => $origen,
                'origen_admin' => true,
                'admin_source' => 'comunicacion360_admin',
            ]
        );
    }
    private function buildAdminUser(array $payload): object
    {
        return (object) [
            'id'   => (int) $payload['id_usuario'],
            'name' => $payload['data']['admin_user_name'] ?? null,
        ];
    }
    private function generateHash(
        array $payload,
        Carbon $checkTime,
        string $tipo,
        string $clase
    ): string {
        return md5(implode('|', [
            $payload['id_portal'],
            $payload['id_cliente'],
            $payload['id_empleado'],
            $payload['fecha'],
            $checkTime->toDateTimeString(),
            $tipo,
            $clase,
            'admin',
            Str::uuid()->toString(),
        ]));
    }
    public function ensureWorkCheckoutIsAfterExistingChecks(
        $checks,
        Carbon $checkTime,
        string $timezone
    ): void {
        $lastExistingCheckTime = collect($checks)
            ->map(function ($check) use ($timezone) {
                $existingCheckTime = data_get($check, 'check_time');

                if (empty($existingCheckTime)) {
                    return null;
                }

                return Carbon::parse(
                    $existingCheckTime,
                    $timezone
                );
            })
            ->filter()
            ->sortByDesc(function (Carbon $existingCheckTime) {
                return $existingCheckTime->timestamp;
            })
            ->first();

        if (! $lastExistingCheckTime) {
            return;
        }

        if ($checkTime->lessThanOrEqualTo($lastExistingCheckTime)) {
            throw new InvalidArgumentException(
                'WORK_CHECK_OUT_MUST_BE_AFTER_LAST_CHECK'
            );
        }
    }
    private function ensureEditedCheckKeepsChronologicalPosition(
        $checks,
        Checada $editedCheck,
        Carbon $newCheckTime,
        string $timezone
    ): void {
        $orderedChecks = collect($checks)
            ->filter(function ($check) {
                return data_get($check, 'id') !== null
                && data_get($check, 'check_time') !== null;
            })
            ->sort(function ($firstCheck, $secondCheck) use ($timezone) {
                $firstCheckTime = Carbon::parse(
                    data_get($firstCheck, 'check_time'),
                    $timezone
                );

                $secondCheckTime = Carbon::parse(
                    data_get($secondCheck, 'check_time'),
                    $timezone
                );

                $timeComparison = $firstCheckTime->timestamp
                <=> $secondCheckTime->timestamp;

                if ($timeComparison !== 0) {
                    return $timeComparison;
                }

                return (int) data_get($firstCheck, 'id')
                <=> (int) data_get($secondCheck, 'id');
            })
            ->values();

        $editedIndex = $orderedChecks->search(function ($check) use ($editedCheck) {
            return (int) data_get($check, 'id') === (int) $editedCheck->id;
        });

        if ($editedIndex === false) {
            throw new InvalidArgumentException(
                'CHECK_NOT_FOUND_IN_SEQUENCE'
            );
        }

        $previousCheck = $editedIndex > 0
            ? $orderedChecks->get($editedIndex - 1)
            : null;

        $nextCheck = $editedIndex < ($orderedChecks->count() - 1)
            ? $orderedChecks->get($editedIndex + 1)
            : null;

        if ($previousCheck) {
            $previousCheckTime = Carbon::parse(
                data_get($previousCheck, 'check_time'),
                $timezone
            );

            if ($newCheckTime->lessThan($previousCheckTime)) {
                throw new InvalidArgumentException(
                    'CHECK_TIME_MUST_NOT_BE_BEFORE_PREVIOUS_CHECK'
                );
            }
        }

        if ($nextCheck) {
            $nextCheckTime = Carbon::parse(
                data_get($nextCheck, 'check_time'),
                $timezone
            );

            if ($newCheckTime->greaterThan($nextCheckTime)) {
                throw new InvalidArgumentException(
                    'CHECK_TIME_MUST_NOT_BE_AFTER_NEXT_CHECK'
                );
            }
        }
    }
    private function ensureNaturalCheckDoesNotExist(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $checkTime,
        string $tipo,
        string $clase
    ): void {
        $exists = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('check_time', $checkTime->toDateTimeString())
            ->where('tipo', $tipo)
            ->where('clase', $clase)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException(
                'CHECK_ALREADY_EXISTS'
            );
        }
    }

    private function ensureNaturalCheckDoesNotExistForUpdate(
        int $idPortal,
        int $idCliente,
        int $idEmpleado,
        Carbon $checkTime,
        string $tipo,
        string $clase,
        int $ignoredCheckId
    ): void {
        $exists = Checada::query()
            ->where('id_portal', $idPortal)
            ->where('id_cliente', $idCliente)
            ->where('id_empleado', $idEmpleado)
            ->where('check_time', $checkTime->toDateTimeString())
            ->where('tipo', $tipo)
            ->where('clase', $clase)
            ->where('id', '!=', $ignoredCheckId)
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException('CHECK_ALREADY_EXISTS');
        }
    }
}
