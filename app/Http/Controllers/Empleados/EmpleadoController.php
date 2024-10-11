<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\DocumentEmpleado;
use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\MedicalInfo;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EmpleadoController extends Controller
{
    public function update(Request $request)
    {

        // Validación
        $validator = Validator::make($request->all(), [
            'id' => 'required|integer',
            'edicion' => 'required|date',
            'domicilio_empleado.id' => 'required|integer',
            'domicilio_empleado.pais' => 'nullable|string|max:255',
            'domicilio_empleado.estado' => 'nullable|string|max:255',
            'domicilio_empleado.ciudad' => 'nullable|string|max:255',
            'domicilio_empleado.colonia' => 'nullable|string|max:255',
            'domicilio_empleado.calle' => 'nullable|string|max:255',
            'domicilio_empleado.cp' => 'nullable|integer',
            'domicilio_empleado.num_int' => 'nullable|string|max:255',
            'domicilio_empleado.num_ext' => 'nullable|string|max:255',
            // ... otros campos del empleado ...
        ]);

        if ($validator->fails()) {
            \Log::error('Validation errors:', $validator->errors()->toArray());
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Actualizar el empleado
            $empleado = Empleado::findOrFail($request->id);
            $empleado->update($request->only([
                'edicion',
                'nombre',
                'paterno',
                'materno',
                'telefono',
                'correo',
                'puesto',
                'rfc',
                'nss',
                'curp',
                'foto',
                'fecha_nacimiento',
                'status',
                'eliminado',
            ]));

            // Actualizar el domicilio
            $domicilio = DomicilioEmpleado::findOrFail($request->domicilio_empleado['id']);
            $domicilio->update($request->domicilio_empleado);

            // Log de éxito
            \Log::info('Empleado y domicilio actualizados correctamente.', ['empleado_id' => $empleado->id, 'domicilio_id' => $domicilio->id]);

            return response()->json(['message' => 'Empleado y domicilio actualizados correctamente.'], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Model not found:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Empleado o domicilio no encontrado.'], 404);
        } catch (Exception $e) {
            \Log::error('Error al actualizar:', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'Ocurrió un error al actualizar los datos.'], 500);
        }
    }

    public function show($id_empleado)
    {
        $medicalInfo = MedicalInfo::where('id_empleado', $id_empleado)->first();

        if (!$medicalInfo) {
            return response()->json(['message' => 'Medical information not found.'], 404);
        }

        return response()->json($medicalInfo, 200);
    }

    public function getDocumentos($id_empleado)
    {
        $documentos = DocumentEmpleado::where('employee_id', $id_empleado)->get();

        if ($documentos->isEmpty()) {
            return response()->json(['message' => 'No se encontraron documentos.'], 404);
        }

        $status = $this->checkDocumentStatus($documentos);

        return response()->json([
            'status' => $status,
        ], 200);
    }

    // Método para verificar el estado de los documentos

    private function checkDocumentStatus($documentos)
    {
        $currentDate = Carbon::now()->startOfDay(); // Ajusta a las 00:00:00
        $status = 'verde'; // Inicializa como verde

        foreach ($documentos as $documento) {
            $expiryDate = Carbon::parse($documento->expiry_date)->startOfDay(); // También ajusta a las 00:00:00
            $daysUntilExpiry = $expiryDate->diffInDays($currentDate);

            // Registrar las variables relevantes
            Log::info("Documento ID {$documento->id}: ", [
                'current_date' => $currentDate,
                'expiry_date' => $expiryDate,
                'days_until_expiry' => $daysUntilExpiry,
                'expiry_reminder' => $documento->expiry_reminder,
            ]);

            // Verificar si expiry_reminder es nulo
            if (is_null($documento->expiry_reminder)) {
                Log::info("Documento ID {$documento->id}: expiry_reminder es nulo, continuando.");
                continue; // Si es nulo, no afecta el estado
            }

            // Verificar si la fecha de expiración ha pasado o es hoy
            if ($expiryDate <= $currentDate) {
                Log::info("Documento ID {$documento->id}: La fecha de expiración ha pasado o es hoy, estableciendo estado 'rojo'.");
                return 'rojo'; // Vencido
            }

            // Verificar si la diferencia en días es igual a expiry_reminder
            if ($daysUntilExpiry == intval($documento->expiry_reminder)) {
                Log::info("Documento ID {$documento->id}: Días hasta la expiración es igual a expiry_reminder, estableciendo estado 'rojo'.");
                return 'rojo'; // Igual a expiry_reminder
            }

            // Verificar si está dentro de un rango de 5 días antes del expiry_reminder
            if ($daysUntilExpiry > intval($documento->expiry_reminder) && $daysUntilExpiry <= (intval($documento->expiry_reminder) + 5)) {
                Log::info("Documento ID {$documento->id}: Dentro del rango de 5 días antes del expiry_reminder, estableciendo estado 'amarillo'.");
                $status = 'amarillo'; // Hay al menos un amarillo
            }
        }

        // Si se ha encontrado algún documento amarillo, se retornará 'amarillo'
        Log::info("Ninguna condición de 'rojo' cumplida, retornando estado: {$status}.");
        return $status; // Retorna 'verde' si no hay amarillos
    }

    public function store(Request $request)
    {
        // Validar los campos requeridos
        $validatedData = $request->validate([
            'creacion' => 'required|date',
            'edicion' => 'required|date',
            'id_portal' => 'required|integer',
            'id_usuario' => 'required|integer',
            'id_empleado' => 'required|integer',

            'correo' => 'required|email',
            'curp' => 'required|string',
            'nombre' => 'required|string',
            'paterno' => 'required|string',
            'materno' => 'nullable|string',

            'puesto' => 'nullable|string',
            'telefono' => 'required|string',
            'peso' => 'nullable|numeric',
            'edad' => 'nullable|integer',
            'alergias_medicamentos' => 'nullable|string',
            'alergias_alimentos' => 'nullable|string',
            'enfermedades_cronicas' => 'nullable|string',
            'cirugias' => 'nullable|string',
            'tipo_sangre' => 'nullable|string',
            'contacto_emergencia' => 'nullable|string',
            'medicamentos_frecuentes' => 'nullable|string',
            'lesiones' => 'nullable|string',
            'otros_padecimientos' => 'nullable|string',
            'otros_padecimientos2' => 'nullable|string',
            'domicilio' => 'array', // Para recibir datos de domicilio
            'domicilio.pais' => 'nullable|string',
            'domicilio.estado' => 'nullable|string',
            'domicilio.ciudad' => 'nullable|string',
            'domicilio.colonia' => 'nullable|string',
            'domicilio.calle' => 'nullable|string',
            'domicilio.cp' => 'nullable|string',
            'domicilio.num_int' => 'nullable|string',
            'domicilio.num_ext' => 'nullable|string',
        ]);

        // Crear un registro en DomicilioEmpleado
        $domicilioData = $validatedData['domicilio'] ?? [];
        $domicilio = DomicilioEmpleado::create($domicilioData);

        // Crear un nuevo empleado
        $empleado = Empleado::create([
            'creacion' => $validatedData['creacion'],
            'id_portal' => $validatedData['id_portal'],
            'id_usuario' => $validatedData['id_usuario'],
            'id_empleado' => $validatedData['id_empleado'],

            'edicion' => $validatedData['edicion'],
            'correo' => $validatedData['correo'],
            'curp' => $validatedData['curp'],
            'nombre' => $validatedData['nombre'],
            'paterno' => $validatedData['paterno'],
            'materno' => $validatedData['materno'] ?? null,
            'puesto' => $validatedData['puesto']?? null,
            'telefono' => $validatedData['telefono'],
            'id_domicilio_empleado' => $domicilio->id, // Guardar el ID del domicilio
            'status' => 1,
            'eliminado' => 0,
        ]);

        // Crear un registro vacío en MedicalInfo con el ID del empleado
        MedicalInfo::create([
            'id_empleado' => $empleado->id,
            'creacion' => now(), // O asignar la fecha según tu lógica
            'edicion' => now(),
            // Otros campos se quedan en null
        ]);

        // Retornar una respuesta exitosa
        return response()->json([
            'message' => 'Employee registered successfully.',
            'data' => $empleado,
        ], 201);
    }
    public function checkEmail(Request $request)
    {
        // Validar que se reciba el correo en la solicitud
        $request->validate([
            'correo' => 'required|email',
        ]);

        // Verificar si el correo ya existe
        $exists = Empleado::where('correo', $request->correo)->exists();

        // Retornar respuesta
        return response()->json(['exists' => $exists]);
    }
}
