<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de agregar esto

use App\Models\Comunicacion\NotificacionesRecordatorios;
use App\Models\Notificacion;
use App\Models\NotificacionExempleo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class NotificacionController extends Controller
{
    /**
     * Consultar las notificaciones de un cliente y portal específicos.
     */
    public function consultar($id_portal, $id_cliente, $status)
    {
        if ($status == 2) {
            $notificacion = Notificacion::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('notificacionesActivas', $status)
                ->first();
        } elseif ($status == 1) {
            $notificacion = Notificacion::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('status', $status)
                ->first();
        }
        // Si la notificación no existe, devolver un mensaje indicando que no hay datos
        if (! $notificacion) {
            return response()->json(['message' => 'No se encontraron notificaciones para este cliente y portal.'], 404);
        }

        // Si la notificación existe, devolverla
        return response()->json(['notificacion' => $notificacion], 200);
    }
    public function consultarExempleo($id_portal, $id_cliente, $status)
    {
        if ($status == 2) {
            $notificacion = NotificacionExempleo::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('notificacionesActivas', $status)
                ->first();
        } elseif ($status == 1) {
            $notificacion = NotificacionExempleo::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('status', $status)
                ->first();
        }
        // Si la notificación no existe, devolver un mensaje indicando que no hay datos
        if (! $notificacion) {
            return response()->json(['message' => 'No se encontraron notificaciones para este cliente y portal.'], 404);
        }

        // Si la notificación existe, devolverla
        return response()->json(['notificacion' => $notificacion], 200);
    }

    public function consultarRecordatorio($id_portal, $id_cliente, $status)
    {
        if ($status == 2) {
            $notificacion = NotificacionesRecordatorios::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('notificacionesActivas', $status)
                ->first();
        } elseif ($status == 1) {
            $notificacion = NotificacionesRecordatorios::where('id_portal', $id_portal)
                ->where('id_cliente', $id_cliente)
                ->where('status', $status)
                ->first();
        }
        // Si la notificación no existe, devolver un mensaje indicando que no hay datos
        if (! $notificacion) {
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
        Log::debug('Datos recibidos en guardar:', $request->all()); // Validar que los parámetros necesarios estén presentes
        $validator = Validator::make($request->all(), [
            'id_portal'             => 'required|integer', // Aseguramos que id_portal existe
            'id_cliente'            => 'required|integer', // Aseguramos que id_cliente existe
            'correo'                => 'nullable|boolean',
            'correo1'               => 'nullable|string',
            'correo2'               => 'nullable|string',
            'cursos'                => 'nullable|boolean',
            'evaluaciones'          => 'nullable|boolean',
            'expediente'            => 'nullable|boolean',
            'horarios'              => 'nullable|string',
            'ladaSeleccionada'      => 'nullable|string',
            'ladaSeleccionada2'     => 'nullable|string',
            'notificacionesActivas' => 'nullable|integer',
            'status'                => 'nullable|boolean',
            'telefono1'             => 'nullable|string',
            'telefono2'             => 'nullable|string',
            'whatsapp'              => 'nullable|boolean',
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

    public function guardarExempleados(Request $request)
    {

                                                                    // Verifica si los datos están llegando
        Log::debug('Datos recibidos en guardar:', $request->all()); // Validar que los parámetros necesarios estén presentes
        $validator = Validator::make($request->all(), [
            'id_portal'             => 'required|integer', // Aseguramos que id_portal existe
            'id_cliente'            => 'required|integer', // Aseguramos que id_cliente existe
            'correo'                => 'nullable|boolean',
            'correo1'               => 'nullable|string',
            'correo2'               => 'nullable|string',
            'exempleo'              => 'nullable|boolean',
            'horarios'              => 'nullable|string',
            'ladaSeleccionada'      => 'nullable|string',
            'ladaSeleccionada2'     => 'nullable|string',
            'notificacionesActivas' => 'nullable|integer',
            'status'                => 'nullable|boolean',
            'telefono1'             => 'nullable|string',
            'telefono2'             => 'nullable|string',
            'whatsapp'              => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        // Buscar si ya existe un registro con el mismo id_portal y id_cliente
        $notificacion = NotificacionExempleo::where('id_portal', $request->id_portal)
            ->where('id_cliente', $request->id_cliente)
            ->first();

        // Si existe, actualizar los datos
        if ($notificacion) {
            $notificacion->update($request->all());
            return response()->json(['message' => 'Notificación actualizada correctamente.', 'notificacion' => $notificacion], 200);
        }

        // Si no existe, crear uno nuevo
        $notificacion = NotificacionExempleo::create($request->all());
        return response()->json(['message' => 'Notificación creada correctamente.', 'notificacion' => $notificacion], 201);
    }
    public function guardarRecordatorios(Request $request)
    {
        Log::debug('Datos recibidos en guardar:', $request->all());

        // 0) Normaliza nombres para que coincidan con columnas reales
        //    (si ya vienen en el nombre correcto, se respetan)
        $merge = [];
        if ($request->has('notificacionesActivas') && ! $request->has('notificaciones_activas')) {
            $merge['notificaciones_activas'] = $request->input('notificacionesActivas');
        }
        if ($request->has('ladaSeleccionada') && ! $request->has('lada1')) {
            $merge['lada1'] = $request->input('ladaSeleccionada');
        }
        if ($request->has('ladaSeleccionada2') && ! $request->has('lada2')) {
            $merge['lada2'] = $request->input('ladaSeleccionada2');
        }
        if (! empty($merge)) {
            $request->merge($merge);
        }

        // 1) Validación (usa nombres de columna)
        $validator = Validator::make($request->all(), [
            'id_portal'              => 'required|integer',
            'id_cliente'             => 'required|integer',
            'correo'                 => 'nullable|boolean',
            'correo1'                => 'nullable|string',
            'correo2'                => 'nullable|string',
            'horarios'               => 'nullable|string',
            'lada1'                  => 'nullable|string',
            'lada2'                  => 'nullable|string',
            'notificaciones_activas' => 'nullable|integer|in:0,1',
            'status'                 => 'nullable|integer',
            'telefono1'              => 'nullable|string',
            'telefono2'              => 'nullable|string',
            'whatsapp'               => 'nullable|boolean',
            'id_usuario'             => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        $where = [
            'id_portal'  => (int) $request->id_portal,
            'id_cliente' => (int) $request->id_cliente,
        ];

        // 2) Campos permitidos (coinciden con columnas reales)
        $incoming = $request->only([
            'correo', 'correo1', 'correo2',
            'horarios',
            'lada1', 'lada2',
            'notificaciones_activas', 'status',
            'telefono1', 'telefono2',
            'whatsapp',
            'id_usuario',
        ]);

        // 3) Solo-flags: si lo único que vino es activar/desactivar, no toques nada más
        $flagKeys  = ['notificaciones_activas', 'status'];
        $onlyFlags = array_intersect_key($incoming, array_flip($flagKeys));
        if (count($incoming) === count($onlyFlags) && ! empty($onlyFlags)) {
            $record = NotificacionesRecordatorios::updateOrCreate($where, $onlyFlags);
            return response()->json(['message' => 'Estado actualizado', 'notificacion' => $record], 200);
        }

        // 4) Evita pisar con null/"" (pero conserva 0/false)
        $data = array_filter($incoming, function ($v) {
            return ! is_null($v) && $v !== '';
        });

        $record = NotificacionesRecordatorios::updateOrCreate($where, $data);

        return response()->json(['message' => 'Notificación guardada', 'notificacion' => $record], 200);
    }

}
