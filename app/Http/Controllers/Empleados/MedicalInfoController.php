<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\MedicalInfo;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MedicalInfoController extends Controller
{
    // Método para actualizar la información médica
    public function upsert(Request $request, $id_empleado)
    {
        // Validación: 'creacion' ya NO es obligatoria (solo se usará al crear)
        $validated = $request->validate([
            'creacion'                => 'nullable|date',
            'edicion'                 => 'nullable|date',
            'peso'                    => 'nullable|numeric',
            'edad'                    => 'nullable|integer',
            'alergias_medicamentos'   => 'nullable|string|max:255',
            'alergias_alimentos'      => 'nullable|string|max:255',
            'enfermedades_cronicas'   => 'nullable|string|max:255',
            'cirugias'                => 'nullable|string|max:255',
            'tipo_sangre'             => 'nullable|string|max:20',
            'contacto_emergencia'     => 'nullable|string|max:255',
            'medicamentos_frecuentes' => 'nullable|string|max:255',
            'lesiones'                => 'nullable|string|max:255',
            'otros_padecimientos'     => 'nullable|string|max:500',
            'otros_padecimientos2'    => 'nullable|string|max:500',
        ]);

        // Toma zona horaria MX si no viene 'edicion'
        $nowMx = Carbon::now('America/Mexico_City');

        // Busca o instancia sin guardar
        $medicalInfo = MedicalInfo::firstOrNew(['id_empleado' => $id_empleado]);

        // Rellena campos permitidos (evita pisar id_empleado)
        $medicalInfo->fill($validated);

        if (! $medicalInfo->exists) {
                                                                                       // Si es nuevo, fija 'creacion' (del request o ahora)
            $medicalInfo->creacion = $validated['creacion'] ?? $nowMx->toDateString(); // o ->toDateTimeString() según tu tipo
        }

        // Siempre actualiza 'edicion'
        $medicalInfo->edicion = $validated['edicion'] ?? $nowMx->toDateTimeString();

        // Asegura el vínculo al empleado
        $medicalInfo->id_empleado = $id_empleado;

        $medicalInfo->save();

        return response()->json([
            'message' => $medicalInfo->wasRecentlyCreated
            ? 'Medical information created successfully.'
            : 'Medical information updated successfully.',
            'created' => $medicalInfo->wasRecentlyCreated,
            'data'    => $medicalInfo,
        ], 200);
    }
    public function show($id_empleado)
    {
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (! $medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }

        return response()->json($medicalInfo, 200);
    }
}
