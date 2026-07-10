<?php
namespace App\Services\Checador;

use Illuminate\Support\Collection;

class AttendanceAdministrationService
{
    public function resolveActions(array $context): array
    {
        $checadas = $context['checadas'] ?? collect();

        $horario = $context['horario'] ?? null;

        if (is_array($horario)) {
            $tieneHorario = (bool) ($horario['tiene_horario'] ?? false);
            $labora       = (bool) ($horario['labora'] ?? false);
        } else {
            $plantillaHorario = $context['plantillaHorario'] ?? null;
            $detalleHorario   = $context['detalleHorario'] ?? null;

            $tieneHorario = $plantillaHorario !== null && $detalleHorario !== null;
            $labora       = $detalleHorario
                ? (bool) $detalleHorario->labora
                : false;
        }

        if (! $tieneHorario) {
            return $this->sinHorario($checadas);
        }

        if (! $labora) {
            return $this->diaNoLaborable($checadas);
        }

        if ($this->isEmptyChecks($checadas)) {
            return $this->sinChecadas();
        }
        $pendingMovement = $this->findPendingMovement($checadas);

        if ($pendingMovement) {
            return $this->jornadaAbierta($pendingMovement);
        }

        if ($this->hasCompleteWorkPair($checadas)) {
            return $this->jornadaConParLaboral();
        }

        return $this->modoRevisionManual();
    }

    private function sinHorario($checadas): array
    {
        $tieneChecadas = ! $this->isEmptyChecks($checadas);

        return [
            'modo'        => 'sin_horario',

            'capacidades' => $this->editable($tieneChecadas),

            'acciones'    => [
                'registrar_entrada_jornada' => $tieneChecadas
                    ? $this->denied('CHECKS_ALREADY_EXIST')
                    : $this->allowedExtraTime('ADMIN_WITHOUT_SCHEDULE'),

                'registrar_par_jornada'     => $tieneChecadas
                    ? $this->denied('CHECKS_ALREADY_EXIST')
                    : $this->allowedExtraTime('ADMIN_WITHOUT_SCHEDULE'),

                'registrar_par_intermedio'  => $tieneChecadas
                    ? $this->allowedExtraTime('ADMIN_WITHOUT_SCHEDULE')
                    : $this->denied('WORK_PAIR_REQUIRED_FIRST'),

                'cerrar_movimiento_abierto' => $this->findPendingMovement($checadas)
                    ? $this->allowedExtraTime('ADMIN_WITHOUT_SCHEDULE')
                    : $this->denied('NO_OPEN_MOVEMENT'),
            ],
        ];
    }

    private function diaNoLaborable($checadas): array
    {
        $tieneChecadas = ! $this->isEmptyChecks($checadas);

        return [
            'modo'        => 'dia_no_laborable',

            'capacidades' => $this->editable($tieneChecadas),

            'acciones'    => [
                'registrar_entrada_jornada' => $tieneChecadas
                    ? $this->denied('CHECKS_ALREADY_EXIST')
                    : $this->allowedExtraTime('NON_WORKING_DAY'),

                'registrar_par_jornada'     => $tieneChecadas
                    ? $this->denied('CHECKS_ALREADY_EXIST')
                    : $this->allowedExtraTime('NON_WORKING_DAY'),

                'registrar_par_intermedio'  => $tieneChecadas
                    ? $this->allowedExtraTime('NON_WORKING_DAY')
                    : $this->denied('WORK_PAIR_REQUIRED_FIRST'),
                'cerrar_movimiento_abierto' => $this->findPendingMovement($checadas)
                    ? $this->allowedExtraTime('NON_WORKING_DAY')
                    : $this->denied('NO_OPEN_MOVEMENT'),
            ],
        ];
    }

    private function sinChecadas(): array
    {
        return [
            'modo'        => 'sin_checadas',

            'capacidades' => $this->editable(false),

            'acciones'    => [
                'registrar_entrada_jornada' => $this->allowed(),
                'registrar_par_jornada'     => $this->allowed(),
                'registrar_par_intermedio'  => $this->denied('WORK_PAIR_REQUIRED_FIRST'),
                'cerrar_movimiento_abierto' => $this->denied('NO_OPEN_MOVEMENT'),
            ],
        ];
    }

    private function jornadaConParLaboral(): array
    {
        return [
            'modo'        => 'jornada_con_par_laboral',

            'capacidades' => $this->editable(true),

            'acciones'    => [
                'registrar_entrada_jornada' => $this->denied('CHECKS_ALREADY_EXIST'),
                'registrar_par_jornada'     => $this->denied('CHECKS_ALREADY_EXIST'),
                'registrar_par_intermedio'  => $this->allowed(),
                'cerrar_movimiento_abierto' => $this->denied('NO_OPEN_MOVEMENT'),
            ],
        ];
    }

    private function jornadaAbierta($pendingMovement): array
    {
        $tipo      = $this->checkValue($pendingMovement, 'tipo');
        $clase     = $this->checkValue($pendingMovement, 'clase');
        $checkTime = $this->checkValue($pendingMovement, 'check_time');

        $workShiftIsOpen = $tipo === 'in' && $clase === 'work';

        return [
            'modo'                 => 'jornada_abierta',

            'movimiento_pendiente' => [
                'id'           => $this->checkValue($pendingMovement, 'id'),
                'tipo'         => $tipo,
                'clase'        => $clase,
                'check_time'   => $this->formatCheckTime($checkTime),
                'hora'         => $this->formatCheckHour($checkTime),
                'tipo_cierre'  => $this->resolveClosingType($tipo, $clase),
                'clase_cierre' => $clase,
            ],

            'capacidades'          => $this->editable(true),

            'acciones'             => [
                'registrar_entrada_jornada' => $this->denied(
                    'OPEN_MOVEMENT_EXISTS'
                ),

                'registrar_par_jornada'     => $this->denied(
                    'OPEN_MOVEMENT_EXISTS'
                ),

                'registrar_par_intermedio'  => $workShiftIsOpen
                    ? $this->allowed()
                    : $this->denied('OPEN_INTERMEDIATE_MOVEMENT_EXISTS'),

                'cerrar_movimiento_abierto' => $this->allowed(),
            ],
        ];
    }

    private function modoRevisionManual(): array
    {
        return [
            'modo'        => 'revision_manual',

            'capacidades' => $this->editable(true),

            'acciones'    => [
                'registrar_entrada_jornada' => $this->denied('ADMIN_REVIEW_REQUIRED'),
                'registrar_par_jornada'     => $this->denied('ADMIN_REVIEW_REQUIRED'),
                'registrar_par_intermedio'  => $this->allowed(),
                'cerrar_movimiento_abierto' => $this->allowed(),
            ],
        ];
    }

    private function hasCompleteWorkPair($checadas): bool
    {
        $collection = $this->toCollection($checadas);

        $hasInWork = $collection->contains(function ($check) {
            return $this->checkValue($check, 'tipo') === 'in'
            && $this->checkValue($check, 'clase') === 'work';
        });

        $hasOutWork = $collection->contains(function ($check) {
            return $this->checkValue($check, 'tipo') === 'out'
            && $this->checkValue($check, 'clase') === 'work';
        });

        return $hasInWork && $hasOutWork;
    }
    public function findPendingMovement($checadas)
    {
        $collection = $this->toCollection($checadas)
            ->sortBy(function ($check) {
                return $this->checkValue($check, 'check_time');
            })
            ->values();

        if ($collection->isEmpty()) {
            return null;
        }

        $pendingWork = [];

        $pendingIntermediate = [
            'meal'     => [],
            'break'    => [],
            'personal' => [],
            'transfer' => [],
        ];

        foreach ($collection as $check) {
            $tipo  = $this->checkValue($check, 'tipo');
            $clase = $this->checkValue($check, 'clase');

            /*
         * Jornada laboral:
         *
         * in/work abre
         * out/work cierra
         */
            if ($clase === 'work') {
                if ($tipo === 'in') {
                    $pendingWork[] = $check;

                    continue;
                }

                if ($tipo === 'out' && ! empty($pendingWork)) {
                    array_shift($pendingWork);
                }

                continue;
            }

            /*
         * Movimientos intermedios:
         *
         * out/{clase} abre
         * in/{clase} cierra
         */
            if (! array_key_exists($clase, $pendingIntermediate)) {
                continue;
            }

            if ($tipo === 'out') {
                $pendingIntermediate[$clase][] = $check;

                continue;
            }

            if (
                $tipo === 'in'
                && ! empty($pendingIntermediate[$clase])
            ) {
                array_shift($pendingIntermediate[$clase]);
            }
        }

        $pendingChecks = collect($pendingWork);

        foreach ($pendingIntermediate as $checks) {
            $pendingChecks = $pendingChecks->concat($checks);
        }

        if ($pendingChecks->isEmpty()) {
            return null;
        }

        return $pendingChecks
            ->sortBy(function ($check) {
                return $this->checkValue($check, 'check_time');
            })
            ->last();
    }

    private function isEmptyChecks($checadas): bool
    {
        return $this->toCollection($checadas)->isEmpty();
    }

    private function toCollection($checadas): Collection
    {
        if ($checadas instanceof Collection) {
            return $checadas;
        }

        return collect($checadas);
    }

    private function allowed(): array
    {
        return [
            'permitido' => true,
            'codigo'    => null,
        ];
    }

    private function allowedExtraTime(string $codigo): array
    {
        return [
            'permitido'     => true,
            'codigo'        => $codigo,
            'genera_evento' => 'horas_extra',
        ];
    }

    private function editable(bool $permitido): array
    {
        return [
            'editar_checadas' => $permitido,
        ];
    }

    private function denied(string $codigo): array
    {
        return [
            'permitido' => false,
            'codigo'    => $codigo,
        ];
    }

    private function resolveClosingType(?string $tipo, ?string $clase): ?string
    {
        if ($clase === 'work' && $tipo === 'in') {
            return 'out';
        }

        if (
            in_array($clase, ['meal', 'break', 'personal', 'transfer'], true)
            && $tipo === 'out'
        ) {
            return 'in';
        }

        return null;
    }

    private function formatCheckTime($checkTime): ?string
    {
        if (! $checkTime) {
            return null;
        }

        if ($checkTime instanceof \DateTimeInterface) {
            return $checkTime->format('Y-m-d H:i:s');
        }

        try {
            return \Carbon\Carbon::parse($checkTime)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string) $checkTime;
        }
    }

    private function formatCheckHour($checkTime): ?string
    {
        if (! $checkTime) {
            return null;
        }

        if ($checkTime instanceof \DateTimeInterface) {
            return $checkTime->format('H:i');
        }

        try {
            return \Carbon\Carbon::parse($checkTime)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }
    private function checkValue($check, string $field)
    {
        if (is_array($check)) {
            return $check[$field] ?? null;
        }

        if (is_object($check)) {
            return $check->{$field} ?? null;
        }

        return null;
    }
}
