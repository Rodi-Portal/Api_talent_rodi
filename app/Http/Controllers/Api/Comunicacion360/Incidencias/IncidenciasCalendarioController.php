<?php
namespace App\Http\Controllers\Api\Comunicacion360\Incidencias;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class IncidenciasCalendarioController extends Controller
{
    public function index(Request $request)
    {
        $datos = $request->validate([
            'id_portal'    => [
                'required',
                'integer',
                'min:1',
            ],
            'contexto'     => [
                'required',
                Rule::in(['sucursal', 'empleado']),
            ],
            'id_sucursal'  => [
                'nullable',
                'required_if:contexto,sucursal',
                'integer',
                'min:1',
            ],
            'id_empleado'  => [
                'nullable',
                'required_if:contexto,empleado',
                'integer',
                'min:1',
            ],
            'fecha_inicio' => [
                'required',
                'date_format:Y-m-d',
            ],
            'fecha_fin'    => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:fecha_inicio',
            ],
        ]);

        $idPortal = (int) $datos['id_portal'];
        $contexto = $datos['contexto'];

        $consulta = DB::connection('portal_main')
            ->table('calendario_eventos as ce')
            ->join('eventos_option as eo', 'eo.id', '=', 'ce.id_tipo')
            ->join('empleados as e', function ($join) {
                $join->on('e.id', '=', 'ce.id_empleado')
                    ->on('e.id_portal', '=', 'ce.id_portal')
                    ->where('e.status', 1)
                    ->where('e.eliminado', 0);
            })
            ->select([
                'ce.id',
                'ce.id_usuario',
                'ce.id_empleado',
                'ce.id_portal',
                'ce.id_cliente',
                'ce.inicio',
                'ce.fin',
                'ce.dias_evento',
                'ce.descripcion',
                'ce.archivo',
                'ce.id_tipo',
                'ce.tipo_incapacidad_sat',
                'ce.estado',
                'ce.estado_aprobacion',
                'ce.origen_evento',
                'ce.requiere_aprobacion',

                'eo.name as tipo_nombre',
                'eo.color as tipo_color',
                'eo.id_portal as tipo_id_portal',

                // Estas columnas faltaban
                'e.id_empleado as numero_empleado',
                'e.nombre as empleado_nombre',
                'e.paterno as empleado_paterno',
                'e.materno as empleado_materno',
                'e.departamento as empleado_departamento',
                'e.puesto as empleado_puesto',
                'e.foto as empleado_foto',
            ])
            ->where('ce.id_portal', $idPortal)
            ->where('ce.eliminado', 0)
            ->whereDate('ce.inicio', '<=', $datos['fecha_fin'])
            ->whereDate('ce.fin', '>=', $datos['fecha_inicio'])
            ->where(function ($query) use ($idPortal) {
                $query
                    ->whereNull('eo.id_portal')
                    ->orWhere('eo.id_portal', $idPortal);
            });

        if ($contexto === 'sucursal') {
            $consulta->where(
                'ce.id_cliente',
                (int) $datos['id_sucursal']
            );
        }

        if ($contexto === 'empleado') {
            $consulta->where(
                'ce.id_empleado',
                (int) $datos['id_empleado']
            );
        }

        $eventos = $consulta
            ->orderBy('ce.inicio')
            ->orderBy('ce.id')
            ->get()
            ->map(function ($evento) {
                $nombreCompleto = trim(
                    implode(' ', array_filter([
                        $evento->empleado_nombre,
                        $evento->empleado_paterno,
                        $evento->empleado_materno,
                    ]))
                );
                return [
                    'id'                   => (int) $evento->id,
                    'id_empleado'          => $evento->id_empleado !== null
                        ? (int) $evento->id_empleado
                        : null,
                    'id_sucursal'          => $evento->id_cliente !== null
                        ? (int) $evento->id_cliente
                        : null,
                    'empleado'             => [
                        'id'           => (int) $evento->id_empleado,
                        'numero'       => $evento->numero_empleado,
                        'nombre'       => $nombreCompleto,
                        'departamento' => $evento->empleado_departamento,
                        'puesto'       => $evento->empleado_puesto,
                        'foto'         => $evento->empleado_foto,
                    ],

                    'inicio'               => $evento->inicio,
                    'fin'                  => $evento->fin,
                    'dias_evento'          => $evento->dias_evento !== null
                        ? (int) $evento->dias_evento
                        : null,

                    'descripcion'          => $evento->descripcion,
                    'archivo'              => $evento->archivo,

                    'tipo'                 => [
                        'id'     => (int) $evento->id_tipo,
                        'nombre' => $evento->tipo_nombre,
                        'color'  => $evento->tipo_color ?: '#64748b',
                    ],

                    'tipo_incapacidad_sat' =>
                    $evento->tipo_incapacidad_sat,

                    'estado'               => $evento->estado !== null
                        ? (int) $evento->estado
                        : null,

                    'estado_aprobacion'    =>
                    $evento->estado_aprobacion,

                    'origen_evento'        =>
                    $evento->origen_evento,

                    'requiere_aprobacion'  =>
                    (bool) $evento->requiere_aprobacion,
                ];
            })
            ->values();

        return response()->json([
            'data' => $eventos,
            'meta' => [
                'contexto'     => $contexto,
                'id_portal'    => $idPortal,
                'id_sucursal'  => $contexto === 'sucursal'
                    ? (int) $datos['id_sucursal']
                    : null,
                'id_empleado'  => $contexto === 'empleado'
                    ? (int) $datos['id_empleado']
                    : null,
                'fecha_inicio' => $datos['fecha_inicio'],
                'fecha_fin'    => $datos['fecha_fin'],
                'total'        => $eventos->count(),
            ],
        ]);
    }
    public function evidencia(Request $request, int $id)
    {
        $datos = $request->validate([
            'id_portal' => [
                'required',
                'integer',
                'min:1',
            ],
            'modo'      => [
                'nullable',
                Rule::in(['ver', 'descargar']),
            ],
        ]);

        $idPortal = (int) $datos['id_portal'];
        $modo     = $datos['modo'] ?? 'ver';

        $evento = DB::connection('portal_main')
            ->table('calendario_eventos')
            ->where('id', $id)
            ->where('id_portal', $idPortal)
            ->where('eliminado', 0)
            ->first([
                'id',
                'id_portal',
                'id_cliente',
                'id_empleado',
                'archivo',
            ]);

        if (! $evento) {
            return response()->json([
                'message' => 'Incidencia no encontrada.',
            ], 404);
        }

        if (empty($evento->archivo)) {
            return response()->json([
                'message' => 'La incidencia no tiene evidencia.',
            ], 404);
        }

        /*
     * La BD debe contener una ruta relativa:
     *
     * portals/1/clientes/12/empleados/3/incidencias/archivo.jpeg
     *
     * Los registros antiguos pueden contener solamente:
     *
     * archivo.jpeg
     */
        $rutaRelativa = str_replace(
            '\\',
            '/',
            trim($evento->archivo)
        );

        if (
            $rutaRelativa === '' ||
            str_contains($rutaRelativa, "\0") ||
            str_starts_with($rutaRelativa, '/') ||
            preg_match('/^[A-Za-z]:\//', $rutaRelativa) ||
            in_array('..', explode('/', $rutaRelativa), true)
        ) {
            return response()->json([
                'message' => 'La ruta de la evidencia no es válida.',
            ], 422);
        }

        $basePath = app()->environment('production')
            ? config('paths.prod_images', '')
            : config('paths.local_images', '');

        $basePath = rtrim(
            str_replace('\\', '/', $basePath),
            '/'
        );

        $calendarRoot = $basePath . '/_archivo_calendario';
        $absolutePath = $calendarRoot . '/' . $rutaRelativa;

        $realRoot = realpath($calendarRoot);
        $realFile = realpath($absolutePath);

        if (
            $realRoot === false ||
            $realFile === false ||
            ! is_file($realFile)
        ) {
            return response()->json([
                'message' => 'Evidencia no encontrada en el servidor.',
            ], 404);
        }

        $normalizedRoot = rtrim(
            str_replace('\\', '/', $realRoot),
            '/'
        );

        $normalizedFile = str_replace('\\', '/', $realFile);

        if (
            strpos(
                $normalizedFile,
                $normalizedRoot . '/'
            ) !== 0
        ) {
            return response()->json([
                'message' => 'Acceso no permitido a la evidencia.',
            ], 403);
        }

        $mime = mime_content_type($realFile)
            ?: 'application/octet-stream';

        $extension = strtolower(
            pathinfo($realFile, PATHINFO_EXTENSION)
        );

        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ];

        if (! in_array($mime, $allowedMimeTypes, true)) {
            return response()->json([
                'message' => 'El formato de la evidencia no está permitido.',
            ], 415);
        }

        $downloadName = sprintf(
            'incidencia_%d.%s',
            $evento->id,
            $extension
        );

        if ($modo === 'descargar') {
            return response()->download(
                $realFile,
                $downloadName,
                [
                    'Content-Type'           => $mime,
                    'X-Content-Type-Options' => 'nosniff',
                    'Cache-Control'          =>
                    'private, no-store, max-age=0',
                ]
            );
        }

        return response()->file(
            $realFile,
            [
                'Content-Type'           => $mime,
                'Content-Disposition'    =>
                'inline; filename="' . $downloadName . '"',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          =>
                'private, no-store, max-age=0',
            ]
        );
    }   
}
