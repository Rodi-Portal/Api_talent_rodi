<?php

namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Models\Auth\AdministradorAuth;
use App\Services\Auth\AdminClientScopeService;
use App\Services\Checador\BranchAttendanceReportService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccesosAsistenciaSucursalController extends Controller
{
    public function __construct(
        private AdminClientScopeService $clientScope,
        private BranchAttendanceReportService $reportService
    ) {
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $administrator = $request->user();

        if (! $administrator instanceof AdministradorAuth) {
            throw new AuthorizationException(
                'Token administrativo no valido.'
            );
        }

        $datos = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'min:1',
            ],
            'tipo' => [
                'required',
                Rule::in(['diario', 'semanal']),
            ],
            'fecha_inicio' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            'fecha_fin' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:fecha_inicio',
                'before_or_equal:today',
            ],
            'empleados' => [
                'sometimes',
                'array',
                'min:1',
                'max:200',
            ],
            'empleados.*' => [
                'required',
                'integer',
                'min:1',
                'distinct',
            ],
        ]);

        $inicio = Carbon::parse($datos['fecha_inicio']);
        $fin = Carbon::parse($datos['fecha_fin']);

        if (
            $datos['tipo'] === 'diario' &&
            ! $inicio->isSameDay($fin)
        ) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'El reporte diario debe consultar una sola fecha.',
            ]);
        }

        if ($inicio->diffInDays($fin) > 6) {
            throw ValidationException::withMessages([
                'fecha_fin' => 'El periodo no puede superar siete dias.',
            ]);
        }

        $idCliente = (int) $datos['id_cliente'];

        $this->clientScope->authorizeRequestedClients(
            $administrator,
            [$idCliente]
        );

        $empleadoIds = $datos['empleados'] ?? null;

        $reporte = $this->reportService->generarVistaPrevia(
            (int) $administrator->id_portal,
            $idCliente,
            $datos['fecha_inicio'],
            $datos['fecha_fin'],
            $empleadoIds
        );

        return response()->json([
            'ok' => true,
            'data' => [
                'tipo' => $datos['tipo'],
                ...$reporte,
            ],
        ]);
    }
}