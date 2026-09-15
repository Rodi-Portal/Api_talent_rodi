<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller; // Asegúrate de incluir esta línea
use App\Models\AntidopingPaquete;
use App\Models\Empleado;
use App\Services\Documents\EmployeePhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    public function updateProfilePicture(
        Request $request,
        $id,
        EmployeePhotoPathService $photoPaths
    ) {
        $validator = Validator::make($request->all(), [
            'foto'         => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'carpeta'      => 'required|string',
            'currentImage' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $empleado = Empleado::find($id);

        if (! $empleado) {
            return response()->json([
                'error' => 'Empleado no encontrado.',
            ], 404);
        }

        $foto = $request->file('foto');

        $extension = strtolower(
            $foto->getClientOriginalExtension()
        );

        $fecha = now()->format('Ymd_His');

        $nombreArchivo = "{$empleado->id}_{$fecha}.{$extension}";

        /*
         * Las fotos nuevas se escriben únicamente en storagetalentsafe.
         * empleados.foto conserva sólo el nombre del archivo.
         */
        $destinationPath = $photoPaths->ensureActiveDirectory(
            $empleado
        );

        $previousFilename = $empleado->foto;

        try {
            $foto->move(
                $destinationPath,
                $nombreArchivo
            );

            $empleado->foto = $nombreArchivo;
            $empleado->save();
        } catch (\Throwable $e) {
            $newFilePath = $photoPaths->activePath(
                $empleado,
                $nombreArchivo
            );

            if (is_file($newFilePath)) {
                @unlink($newFilePath);
            }

            throw $e;
        }

        /*
         * Sólo después de actualizar correctamente la BD archivamos
         * una foto anterior que ya perteneciera al almacenamiento nuevo.
         *
         * Las fotos legacy de _perfilEmpleado permanecen intactas
         * durante la transición.
         */
        if (
            ! empty($previousFilename)
            && $previousFilename !== $nombreArchivo
        ) {
            try {
                $photoPaths->archiveActivePhoto(
                    $empleado,
                    $previousFilename
                );
            } catch (\Throwable $e) {
                Log::warning(
                    'No fue posible archivar la foto de perfil reemplazada.',
                    [
                        'employee_id' => (int) $empleado->id,
                        'filename'    => $previousFilename,
                        'error'       => $e->getMessage(),
                    ]
                );
            }
        }

        return response()->json([
            'success' => 'Imagen de perfil actualizada.',
            'ruta'    => $nombreArchivo,
        ]);
    }
    public function getProfilePicture(
        $filename,
        EmployeePhotoPathService $photoPaths
    ) {
        $filePath = $photoPaths->resolveReadablePathByFilename(
            $filename
        );

        if ($filePath === null) {
            return response()->noContent();
        }

        return response()->file($filePath, [
            'Content-Type'  => mime_content_type($filePath),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
    public function getMyProfilePicture(
        Request $request,
        EmployeePhotoPathService $photoPaths
    ) {
        $authenticatedEmployee = auth('empleado')->user();

        if (! $authenticatedEmployee) {
            return response()->json([
                'error' => 'Empleado no autenticado',
            ], 401);
        }

        $empleado = Empleado::find(
            $authenticatedEmployee->id
        );

        if (! $empleado) {
            return response()->json([
                'error' => 'Empleado no encontrado',
            ], 404);
        }

        $filePath = $photoPaths->resolveReadablePath(
            $empleado,
            $empleado->foto
        );

        if ($filePath === null) {
            return response()->json([
                'error' => 'Imagen no disponible',
            ], 404);
        }

        return response()->file($filePath, [
            'Content-Type'  => mime_content_type($filePath),
            'Cache-Control' => 'private, max-age=604800, immutable',
            'Vary'          => 'Authorization',
        ]);
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
            ? config('paths.prod_images')
            : config('paths.local_images');

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
            'Content-Type'            => $mimeType,
            'Content-Disposition'     => 'inline; filename="' . $archivo . '"',
            'Content-Security-Policy' => "frame-ancestors 'self' https://portal.talentsafecontrol.com",
        ]);

    }

}