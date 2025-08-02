<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\CalendarioEvento;
use App\Models\ClienteTalent;
use App\Models\Empleado;
use App\Models\EventosOption;
use Illuminate\Http\Request;

class CalendarioController extends Controller
{
    //

    public function colaboradoresPorSucursal(Request $request)
    {
        $idCliente = $request->query('id_cliente');

        // Si viene como string y contiene comas, lo convertimos a array
        if (is_string($idCliente) && str_contains($idCliente, ',')) {
            $ids = explode(',', $idCliente);
        } else {
            $ids = is_array($idCliente) ? $idCliente : [$idCliente];
        }

        // Recuperar clientes
        $clientes = ClienteTalent::whereIn('id', $ids)
            ->select('id', 'nombre')
            ->get();

        $empleados = Empleado::with('cliente')
            ->whereIn('id_cliente', $ids)
            ->where('status', 1)
            ->select('id', 'id_empleado', 'nombre', 'paterno', 'materno', 'id_cliente')
            ->get()
            ->map(function ($e) {
                return [
                    'id'              => $e->id,
                    'id_empleado'     => $e->id_empleado,
                    'nombre'          => $e->nombre,
                    'paterno'         => $e->paterno,
                    'materno'         => $e->materno,
                    'nombre_completo' => trim("{$e->nombre} {$e->paterno} {$e->materno}"),
                    'nombre_cliente'  => $e->cliente ? $e->cliente->nombre : '',
                ];
            });

        return response()->json([
            'clientes'  => $clientes,
            'empleados' => $empleados,
        ]);
    }

    public function getEventosPorClientes(Request $request)
    {
        /*\Log::info('[getEventosPorClientes] FULL REQUEST', [
            'all'               => $request->all(),
            'query'             => $request->query(),
            'input_id_cliente'  => $request->input('id_cliente'),
            'input_id_empleado' => $request->input('id_empleado'),
        ]); */

        // 1. Procesar por id_empleado SOLO si existe la clave (aunque sea vacío)
        if ($request->has('id_empleado')) {
            $id_empleados = $request->input('id_empleado');
            if (is_string($id_empleados)) {
                $id_empleados = explode(',', $id_empleados);
            }
            if (! is_array($id_empleados)) {
                $id_empleados = [$id_empleados];
            }

            $id_empleados = array_filter($id_empleados);

            //\Log::info('[getEventosPorClientes] Filtrando por id_empleado:', $id_empleados);

            if (! empty($id_empleados)) {
                $eventos = CalendarioEvento::with('tipo')
                    ->whereIn('id_empleado', $id_empleados)
                    ->get();
            } else {
                $eventos = collect();
            }
        } else {
            // 2. Si no hay id_empleado, procesar por id_cliente
            $id_clientes = $request->input('id_cliente');
            if (is_string($id_clientes)) {
                $id_clientes = explode(',', $id_clientes);
            }
            if (! is_array($id_clientes)) {
                $id_clientes = [$id_clientes];
            }

            $id_clientes = array_filter($id_clientes);

            // \Log::info('[getEventosPorClientes] Filtrando por id_cliente:', $id_clientes);

            if (! empty($id_clientes)) {
                $empleadosIds = Empleado::whereIn('id_cliente', $id_clientes)->pluck('id');
                // \Log::info('[getEventosPorClientes] IDs de empleados encontrados:', $empleadosIds->toArray());

                if ($empleadosIds->isEmpty()) {
                    // \Log::warning('[getEventosPorClientes] No se encontraron empleados para los clientes indicados');
                    $eventos = collect();
                } else {
                    $eventos = CalendarioEvento::with('tipo')
                        ->whereIn('id_empleado', $empleadosIds)
                        ->where('eliminado', 0)
                        ->get();
                }
            } else {
                // \Log::warning('[getEventosPorClientes] id_clientes vacío o no es array');
                $eventos = collect();
            }
        }

        // \Log::info('[getEventosPorClientes] Total de eventos encontrados:', ['count' => $eventos->count()]);
        if ($eventos->count()) {
           // \Log::info('[getEventosPorClientes] PRIMER EVENTO RAW:', $eventos->first()->toArray());
        }

        $result = $eventos->map(function ($evento) {
            // Toma el empleado relacionado y concatena los campos si existen
            $empleado       = $evento->empleado;
            $nombreCompleto = '';
            if ($empleado) {
                $nombreCompleto = trim(
                    ($empleado->nombre ?? '') . ' ' .
                    ($empleado->paterno ?? '') . ' ' .
                    ($empleado->materno ?? '')
                );
            }

            return [
                'id'              => $evento->id,
                'title'           => $evento->tipo->name ?? 'Evento',
                'tipo_evento'     => $evento->tipo->name ?? 'evento',
                'start'           => $evento->inicio,
                'end'             => $evento->fin,
                'backgroundColor' => $evento->tipo->color ?? '#a78bfa',
                'descripcion'     => $evento->descripcion,
                'archivo'         => $evento->archivo,
                'id_empleado'     => $evento->id_empleado,
                'empleado'        => $nombreCompleto,
                'id_periodo'      => $evento->id_periodo_nomina,
            ];
        });

      //  \Log::info('[getEventosPorClientes] Primer evento mapeado:', $result->all() ?? []);

        return response()->json(['eventos' => $result]);
    }

    public function setEventos(Request $request)
    {
        \Log::info('Payload completo recibido en setEventos: ' . json_encode($request->all()));

        $eventos    = $request->input('eventos');
        $id_portal  = $request->input('id_portal');
        $id_usuario = $request->input('id_usuario');
        $id_periodo = $request->input('periodo_nomina_id');

        if (! is_array($eventos)) {
            return response()->json(['error' => 'El campo eventos debe ser un array.'], 400);
        }

        $eventosGuardados = [];

        foreach ($eventos as $i => $evento) {
            // 1. Buscar o crear el tipo de evento si es personalizado
            $tipoId = $evento['tipoId'] ?? null;
            if (! $tipoId && ! empty($evento['tipoNombre'])) {
                $nuevoTipo = \App\Models\EventosOption::firstOrCreate(
                    [
                        'name'      => $evento['tipoNombre'],
                        'id_portal' => $id_portal ?? null,
                    ],
                    [
                        'color'    => $evento['backgroundColor'] ?? '#a78bfa',
                        'creacion' => now(),
                    ]
                );
                $tipoId = $nuevoTipo->id;
            }

            // 2. Guardar el archivo (si existe)
            $archivoNombre = null;
            $archivo       = $request->file("eventos.$i.archivo");
            if ($archivo && $archivo->isValid()) {
                $extension         = $archivo->getClientOriginalExtension();
                $archivoNombre     = "portal{$id_portal}_emp{$evento['colaboradorId']}_" . time() . "_" . uniqid() . "." . $extension;
                $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
                if (! file_exists($directorioDestino)) {
                    mkdir($directorioDestino, 0777, true);
                }
                $archivo->move($directorioDestino, $archivoNombre);
            }
            $fechaInicio = new \DateTime($evento['start']);
            $fechaFin    = new \DateTime($evento['end']);
            $dias        = $fechaInicio->diff($fechaFin)->days + 1;

            // *** NUEVO: Guarda el id_periodo en CalendarioEvento ***
            $eventoGuardado = CalendarioEvento::create([
                'id_usuario'        => $id_usuario,
                'id_empleado'       => $evento['colaboradorId'],
                'id_tipo'           => $tipoId,
                'inicio'            => $evento['start'],
                'fin'               => $evento['end'],
                'dias_evento'       => $dias,
                'descripcion'       => $evento['descripcion'] ?? '',
                'archivo'           => $archivoNombre,
                'id_periodo_nomina' => $id_periodo, // puede ser null
                'eliminado'         => 0,           // por defecto no eliminado
            ]);
            $eventosGuardados[] = $eventoGuardado;

            // *** LÓGICA PARA PRENÓMINA ***
            /*
        if ($id_periodo && in_array((int) $tipoId, [1, 4])) {
            // Aquí irá la lógica para actualizar prenómina y laborales
        }
        */
        }

        return response()->json([
            'ok'      => true,
            'eventos' => $eventosGuardados,
        ]);
    }
    public function actualizarEvento(Request $request, $id)
    {
        // 1. Log principal de la petición
        \Log::info('>>> [actualizarEvento] REQUEST', [
            'id'     => $id,
            'inputs' => $request->all(),
            'files'  => $request->allFiles(),
            'method' => $request->method(),
        ]);

        // 2. ¿Es método PUT o PATCH? (log)
        if ($request->isMethod('put') || $request->isMethod('patch')) {
            \Log::info('>>> [actualizarEvento] Método PUT/PATCH. Reagregando archivos...');
            $request->files->add($request->allFiles());
        }

        // 3. Buscar evento
        $evento = CalendarioEvento::find($id);
        \Log::info('>>> [actualizarEvento] Evento encontrado:', $evento ? $evento->toArray() : ['null']);

        if (! $evento) {
            \Log::warning('>>> [actualizarEvento] No se encontró el evento con ID: ' . $id);
            return response()->json(['error' => 'No se encontró el evento'], 404);
        }

        // 4. Asignar campos
        $evento->id_usuario        = $request->input('id_usuario', $evento->id_usuario);
        $evento->id_empleado       = $request->input('id_empleado', $evento->id_empleado);
        $evento->id_tipo           = $request->input('id_tipo', $evento->id_tipo);
        $evento->inicio            = $request->input('inicio', $evento->inicio);
        $evento->fin               = $request->input('fin', $evento->fin);
        $evento->descripcion       = $request->input('descripcion', $evento->descripcion);
        $evento->id_periodo_nomina = $request->input('id_periodo', $evento->id_periodo_nomina);

        \Log::info('>>> [actualizarEvento] Datos para actualizar:', $evento->toArray());

        // 5. Calcula días del evento (log error si falla)
        try {
            $fechaInicio         = new \DateTime($evento->inicio);
            $fechaFin            = new \DateTime($evento->fin);
            $dias                = $fechaInicio->diff($fechaFin)->days + 1;
            $evento->dias_evento = $dias;
            \Log::info(">>> [actualizarEvento] Calculados días evento: $dias");
        } catch (\Exception $e) {
            $evento->dias_evento = 1;
            \Log::warning('>>> [actualizarEvento] Error calculando días evento: ' . $e->getMessage());
        }

        // 6. Manejo de archivo
        \Log::info('>>> [actualizarEvento] hasFile(archivo): ' . ($request->hasFile('archivo') ? 'SI' : 'NO'));

        if ($request->hasFile('archivo')) {
            // Borra el archivo anterior si existe
            if ($evento->archivo) {
                $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
                $rutaArchivo       = $directorioDestino . '/' . $evento->archivo;
                if (file_exists($rutaArchivo)) {
                    unlink($rutaArchivo);
                    \Log::info(">>> [actualizarEvento] Archivo anterior borrado: $rutaArchivo");
                } else {
                    \Log::warning(">>> [actualizarEvento] Archivo anterior NO encontrado para borrar: $rutaArchivo");
                }
            }
                \Log::info('Método de la solicitud: ' . $request->method());

            // Guarda el nuevo archivo
            $archivo           = $request->file('archivo');
            $extension         = $archivo->getClientOriginalExtension();
            $archivoNombre     = "portal{$evento->id_usuario}_emp{$evento->id_empleado}_" . time() . "_" . uniqid() . "." . $extension;
            $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';
            if (! file_exists($directorioDestino)) {
                mkdir($directorioDestino, 0777, true);
                \Log::info(">>> [actualizarEvento] Carpeta creada: $directorioDestino");
            }
            $archivo->move($directorioDestino, $archivoNombre);
            $evento->archivo = $archivoNombre;
            \Log::info(">>> [actualizarEvento] Archivo nuevo guardado: $archivoNombre");
        } else {
            \Log::info('>>> [actualizarEvento] No se envió archivo nuevo.');
        }

        $evento->save();
        \Log::info('>>> [actualizarEvento] Evento actualizado y guardado', $evento->toArray());

        // Lógica para prenómina...
        /*
    if ($evento->id_periodo_nomina && in_array((int) $evento->id_tipo, [1, 4])) {
        \Log::info('>>> [actualizarEvento] Lógica prenómina pendiente.');
    }
    */

        return response()->json(['ok' => true, 'evento' => $evento]);
    }

    public function eliminarEvento($id)
    {
        $evento = CalendarioEvento::findOrFail($id);

        // Solo marca como eliminado
        $evento->eliminado = 1;
        $evento->save();

        // *** LÓGICA PARA PRENÓMINA ***
        /*
    if ($evento->id_periodo_nomina && in_array((int) $evento->id_tipo, [1, 4])) {
        // Aquí irá la lógica para actualizar prenómina y laborales
    }
    */

        return response()->json(['ok' => true, 'evento' => $evento]);
    }

    public function getTiposEvento(Request $request)
    {
        $query = EventosOption::query();

        if ($request->filled('id_portal')) {
            $id_portal = $request->input('id_portal');
            $query->where(function ($q) use ($id_portal) {
                $q->where('id_portal', $id_portal)
                    ->orWhereNull('id_portal');
            });
        } else {
            $query->whereNull('id_portal');
        }

        $tipos = $query->select('id', 'name', 'color')->distinct()->get();

        return response()->json($tipos);
    }

}
