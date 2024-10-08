<?php

namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\DocumentEmpleado;
use App\Models\DocumentOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DocumentOptionController extends Controller
{
    public function index(Request $request)
    {
        // Verificar si se recibió id_portal
        $id_portal = $request->input('id_portal');

        // Construir la consulta
        $query = DocumentOption::query();

        // Caso 1: Si se proporciona id_portal
        if ($id_portal) {
            $query->where(function ($q) use ($id_portal) {
                $q->where('id_portal', $id_portal)
                    ->orWhereNull('id_portal');
            });
        } else {
            // Caso 2: Si no hay id_portal, obtener solo opciones donde id_portal es nulo
            $query->whereNull('id_portal');
        }

        // Ejecutar la consulta para obtener los resultados
        $documentOptions = $query->ordered()->get();

        // Si hay un campo name en el request, filtrar y devolver solo el id
        if ($request->has('name')) {
            $name = $request->input('name');
            // Filtrar por nombre
            $filtered = $documentOptions->filter(function ($option) use ($name) {
                return stripos($option->name, $name) !== false; // Comparación no sensible a mayúsculas
            });

            // Si hay coincidencias, devolver solo el id del primero encontrado
            if ($filtered->isNotEmpty()) {
                return response()->json($filtered->pluck('id'));
            }

            // Si no hay coincidencias
            return response()->json([], 404);
        }

        // Devolver todos los resultados si no se busca por nombre
        return response()->json($documentOptions);
    }

    // verificar  si existe  la opcion
    public function buscar_insertar_opcion(Request $request)
    {
        // Obtener los parámetros de la solicitud
        $id_portal = $request->input('id_portal');
        $name = $request->input('name');

        // Buscar el documento existente
        $documentOption = DocumentOption::where(function ($query) use ($id_portal, $name) {
            $query->where('id_portal', $id_portal)
                ->orWhere('id_portal', null);
        })
            ->where('name', $name)
            ->first();

        // Si existe, devolver su ID
        if ($documentOption) {
            return response()->json(['id_opciones' => $documentOption->id], 200);
        }

        // Si no existe, crear un nuevo registro
        $newDocumentOption = DocumentOption::create([
            'name' => $name,
            'type' => 'default_type', // Ajusta según sea necesario
            'id_portal' => $id_portal,
        ]);

        // Devolver el ID del nuevo registro
        return response()->json(['id_opciones' => $newDocumentOption->id], 201);
    }

    public function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat($x = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length / strlen($x)))), 1, $length);
    }

    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'employee_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'expiry_date' => 'nullable|date',
            'expiry_reminder' => 'nullable|integer',
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048',
            'creacion' => 'required|string',
            'edicion' => 'required|string',
            'id_portal' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        // Log de los datos recibidos
        Log::info('Datos recibidos en el store:', $request->all());

        // Verificar si se recibió un archivo
        if (!$request->hasFile('file')) {
            Log::error('No se recibió ningún archivo en la solicitud.');
            return response()->json(['error' => 'No se recibió ningún archivo.'], 400);
        }

        // Asegurarse de que el archivo es válido
        if (!$request->file('file')->isValid()) {
            Log::error('El archivo recibido no es válido.');
            return response()->json(['error' => 'El archivo recibido no es válido.'], 400);
        }

        // Llamar a buscar_insertar_opcion para obtener el id_opciones
        $opcionRequest = new Request([
            'id_portal' => $request->input('id_portal'),
            'name' => $request->input('name'),
            'creacion' => $request->input('creacion'),
        ]);

        $opcionResponse = $this->buscar_insertar_opcion($opcionRequest);
        $idOpcion = json_decode($opcionResponse->getContent())->id_opciones;

        // Log para verificar el ID obtenido
        Log::info('ID de opción obtenido:', ['id_opcion' => $idOpcion]);

        // Preparar la solicitud para la subida del archivo
        $employeeId = $request->input('employee_id');
        $randomString = $this->generateRandomString(); // Generar la cadena aleatoria
        $fileExtension = $request->file('file')->getClientOriginalExtension(); // Obtener la extensión del archivo

        // Crear el nuevo nombre de archivo
        $newFileName = "{$employeeId}_{$randomString}.{$fileExtension}";

        // Preparar la solicitud para la subida del archivo
        $uploadRequest = new Request();
        $uploadRequest->files->set('file', $request->file('file'));
        $uploadRequest->merge([
            'file_name' => $newFileName,
            'carpeta' => $request->input('carpeta'),
        ]);
        $uploadResponse = app(DocumentController::class)->upload($uploadRequest);

        // Verificar si la subida fue exitosa
        if ($uploadResponse->getStatusCode() !== 200) {
            return response()->json(['error' => 'Error al subir el documento.'], 500);
        }

        // Log para verificar el ID antes de la creación
        Log::info('Preparándose para crear DocumentEmpleado con id_opcion:', ['id_opcion' => $idOpcion]);

        // Crear un nuevo registro en la base de datos
        $documentEmpleado = DocumentEmpleado::create([
            'creacion' => $request->input('creacion'),
            'edicion' => $request->input('edicion'),
            'employee_id' => $request->input('employee_id'),
            'name' => $newFileName,
            'id_opcion' => $idOpcion, // Aquí se usa el ID correcto
            'description' => $request->input('description'),
            'expiry_date' => $request->input('expiry_date'),
            'expiry_reminder' => $request->input('expiry_reminder'),
        ]);

        // Log para verificar el documento registrado
        Log::info('Documento registrado:', ['document' => $documentEmpleado]);

        // Devolver una respuesta exitosa
        return response()->json([
            'message' => 'Documento agregado exitosamente.',
            'document' => $documentEmpleado,
        ], 201);
    }

    public function getDocumentsByEmployeeId($employeeId)
    {
        // Validar el ID del empleado
        if (!is_numeric($employeeId)) {
            return response()->json(['error' => 'ID de empleado no válido.'], 422);
        }

        // Buscar documentos del empleado junto con las opciones
        $documentos = DocumentEmpleado::with('documentOption')->where('employee_id', $employeeId)->get();

        // Log para verificar los documentos encontrados

        // Verificar si se encontraron documentos
        if ($documentos->isEmpty()) {
            return response()->json(['message' => 'No se encontraron documentos para el empleado.'], 404);
        }

        // Mapear los documentos para incluir el nombre de la opción
        $documentosConOpciones = $documentos->map(function ($documento) {
            return [
                'id' => $documento->id,
                'nameDocument' => $documento->name,
                'optionName' => $documento->documentOption ? $documento->documentOption->name : null,
                'description' => $documento->description,
                'upload_date' => \Carbon\Carbon::parse($documento->upload_date)->format('Y-m-d'),
                'expiry_date' => $documento->expiry_date,
                'expiry_reminder' => $documento->expiry_reminder,
                // Agrega otros campos que necesites
            ];
        });

        // Devolver los documentos
        return response()->json(['documentos' => $documentosConOpciones], 200);
    }

    public function updateExpiration(Request $request, $id)
    {
        // Validar la solicitud
        $request->validate([
            'expiry_date' => 'required|date',
            'expiry_reminder' => 'nullable|integer|min:0',
        ]);

        // Encontrar el documento por ID
        $document = DocumentEmpleado::find($id);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Actualizar los campos necesarios
        $document->expiry_date = $request->input('expiry_date');

        // Asignar expiry_reminder, se establecerá a null si no se proporciona
        $document->expiry_reminder = $request->input('expiry_reminder', null);

        // Guardar los cambios
        $document->save();

        // Retornar una respuesta de éxito
        return response()->json(['message' => 'Document updated successfully'], 200);
    }

    public function deleteDocument(Request $request)
    {
        // Validate the request
        $request->validate([
            'id' => 'required|integer|exists:portal_main.documents_empleado,id',
            'nameDocument' => 'required|string',
        ]);

        // Find the document by ID
        $document = DocumentEmpleado::find($request->id);

        if (!$document) {
            return response()->json(['message' => 'Document not found'], 404);
        }

        // Determine the base path depending on the environment
        $basePath = env('APP_ENV') === 'local' ? env('LOCAL_IMAGE_PATH') : env('PROD_IMAGE_PATH');

        // Construct the file path
        $filePath = $basePath . '_documentEmpleado/' . $request->nameDocument;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete the document from the database
        $document->delete();

        return response()->json(['message' => 'Document deleted successfully'], 200);
    }

}
