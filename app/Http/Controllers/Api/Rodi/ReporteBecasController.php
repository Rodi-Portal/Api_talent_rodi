<?php

namespace App\Http\Controllers\Api\Rodi;

use App\Http\Controllers\Controller;
use App\Services\Rodi\ReporteBecasService;
use Illuminate\Http\JsonResponse;

class ReporteBecasController extends Controller
{
    public function __construct(
        protected ReporteBecasService $service
    ) {}

    public function show(int $id_candidato): JsonResponse
    {
        if ($id_candidato <= 0) {
            return response()->json([
                'status'  => false,
                'message' => 'ID de candidato no válido',
            ], 422);
        }

        $data = $this->service->getReporteData($id_candidato);

        return response()->json([
            'status' => true,
            'data'   => $data,
        ]);
    }
}