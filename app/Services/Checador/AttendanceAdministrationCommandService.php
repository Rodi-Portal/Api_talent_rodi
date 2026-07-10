<?php
namespace App\Services\Checador;
use App\Models\Comunicacion360\Checador\Checada;
use InvalidArgumentException;

class AttendanceAdministrationCommandService
{
    public function __construct(
        protected AttendanceDayContextService $dayContextService,
        protected AttendanceAdministrationService $administrationService,
        protected AttendanceCheckWriterService $checkWriterService,
    ) {
    }

    public function execute(array $payload): array
    {
        $this->validatePayload($payload);

        $context = $this->dayContextService->resolver(
            (int) $payload['id_portal'],
            (int) $payload['id_empleado'],
            $payload['fecha']
        );

        $adminActions = $this->administrationService->resolveActions($context);

        $action = $payload['action'];

        $this->validateActionAllowed($action, $adminActions);

        return match ($action) {
            'registrar_entrada_jornada' => $this->registrarEntradaJornada($payload, $context, $adminActions),
            'registrar_par_jornada'     => $this->registrarParJornada($payload, $context, $adminActions),
            'registrar_par_intermedio'  => $this->registrarParIntermedio($payload, $context, $adminActions),
            'cerrar_movimiento_abierto' => $this->cerrarMovimientoAbierto($payload, $context, $adminActions),
            'editar_checada'            => $this->editarChecada($payload, $context, $adminActions),

            default                     => throw new InvalidArgumentException('Acción administrativa no soportada.'),
        };
    }

    protected function validatePayload(array $payload): void
    {
        $required = [
            'id_portal',
            'id_cliente',
            'id_usuario',
            'id_empleado',
            'action',
            'fecha',
            'motivo',
            'data',
        ];

        foreach ($required as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new InvalidArgumentException("Campo requerido faltante: {$field}");
            }
        }

        if (! is_array($payload['data'])) {
            throw new InvalidArgumentException('El campo data debe ser un arreglo.');
        }

        if (trim((string) $payload['motivo']) === '') {
            throw new InvalidArgumentException('El motivo administrativo es obligatorio.');
        }
    }

    protected function validateActionAllowed(string $action, array $adminActions): void
    {
        if ($action === 'editar_checada') {
            $this->validateEditCapability($adminActions);

            return;
        }

        $acciones = $adminActions['acciones'] ?? [];

        if (! array_key_exists($action, $acciones)) {
            throw new InvalidArgumentException(
                'La acción solicitada no está disponible para esta jornada.'
            );
        }

        $actionConfig = $acciones[$action];

        if (($actionConfig['permitido'] ?? false) !== true) {
            throw new InvalidArgumentException(
                $actionConfig['mensaje'] ?? $actionConfig['codigo'] ?? 'La acción solicitada no está permitida.'
            );
        }
    }
    protected function validateEditCapability(array $adminActions): void
    {
        $capacidades = $adminActions['capacidades'] ?? [];

        if (! array_key_exists('editar_checadas', $capacidades)) {
            throw new InvalidArgumentException(
                'La capacidad de editar checadas no está disponible para esta jornada.'
            );
        }

        if (($capacidades['editar_checadas'] ?? false) !== true) {
            throw new InvalidArgumentException('NO_CHECKS_TO_EDIT');
        }
    }
    protected function registrarEntradaJornada(array $payload, array $context, array $adminActions): array
    {
        $this->checkWriterService->createWorkCheckIn($payload, $context);

        return $this->reloadContext($payload);
    }

    protected function registrarParJornada(array $payload, array $context, array $adminActions): array
    {
        $this->checkWriterService->createWorkPair($payload, $context);

        return $this->reloadContext($payload);
    }

    protected function registrarParIntermedio(array $payload, array $context, array $adminActions): array
    {
        $this->checkWriterService->createIntermediatePair($payload, $context);

        return $this->reloadContext($payload);
    }
    protected function cerrarMovimientoAbierto(
        array $payload,
        array $context,
        array $adminActions
    ): array {
        $openCheck = $this->administrationService->findPendingMovement(
            $context['checadas'] ?? collect()
        );

        if (! $openCheck) {
            throw new InvalidArgumentException('NO_OPEN_MOVEMENT');
        }

        $this->checkWriterService->closeOpenMovement(
            $payload,
            $context,
            $openCheck
        );

        return $this->reloadContext($payload);
    }
    protected function editarChecada(
        array $payload,
        array $context,
        array $adminActions
    ): array {
        $check = $this->findEditableCheck($payload, $context);

        $this->checkWriterService->updateCheckTime(
            payload: $payload,
            context: $context,
            check: $check
        );

        return $this->reloadContext($payload);
    }
    private function findEditableCheck(
        array $payload,
        array $context
    ): Checada {
        $idChecada = $payload['data']['id_checada'] ?? null;

        if (! $idChecada) {
            throw new InvalidArgumentException('CHECK_ID_REQUIRED');
        }

        $check = Checada::query()
            ->where('id', (int) $idChecada)
            ->where('id_portal', (int) $payload['id_portal'])
            ->where('id_cliente', (int) $payload['id_cliente'])
            ->where('id_empleado', (int) $payload['id_empleado'])
            ->where('fecha', $payload['fecha'])
            ->first();

        if (! $check) {
            throw new InvalidArgumentException('CHECK_NOT_FOUND');
        }

        return $check;
    }
    private function reloadContext(array $payload): array
    {
        return $this->dayContextService->resolver(
            (int) $payload['id_portal'],
            (int) $payload['id_empleado'],
            $payload['fecha']
        );
    }
}
