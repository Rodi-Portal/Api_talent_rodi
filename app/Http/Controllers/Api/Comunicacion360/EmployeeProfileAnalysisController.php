<?php

namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeProfileAnalysisController extends Controller
{
    public function show(Request $request, int $id)
    {
        $employee = DB::connection('portal_main')
            ->table('empleados')
            ->select('id', 'id_portal', 'id_cliente', 'status')
            ->where('id', $id)
            ->first();

        if (! $employee) {
            return response()->json([
                'ok' => false,
                'code' => 'employee_not_found',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'employee_id' => (int) $employee->id,
                'status' => 'normal',
                'risk_level' => 'low',

                'scores' => [
                    'punctuality' => null,
                    'productivity' => null,
                    'attendance' => null,
                    'compliance' => null,
                    'evidence' => null,
                ],

                'kpis' => [
                    'late_count' => 0,
                    'absences_count' => 0,
                    'completed_tasks' => 0,
                    'pending_tasks' => 0,
                    'missing_evidence' => 0,
                ],

                'insights' => [],

                'trends' => [
                    'current_week' => [],
                    'previous_week' => [],
                    'current_month' => [],
                    'previous_month' => [],
                ],

                'timeline' => [],
            ],
        ]);
    }
}