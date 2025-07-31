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
        \Log::info('[getEventosPorClientes] FULL REQUEST', [
            'all'               => $request->all(),
            'query'             => $request->query(),
            'input_id_cliente'  => $request->input('id_cliente'),
            'input_id_empleado' => $request->input('id_empleado'),
        ]);

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

            \Log::info('[getEventosPorClientes] Filtrando por id_empleado:', $id_empleados);

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

            \Log::info('[getEventosPorClientes] Filtrando por id_cliente:', $id_clientes);

            if (! empty($id_clientes)) {
                $empleadosIds = Empleado::whereIn('id_cliente', $id_clientes)->pluck('id');
                \Log::info('[getEventosPorClientes] IDs de empleados encontrados:', $empleadosIds->toArray());

                if ($empleadosIds->isEmpty()) {
                    \Log::warning('[getEventosPorClientes] No se encontraron empleados para los clientes indicados');
                    $eventos = collect();
                } else {
                    $eventos = CalendarioEvento::with('tipo')
                        ->whereIn('id_empleado', $empleadosIds)
                        ->get();
                }
            } else {
                \Log::warning('[getEventosPorClientes] id_clientes vacío o no es array');
                $eventos = collect();
            }
        }

        \Log::info('[getEventosPorClientes] Total de eventos encontrados:', ['count' => $eventos->count()]);
        if ($eventos->count()) {
            \Log::info('[getEventosPorClientes] Primer evento:', $eventos->first()->toArray());
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
                'title'           => $evento->tipo->name ?? 'Evento',
                'start'           => $evento->inicio,
                'end'             => $evento->fin,
                'backgroundColor' => $evento->tipo->color ?? '#a78bfa',
                'descripcion'     => $evento->descripcion,
                'archivo'         => $evento->archivo,
                'id_empleado'     => $evento->id_empleado,
                'empleado'        => $nombreCompleto, // <-- aquí el nombre completo
            ];
        });

        \Log::info('[getEventosPorClientes] Primer evento mapeado:', $result->first() ?? []);

        return response()->json(['eventos' => $result]);
    }

    public function setEventos(Request $request)
    {
        // \Log::info('Payload completo recibido en setEventos: ' . json_encode($request->all()));

        $eventos   = $request->input('eventos');
        $id_portal = $request->input('id_portal');

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
            $archivo       = $request->file("eventos.$i.archivo"); // importante: entre comillas dobles
            if ($archivo && $archivo->isValid()) {
                // Genera nombre único: idPortal_idEmpleado_timestamp.ext
                $extension     = $archivo->getClientOriginalExtension();
                $archivoNombre = "portal{$id_portal}_emp{$evento['colaboradorId']}_" . time() . "_" . uniqid() . "." . $extension;
                // Guarda en storage/app/public/archivos_eventos o en public/archivos_eventos
                $directorioDestino = rtrim(env('LOCAL_IMAGE_PATH'), '/') . '/_archivo_calendario';

                if (! file_exists($directorioDestino)) {
                    mkdir($directorioDestino, 0777, true);
                }
                $archivo->move($directorioDestino, $archivoNombre);
            }

            // 3. Inserta el evento
            $eventoGuardado = \App\Models\CalendarioEvento::create([
                'id_empleado' => $evento['colaboradorId'],
                'id_tipo'     => $tipoId,
                'inicio'      => $evento['start'],
                'fin'         => $evento['end'],
                'descripcion' => $evento['descripcion'] ?? '',
                'archivo'     => $archivoNombre, // Guarda aquí el nombre generado o null
            ]);
            $eventosGuardados[] = $eventoGuardado;
        }

        return response()->json([
            'ok'      => true,
            'eventos' => $eventosGuardados,
        ]);
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
