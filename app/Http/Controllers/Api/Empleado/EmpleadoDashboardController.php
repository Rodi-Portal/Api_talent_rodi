<?php
namespace App\Http\Controllers\Api\Empleado;

use App\Http\Controllers\Controller;
use App\Models\CalendarioEvento;
use App\Models\CursoEmpleado;
use App\Models\DocumentEmpleado;
use App\Models\Empleado;
use App\Models\ExamEmpleado;
use Illuminate\Http\Request;

class EmpleadoDashboardController extends Controller
{

    public function dashboard(Request $request)
    {
        $authEmpleado = $request->user();

        if (! $authEmpleado) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $empleado = Empleado::where('id', $authEmpleado->id)->first();

        if (! $empleado) {
            return response()->json([
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        /* =========================
           DOCUMENTOS
        ========================= */

        $documents = DocumentEmpleado::with('documentOption')
            ->where('employee_id', $empleado->id)
            ->get()
            ->map(function ($doc) {

                $name = $doc->id_opcion
                    ? optional($doc->documentOption)->name
                    : $doc->nameDocument;

                return [
                    'id'              => $doc->id,
                    'name'            => $name,
                    'expiry_date'     => $doc->expiry_date,
                    'document'        => $doc->name,
                    'expiry_reminder' => $doc->expiry_reminder,
                    'status'          => $doc->status,
                    'file_url'        => url("/api/empleado/compliance/documento/{$doc->id}/ver")];
            });

        /* =========================
           CURSOS
        ========================= */

        $cursos = CursoEmpleado::with('documentOption')
            ->where('employee_id', $empleado->id)
            ->get()
            ->map(function ($curso) {

                $name = $curso->id_opcion
                    ? optional($curso->documentOption)->name
                    : $curso->nameDocument;

                return [

                    'id'              => $curso->id,
                    'name'            => $name,
                    'document'        => $curso->name,
                    'expiry_date'     => $curso->expiry_date,
                    'expiry_reminder' => $curso->expiry_reminder,
                    'status'          => $curso->status,
                    'file_url'        => url("/api/empleado/compliance/curso/{$curso->id}/ver"),
                ];
            });

        /* =========================
           EXÁMENES
        ========================= */
        $examenes = ExamEmpleado::with('examOption')
            ->where('employee_id', $empleado->id)
            ->whereNull('id_candidato')
            ->get()
            ->map(function ($exam) {

                $name = $exam->id_opcion
                    ? optional($exam->examOption)->name
                    : $exam->nameDocument;

                return [
                    'id'              => $exam->id,
                    'name'            => $name,
                    'document'        => $exam->name,
                    'expiry_date'     => $exam->expiry_date,
                    'expiry_reminder' => $exam->expiry_reminder,
                    'status'          => $exam->status,
                    'file_url'        => url("/api/empleado/compliance/examen/{$exam->id}/ver"),
                ];
            });
/* =========================
   INCIDENCIAS
========================= */

        $incidencias = CalendarioEvento::where('id_empleado', $empleado->id)
            ->where('eliminado', 0)
            ->where('estado', 1)
            ->orderByDesc('inicio')
            ->get()
            ->map(function ($evento) {

                return [
                    'id'         => $evento->id,
                    'tipo'       => $evento->id_tipo,
                    'fecha'      => $evento->inicio,
                    'fechaFin'   => $evento->fin,
                    'dias'       => $evento->dias_evento,
                    'comentario' => $evento->descripcion,
                    'archivo'    => $evento->archivo,
                    'estado'     => $evento->estado, // puedes ajustar si tienes lógica de aprobación
                ];
            });
        /* =========================
           RESPUESTA
        ========================= */

        return response()->json([

            'profile'            => [
                'id'             => $empleado->id,
                'nombreCompleto' => trim(
                    $empleado->nombre . ' ' .
                    $empleado->paterno . ' ' .
                    $empleado->materno
                ),
                'photo'          => $empleado->foto,
            ],

            'laboral'            => [
                'puesto'       => $empleado->puesto,
                'departamento' => $empleado->departamento,
                'fechaIngreso' => $empleado->fecha_ingreso ?? $empleado->creacion,
            ],

            'documents_empleado' => $documents,
            'cursos_empleado'    => $cursos,
            'examenes_empleado'  => $examenes,

            'incidencias'        => $incidencias,

        ]);
    }

    public function verCompliance(Request $request, $tipo, $id)
    {
        $authEmpleado = $request->user();

        if (! $authEmpleado) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        $empleado = Empleado::where('id', $authEmpleado->id)->first();

        if (! $empleado) {
            return response()->json([
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        switch ($tipo) {

            case 'documento':
                $item = DocumentEmpleado::where('id', $id)
                    ->where('employee_id', $empleado->id)
                    ->first();

                $folder     = '_documentEmpleado';
                $nombreTipo = 'Documento';
                break;

            case 'curso':
                $item = CursoEmpleado::where('id', $id)
                    ->where('employee_id', $empleado->id)
                    ->first();

                $folder     = '_cursos';
                $nombreTipo = 'Curso';
                break;

            case 'examen':
                $item = ExamEmpleado::where('id', $id)
                    ->where('employee_id', $empleado->id)
                    ->whereNull('id_candidato')
                    ->first();

                $folder     = '_examEmpleado';
                $nombreTipo = 'Examen';
                break;

            default:
                return response()->json([
                    'message' => 'Tipo no soportado',
                ], 404);
        }

        if (! $item) {
            return response()->json([
                'message' => $nombreTipo . ' no encontrado',
            ], 404);
        }

        if (! $item->name) {
            return response()->json([
                'message' => $nombreTipo . ' no tiene archivo',
            ], 404);
        }

        $basePath = app()->environment('production')
            ? config('paths.prod_images')
            : config('paths.local_images');

        $fullPath = rtrim($basePath, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $folder
        . DIRECTORY_SEPARATOR
        . $item->name;

        \Log::info('COMPLIANCE FILE PATH', [
            'tipo'     => $tipo,
            'id'       => $id,
            'archivo'  => $item->name,
            'folder'   => $folder,
            'basePath' => $basePath,
            'fullPath' => $fullPath,
            'exists'   => file_exists($fullPath),
        ]);

        if (! file_exists($fullPath)) {
            return response()->json([
                'message' => 'Archivo no encontrado',
            ], 404);
        }

        return response()->file($fullPath);
    }
}
