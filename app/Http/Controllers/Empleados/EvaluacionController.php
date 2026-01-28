<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Http\Controllers\DocumentController;
use App\Models\Evaluacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class EvaluacionController extends Controller
{
    // Método para obtener todas las evaluaciones
    public function getEvaluations(Request $request)
    {
        $request->validate([
            'id_portal'  => 'required|integer',
            'id_cliente' => 'required|integer',
        ]);

        // Obtener todas las evaluaciones asociadas al id_portal
        $evaluaciones = Evaluacion::where('id_portal', $request->input('id_portal'))
            ->where('id_cliente', $request->input('id_cliente'))
            ->where('eliminado', 0)
            ->get();
        $resultados = [];

        foreach ($evaluaciones as $evaluacion) {
            // Obtener documentos o información relacionada con la evaluación
            $status = $this->checkDocumentStatus($evaluacion);

            // Convertir la evaluación a un array y agregar el statusDocuments
            $evaluacionArray                     = $evaluacion->toArray();
            $evaluacionArray['statusEvaluacion'] = $status;

            $resultados[] = $evaluacionArray;
        }
        //Log::info('Resultados de empleados con documentos: ' . print_r($resultados, true));

        return response()->json($resultados);
    }

    private function checkDocumentStatus($documentos)
    {
        // Si $documentos es un solo documento, conviene usarlo directamente
        if (! is_array($documentos) && ! $documentos instanceof \Illuminate\Support\Collection) {
            $documentos = [$documentos]; // Convertir a un array para la iteración
        }

        if (empty($documentos)) {
            return 'verde'; // Sin documentos, consideramos como verde
        }

        $tieneRojo     = false;
        $tieneAmarillo = false;

        foreach ($documentos as $documento) {
            // Calcular diferencia de días con respecto a la fecha actual
            $diasDiferencia = $this->calcularDiferenciaDias(now(), $documento->expiry_date);

            // Comprobamos el estado del documento
            if ($documento->expiry_reminder == 0) {
                continue; // No se requiere cálculo, se considera verde
            } elseif ($diasDiferencia <= $documento->expiry_reminder || $diasDiferencia < 0) {
                // Vencido o exactamente al límite
                $tieneRojo = true;
                break; // Prioridad alta, salimos del bucle
            } elseif ($diasDiferencia > $documento->expiry_reminder && $diasDiferencia <= ($documento->expiry_reminder + 7)) {
                // Se requiere atención, se considera amarillo
                $tieneAmarillo = true;
            }
        }

        // Determinamos el estado basado en las prioridades
        if ($tieneRojo) {
            return 'rojo';
        }

        if ($tieneAmarillo) {
            return 'amarillo';
        }

        return 'verde'; // Si no hay documentos en rojo o amarillo
    }

    private function calcularDiferenciaDias($fechaActual, $fechaExpiracion)
    {
        $fechaActual     = \Carbon\Carbon::parse($fechaActual);
        $fechaExpiracion = \Carbon\Carbon::parse($fechaExpiracion);

        // Calculamos la diferencia de días
        $diferenciaDias = $fechaExpiracion->diffInDays($fechaActual);

        // Ajustamos la diferencia para que sea negativa si la fecha de expiración ya ha pasado
        return $fechaExpiracion < $fechaActual ? -$diferenciaDias : $diferenciaDias;
    }

    // Método para crear una nueva evaluación
    public function store(Request $request)
    {
        // Validar los datos de entrada
        $validator = Validator::make($request->all(), [
            'id_portal'            => 'required|integer',
            'id_usuario'           => 'nullable|integer',
            'id_cliente'           => 'nullable|integer',
            'name'                 => 'required|string|max:255',
            'numero_participantes' => 'nullable|integer',
            'departamento'         => 'nullable|string|max:250',
            'name_document'        => 'required|string|max:255',
            'description'          => 'nullable|string',
            'conclusiones'         => 'nullable|string',
            'acciones'             => 'nullable|string',
            'expiry_date'          => 'required|date',
            'expiry_reminder'      => 'nullable|integer',
            'origen'               => 'nullable|integer',
            'file'                 => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120', // Validación del archivo
            'creacion'             => 'required|date',
            'edicion'              => 'required|date',
        ]);
        Log::info('Datos recibidos en el store: ' . print_r($request->all(), true));

        if ($validator->fails()) {
            Log::error('Errores de validación:', $validator->errors()->toArray());
            return response()->json($validator->errors(), 422);
        }

        // Preparar el nombre del archivo para la subida
        $origen        = $request->input('origen');
        $randomString  = $this->generateRandomString();                        // Generar cadena aleatoria
        $fileExtension = $request->file('file')->getClientOriginalExtension(); // Obtener extensión del archivo
        $newFileName   = "{$request->input('id_usuario')}_{$randomString}_{$origen}.{$fileExtension}";

        // Preparar la solicitud para la subida del archivo
        $uploadRequest = new Request();
        $uploadRequest->files->set('file', $request->file('file'));
        $uploadRequest->merge([
            'file_name' => $newFileName,
            'carpeta'   => '_evaluacionesPortal', // Cambia esto a tu carpeta deseada
        ]);

                                                                                     // Llamar a la función de upload
        $uploadResponse = app(DocumentController::class)->uploadZip($uploadRequest); // Cambia el nombre del controlador según sea necesario

        // Verificar si la subida fue exitosa
        if ($uploadResponse->getStatusCode() !== 200) {
            return response()->json(['error' => 'Error al subir el documento.'], 500);
        }

        // Crear un nuevo registro en la base de datos
        $evaluacion = Evaluacion::create([
            'id_portal'            => $request->input('id_portal'),
            'id_usuario'           => $request->input('id_usuario'),
            'id_cliente'           => $request->input('id_cliente'),
            'name'                 => $request->input('name'),
            'numero_participantes' => $request->input('numero_participantes'),
            'departamento'         => $request->input('departamento'),
            'name_document'        => $newFileName,
            'description'          => $request->input('description'),
            'conclusiones'         => $request->input('conclusiones'),
            'acciones'             => $request->input('acciones'),
            'expiry_date'          => $request->input('expiry_date'),
            'expiry_reminder'      => $request->input('expiry_reminder'),
            'origen'               => $origen,
            'creacion'             => $request->input('creacion'),
            'edicion'              => $request->input('edicion'),
        ]);

        // Log para verificar la evaluación registrada
        Log::info('Evaluación registrada:', ['evaluacion' => $evaluacion]);

        // Devolver una respuesta exitosa
        return response()->json([
            'message'    => 'Evaluación agregada exitosamente.',
            'evaluacion' => $evaluacion,
        ], 201);
    }

    // Método para obtener una evaluación específica
    public function show($id)
    {
        $evaluacion = Evaluacion::findOrFail($id);

        return response()->json($evaluacion);
    }

    // Método para actualizar una evaluación
    public function update(Request $request, $id)
    {
        Log::info('Evaluacion update', [
            'id'      => $id,
            'hasFile' => $request->hasFile('file'),
        ]);

        $rules = [
            'name'                 => 'string|max:255',
            'numero_participantes' => 'integer|min:1',
            'departamento'         => 'string|max:255',
            'description'          => 'nullable|string',
            'expiry_date'          => 'date',
            'expiry_reminder'      => 'nullable|integer|in:0,1,7,15,30',
            'conclusiones'         => 'string',
            'acciones'             => 'string',
            'edicion'              => 'nullable|date_format:Y-m-d H:i:s',
            'file'                 => 'nullable|file|max:10240',
            'eliminado'            => 'integer', // 10 MB
        ];
        $data = $request->validate($rules);

        $eval = \App\Models\Evaluacion::findOrFail($id);

        // --- Entorno y rutas base desde .env ---
        // Nota: Laravel usa 'production' (en inglés). Incluyo 'produccion' por si acaso.
        $isProd = app()->environment(['production', 'produccion']);

        $baseFs = rtrim(
            $isProd
                ? config('paths.prod_images')
                : config('paths.local_images'),
            '/\\'
        );

        $baseUrl = rtrim(
            $isProd
                ? config('paths.prod_images_url')
                : config('paths.local_images_url'),
            '/'
        );

                                               // Directorio final
        $targetDirRel = '_evaluacionesPortal'; // relativo (para BD)
        $targetDirFs  = $baseFs . DIRECTORY_SEPARATOR . $targetDirRel;
        $targetDirUrl = $baseUrl . '/' . $targetDirRel;

        if (! is_dir($targetDirFs)) {
            @mkdir($targetDirFs, 0755, true);
        }

                                                    // --- Guardamos referencia del archivo anterior (si existía) ---
        $oldRelPath = $eval->path_document ?? null; // p.ej. "_evaluaciones/archivo.pdf"
        $oldAbsPath = null;
        if ($oldRelPath) {
            $oldRelNormalized = ltrim(str_replace(['\\'], '/', $oldRelPath), '/');
            $oldAbsPath       = $baseFs . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $oldRelNormalized);
        }

        // --- Si viene archivo, guardar y reemplazar ---
        if ($request->hasFile('file')) {
            $file = $request->file('file');

            // Nombre final: eval_<id>_<random>.<ext>
            $ext      = strtolower($file->getClientOriginalExtension());
            $filename = 'eval_' . $eval->id . '_' . Str::random(8) . ($ext ? '.' . $ext : '');

            // Mover nuevo archivo
            $destFs = $targetDirFs . DIRECTORY_SEPARATOR . $filename;
            $file->move($targetDirFs, $filename);

            // Intentar borrar el anterior (si existía)
            if ($oldAbsPath && is_file($oldAbsPath)) {
                @unlink($oldAbsPath);
            }

                                                                      // Actualizar referencias en $data (BD)
            $data['name_document'] = $filename;                       // solo nombre
            $data['path_document'] = $targetDirRel . '/' . $filename; // ruta relativa
            $data['url_document']  = $targetDirUrl . '/' . $filename; // URL pública (si aplica)

            // (Opcional) permisos/propietario; ignorará en Windows
            @chmod($destFs, 0664);
            @chgrp($destFs, 'rodicomm');
        }

        // Campos que NO quieres actualizar si llegan del front
        unset($data['creacion']);

        $eval->fill($data);
        $eval->save();

        return response()->json($eval);
    }

    // Método para eliminar una evaluación
    public function destroy($id)
    {
        $evaluacion = Evaluacion::findOrFail($id);
        $evaluacion->delete();

        return response()->json(null, 204);
    }
    private function generateRandomString($length = 10)
    {
        return substr(str_shuffle(str_repeat("0123456789abcdefghijklmnopqrstuvwxyz", ceil($length / 10))), 1, $length);
    }
}
