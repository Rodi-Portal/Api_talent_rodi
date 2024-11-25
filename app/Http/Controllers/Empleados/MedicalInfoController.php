<?php

namespace App\Http\Controllers\Empleados;


use App\Http\Controllers\Controller;
use App\Models\MedicalInfo;
use Illuminate\Http\Request;

class MedicalInfoController extends Controller
{
    // Método para actualizar la información médica
    public function update(Request $request, $id_empleado)
    {
        // Validar la solicitud
        $validatedData = $request->validate([
            'creacion' => 'required|date',
            'edicion' => 'required|date',
            'peso' => 'nullable|numeric',
            'edad' => 'nullable|integer',
            'alergias_medicamentos' => 'nullable|string|max:255',
            'alergias_alimentos' => 'nullable|string|max:255',
            'enfermedades_cronicas' => 'nullable|string|max:255',
            'cirugias' => 'nullable|string|max:255',
            'tipo_sangre' => 'nullable|string|max:20',
            'contacto_emergencia' => 'nullable|string|max:255',
            'medicamentos_frecuentes' => 'nullable|string|max:255',
            'lesiones' => 'nullable|string|max:255',
            'otros_padecimientos' => 'nullable|string|max:500',
            'otros_padecimientos2' => 'nullable|string|max:500',

        ]);

        // Buscar la información médica existente
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (!$medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }

        // Actualizar los campos
        $medicalInfo->update($validatedData);

        return response()->json(['message' => 'Medical information updated successfully.', 'data' => $medicalInfo], 200);
    }
    public function show($id_empleado)
    {
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (!$medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }

        return response()->json($medicalInfo, 200);
    }
}

