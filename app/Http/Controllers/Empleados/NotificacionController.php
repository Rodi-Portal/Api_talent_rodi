<?php

namespace App\Http\Controllers\Empleados;
use App\Http\Controllers\Controller; // Asegúrate de agregar esto


use App\Models\Notificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;


class NotificacionController extends Controller
{
    /**
     * Consultar las notificaciones de un cliente y portal específicos.
     */
    public function consultar($id_portal, $id_cliente)
    {
        // Consultar la notificación
        $notificacion = Notificacion::where('id_portal', $id_portal)
            ->where('id_cliente', $id_cliente)
            ->first();
    
        // Si la notificación no existe, devolver un mensaje indicando que no hay datos
        if (!$notificacion) {
            return response()->json(['message' => 'No se encontraron notificaciones para este cliente y portal.'], 404);
        }
    
        // Si la notificación existe, devolverla
        return response()->json(['notificacion' => $notificacion], 200);
    }

    /**
     * Guardar o actualizar las notificaciones.
     */
    public function guardar(Request $request)
    {

         // Verifica si los datos están llegando
         Log::debug('Datos recibidos en guardar:', $request->all());        // Validar que los parámetros necesarios estén presentes
        $validator = Validator::make($request->all(), [
            'id_portal' => 'required|integer', // Aseguramos que id_portal existe
            'id_cliente' => 'required|integer', // Aseguramos que id_cliente existe
            'correo' => 'nullable|boolean',
            'correo1' => 'nullable|string',
            'correo2' => 'nullable|string',
            'cursos' => 'nullable|boolean',
            'evaluaciones' => 'nullable|boolean',
            'expediente' => 'nullable|boolean',
            'horarios' => 'nullable|string',
            'ladaSeleccionada' => 'nullable|string',
            'ladaSeleccionada2' => 'nullable|string',
            'notificacionesActivas' => 'nullable|boolean',
            'status' => 'nullable|boolean',
            'telefono1' => 'nullable|string',
            'telefono2' => 'nullable|string',
            'whatsapp' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Buscar si ya existe un registro con el mismo id_portal y id_cliente
        $notificacion = Notificacion::where('id_portal', $request->id_portal)
            ->where('id_cliente', $request->id_cliente)
            ->first();

        // Si existe, actualizar los datos
        if ($notificacion) {
            $notificacion->update($request->all());
            return response()->json(['message' => 'Notificación actualizada correctamente.', 'notificacion' => $notificacion], 200);
        }

        // Si no existe, crear uno nuevo
        $notificacion = Notificacion::create($request->all());
        return response()->json(['message' => 'Notificación creada correctamente.', 'notificacion' => $notificacion], 201);
    }
}
