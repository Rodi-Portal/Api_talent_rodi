<?php
namespace App\Http\Controllers\Api\Comunicacion360;

use App\Http\Controllers\Controller;
use App\Services\Checador\AttendanceReportService;
use Illuminate\Http\Request;

class AccesosChecadorReportesController extends Controller
{
    public function __construct(
        private AttendanceReportService $attendanceReportService
    ) {
    }

    public function vistaPrevia(Request $request, $id)
    {
        $datos = $request->validate([
            'id_portal'    => ['required', 'integer'],
            'fecha_inicio' => ['required', 'date_format:Y-m-d'],
            'fecha_fin'    => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:fecha_inicio',
                'before_or_equal:today',
            ],
        ]);

        $reporte = $this->attendanceReportService->generarVistaPrevia(
            (int) $datos['id_portal'],
            (int) $id,
            $datos['fecha_inicio'],
            $datos['fecha_fin']
        );

        return response()->json([
            'ok'   => true,
            'data' => $reporte,
        ]);
    }
}
