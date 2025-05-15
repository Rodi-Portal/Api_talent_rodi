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
        try {
            \Log::info('Datos recibidos en update: ', $request->all());

            $payloadKeys = array_keys($request->all());

            // Si vienen muchos datos (más allá de los campos médicos), se fuerza la creación
            $crearDirectamente = isset($request->payload['nombre']) || isset($request->nombre);

            // Validar solo si es creación directa o si existen solo los campos médicos
            $validatedData = $request->validate([
                'creacion'                => 'required|date',
                'edicion'                 => 'required|date',
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

            // Si viene un payload completo, no validamos si ya existe, simplemente creamos
            if ($crearDirectamente) {
                $validatedData['id_empleado'] = $request->id; // ← este ID es el correcto para creación
                $medicalInfo                  = MedicalInfo::create($validatedData);

                return response()->json([
                    'message' => 'Información médica creada directamente.',
                    'data'    => $medicalInfo,
                ], 201);
            }

            // Si no viene payload completo, se trata como actualización normal
            $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

             if (!$medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }
                $medicalInfo->update($validatedData);
                $message    = 'Información médica actualizada correctamente.';
                $statusCode = 200;

            return response()->json([
                'message' => $message,
                'data'    => $medicalInfo,
            ], $statusCode);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Error in MedicalInfo update:', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error'   => $e->getMessage(),
            ], 500);
        }
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
