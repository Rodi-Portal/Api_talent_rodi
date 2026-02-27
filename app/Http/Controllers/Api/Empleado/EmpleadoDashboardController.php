<?php

namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\miportal\EmpleadoDashboardService;

class EmpleadoDashboardController extends Controller
{
    private EmpleadoDashboardService $service;

    public function __construct(EmpleadoDashboardService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        $employee = $request->user(); // auth:empleado

        if (!$employee) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        $data = $this->service->fetch($employee->id);

        return response()->json([
            'profile' => [
                'id'     => $employee->id,
                'nombre' => trim(
                    ($employee->nombre ?? '') . ' ' .
                    ($employee->paterno ?? '') . ' ' .
                    ($employee->materno ?? '')
                ),
                'puesto' => $employee->puesto,
                'foto'   => $employee->foto,
            ],
            ...$data
        ]);
    }
}