<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\MedicalInfo;
use Illuminate\Http\Request;


class MedicalInfoController extends Controller
{
    // Método para actualizar la información médica
    public function upsert(Request $request, int $empleadoId)
    {
        // 1) Validación (sin creacion/edicion; Eloquent las maneja)
        $validated = $request->validate([
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

        // 2) Resuelve el empleado por PK
        $emp = Empleado::find($empleadoId);
        if (! $emp) {
            return response()->json(['message' => 'Empleado no encontrado.'], 404);
        }

                                                                    // 3) Upsert por FK -> empleados.id (PK)
        $mi = MedicalInfo::firstOrNew(['id_empleado' => $emp->id]); // <- SIEMPRE PK
        $mi->fill($validated);
        $mi->id_empleado = $emp->id; // asegura FK

        // Si usas timestamps mapeados a creacion/edicion (ver modelo abajo),
        // NO asignes creacion/edicion aquí: Eloquent lo hace por ti.
        $mi->save();

        return response()->json([
            'message' => $mi->wasRecentlyCreated
            ? 'Medical information created successfully.'
            : 'Medical information updated successfully.',
            'created' => $mi->wasRecentlyCreated,
            'data'    => $mi,
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