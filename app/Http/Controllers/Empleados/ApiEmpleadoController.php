<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de incluir esta línea
use App\Models\AntidopingPaquete;
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
        $currentDatabase   = DB::connection($currentConnection)->getDatabaseName();

        // Log para depuración

        $id_portal = $request->input('id_portal');

        $empleados = Empleado::with('domicilioEmpleado')
            ->where('id_portal', $id_portal)
            ->get();

        return response()->json($empleados);
    }
/*
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
        $destinationPath = app()->environment(['production', 'produccion'])
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
    }   */
    public function updateProfilePicture(Request $request, $id)
    {
        // Validar la entrada
        $validator = Validator::make($request->all(), [
            'foto'         => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'carpeta'      => 'required|string',
            'currentImage' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Encontrar al empleado
        $empleado = Empleado::find($id);
        if (! $empleado) {
            return response()->json(['error' => 'Empleado no encontrado.'], 404);
        }

        // Determinar ruta base según entorno
        $rutaBase = app()->environment('production')
            ? env('PROD_IMAGE_PATH')
            : env('LOCAL_IMAGE_PATH');

        $urlBase = app()->environment('production')
            ? env('PROD_IMAGE_URL')
            : env('LOCAL_IMAGE_URL');

        $foto          = $request->file('foto');
        $carpeta       = $request->input('carpeta');
        $extension     = $foto->getClientOriginalExtension();
        $fecha         = now()->format('Ymd_His');
        $nombreArchivo = "{$empleado->id}_{$fecha}.{$extension}";

        $destinationPath = $rutaBase . '/' . $carpeta;

        // Eliminar imagen anterior
        if ($request->input('currentImage')) {
            $currentImagePath = $destinationPath . '/' . $request->input('currentImage');
            if (file_exists($currentImagePath)) {
                unlink($currentImagePath);
            }
        }

        // Mover archivo
        $foto->move($destinationPath, $nombreArchivo);

        // Guardar ruta relativa en DB
        $empleado->foto = $nombreArchivo;
        $empleado->save();

        return response()->json([
            'success' => 'Imagen de perfil actualizada.',
            'ruta'    => $nombreArchivo, // solo el nombre
        ]);
    }
    public function getProfilePicture($filename)
    {
        $carpeta = '_perfilEmpleado';

        $rutaBase = app()->environment('production')
            ? env('PROD_IMAGE_PATH')
            : env('LOCAL_IMAGE_PATH');

        $filePath = $rutaBase . '/' . $carpeta . '/' . $filename;

        if (! file_exists($filePath)) {
            return response()->json(['error' => 'Imagen no encontrada'], 404);
        }

        return response()->file($filePath);
    }

    public function getAntidopinPaquetes()
    {
                                                             // Obtiene todos los paquetes de antidoping
        $paquetes = AntidopingPaquete::where('eliminado', 0) // Filtra solo los no eliminados
            ->get(['id', 'nombre', 'sustancias', 'conjunto']);   // Especifica qué campos deseas obtener

        return response()->json($paquetes); // Retorna los datos como JSON
    }

    public function verDocumento($carpeta, $archivo)
    {
        $env = config('app.env');

        // Determina la ruta base según el entorno
        $basePath = $env === 'production'
            ? env('PROD_IMAGE_PATH')
            : env('LOCAL_IMAGE_PATH');

        // Evita ataques de path traversal
        $carpeta = basename($carpeta);
        $archivo = basename($archivo);

        $filePath = "{$basePath}/{$carpeta}/{$archivo}";

        if (! file_exists($filePath)) {
            return response()->json(['error' => 'Archivo no encontrado'], 404);
        }

        $mimeType = mime_content_type($filePath);

        // Devuelve el archivo con el Content-Type correcto
        return response()->file($filePath, [
            'Content-Type'                => $mimeType,
            // Permite que se use desde cualquier dominio (CORS)
            'Access-Control-Allow-Origin' => '*',
            'Content-Disposition'         => 'inline; filename="' . $archivo . '"', // inline permite ver PDF en navegador
        ]);
    }

}
