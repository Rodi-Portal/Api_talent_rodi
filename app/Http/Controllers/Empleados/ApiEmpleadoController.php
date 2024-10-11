<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de incluir esta línea
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
// Asegúrate de importar Validator

class ApiEmpleadoController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'id_portal' => 'required|integer',
        ]);

        // Verificar la conexión actual
        $currentConnection = DB::getDefaultConnection();
        $currentDatabase = DB::connection($currentConnection)->getDatabaseName();

        // Log para depuración
        \Log::info("Conexión actual: $currentConnection");
        \Log::info("Base de datos actual: $currentDatabase");

        $id_portal = $request->input('id_portal');

        $empleados = Empleado::with('domicilioEmpleado')
            ->where('id_portal', $id_portal)
            ->get();

        return response()->json($empleados);
    }

    public function updateProfilePicture(Request $request, $id)
    {
        // Validar la entrada
        $validator = Validator::make($request->all(), [
            'foto' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'carpeta' => 'required|string',
            'currentImage' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Encontrar al empleado
        $empleado = Empleado::find($id);
        if (!$empleado) {
            return response()->json(['error' => 'Empleado no encontrado.'], 404);
        }

        // Subir la imagen
        $foto = $request->file('foto');
        $carpeta = $request->input('carpeta');
        $extension = $foto->getClientOriginalExtension(); // Obtener la extensión del archivo
        $fecha = now()->format('Ymd_His'); // Formato de fecha
        $nombreArchivo = "{$empleado->id}_{$fecha}.{$extension}"; // Formar el nombre del archivo
        $localImagePath = 'C:/laragon/www/rodi_portal';
        $prodImagePath = '/home/rodicomm/public_html/portal.rodi.com.mx';
    
        // Obtener la ruta de destino
        $destinationPath = app()->environment('produccion') 
            ? $prodImagePath . '/' . $carpeta 
            : $localImagePath . '/' . $carpeta; 
        // Determinar la ruta de destino según el entorno
       

        // Eliminar la imagen anterior si existe
        if ($request->input('currentImage')) {
            $currentImagePath = $destinationPath . '/' . $request->input('currentImage');
            if (file_exists($currentImagePath)) {
                unlink($currentImagePath); // Elimina la imagen anterior
            }
        }
        // Mover el archivo a la ruta de destino
        $foto->move($destinationPath, $nombreArchivo);

        // Actualizar el campo 'foto' en la base de datos
        $empleado->foto = "{$nombreArchivo}"; // Guarda la ruta relativa
        $empleado->save();

        return response()->json(['success' => 'Imagen de perfil actualizada.', 'ruta' => $empleado->foto]);
    }
}
