<?php

namespace App\Http\Controllers\PreEmpleado;
use App\Http\Controllers\Controller;

use App\Models\Empleado; // Asegúrate de tener el modelo de Empleado
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
class PreEmpleadoController extends Controller
{
    /**
     * Mostrar una lista de todos los pre-empleados.
     */
    public function index()
    {
        $preEmpleados = Empleado::all(); // Obtén todos los registros de pre-empleados
        return view('preempleados.index', compact('preEmpleados')); // Devuelve la vista con los pre-empleados
    }

    /**
     * Mostrar el formulario para crear un nuevo pre-empleado.
     */
    public function create()
    {
        return view('preempleados.create'); // Muestra el formulario de creación de un pre-empleado
    }

    /**
     * Almacenar un nuevo pre-empleado en la base de datos.
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'correo' => 'required|email|unique:pre_empleados',
            'telefono' => 'required|string',
            // Agregar otras validaciones aquí
        ]);

        $preEmpleado = Empleado::create([
            'nombre' => $request->nombre,
            'correo' => $request->correo,
            'telefono' => $request->telefono,
            // Otras columnas que corresponden
        ]);

        return redirect()->route('preempleados.index')->with('success', 'Pre-empleado creado exitosamente.');
    }

    /**
     * Mostrar el formulario para editar un pre-empleado específico.
     */
    public function edit($id)
    {
        $preEmpleado = Empleado::findOrFail($id); // Busca el pre-empleado por ID
        return view('preempleados.edit', compact('preEmpleado')); // Muestra el formulario de edición
    }

    /**
     * Actualizar la información de un pre-empleado específico.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'correo' => 'required|email|unique:pre_empleados,correo,' . $id, // Excluye el correo del mismo ID
            'telefono' => 'required|string',
        ]);
    }

    public function verProcesoCandidato(Request $request)
    {
        $idCandidato = (int) $request->input('id_candidato');
        $statusBgc = (int) $request->input('status_bgc');
        $formulario = $request->input('formulario');

        if ($idCandidato <= 0) {
            return response('Candidato no encontrado.', 404);
        }

        $rodiIntegrationUrl = rtrim(
            (string) config(
                'services.rodi_integration.base_url'
            ),
            '/'
        );

        $rodiIntegrationKey = (string) config(
            'integrations.rodi.document_key'
        );

        if (
            $rodiIntegrationUrl === ''
            || $rodiIntegrationKey === ''
        ) {
            Log::error(
                'Configuración de integración RODI incompleta para proceso de candidato.',
                [
                    'id_candidato' => $idCandidato,
                ]
            );

            return response(
                'Integración RODI no configurada.',
                503
            );
        }

        try {
            $rodiResponse = Http::acceptJson()
                ->withHeaders([
                    'X-RODI-Integration-Key' =>
                        $rodiIntegrationKey,
                ])
                ->timeout(30)
                ->get(
                    $rodiIntegrationUrl .
                    '/candidatos/' .
                    rawurlencode(
                        (string) $idCandidato
                    ) .
                    '/proceso'
                );
        } catch (\Throwable $e) {
            Log::error(
                'Error consultando proceso de candidato en RODI.',
                [
                    'id_candidato' => $idCandidato,
                    'error'        => $e->getMessage(),
                ]
            );

            return response(
                'No fue posible consultar RODI.',
                502
            );
        }

        if ($rodiResponse->status() === 404) {
            abort(404);
        }

        if (! $rodiResponse->successful()) {
            Log::error(
                'RODI devolvió error al consultar proceso de candidato.',
                [
                    'id_candidato' => $idCandidato,
                    'status'       => $rodiResponse->status(),
                ]
            );

            return response(
                'No fue posible consultar RODI.',
                502
            );
        }

        $payload = $rodiResponse->json();

        if (
            ! is_array($payload)
            || ($payload['success'] ?? false) !== true
            || ! is_array($payload['data'] ?? null)
        ) {
            Log::error(
                'Respuesta inválida de RODI para proceso de candidato.',
                [
                    'id_candidato' => $idCandidato,
                ]
            );

            return response(
                'Respuesta inválida de RODI.',
                502
            );
        }

        $proceso = $payload['data'];

        if (empty($proceso['id_seccion'])) {
            return response(
                'No se encontraron secciones para este candidato.',
                404
            );
        }

        $generarFila = function ($descripcion, $estado) {
            return "<tr><th>{$descripcion}</th><th>{$estado}</th></tr>";
        };

        /*
         * Se preserva el comportamiento actual del controlador legado.
         * Las propiedades verificacionMayoresEstudios,
         * verificacionDocumentosCandidato, verificacionReferencias,
         * historialDomicilios y checkCredito no existen actualmente
         * como relaciones/atributos del modelo Candidato.
         */
        $estudios = ! empty($proceso['lleva_estudios'])
            ? $generarFila('Education', 'In process')
            : $generarFila('Education', 'N/A');

        if ($statusBgc > 0 && ! empty($proceso['lleva_estudios'])) {
            $estudios = $generarFila('Education', 'Completed');
        }

        $identidad = ! empty($proceso['lleva_identidad'])
            ? $generarFila('Identity', 'In process')
            : $generarFila('Identity', 'N/A');

        if ($statusBgc > 0 && ! empty($proceso['lleva_identidad'])) {
            $identidad = $generarFila('Identity', 'Completed');
        }

        $empleo = ! empty($proceso['lleva_empleos'])
            ? $generarFila('Employment History', 'In process')
            : $generarFila('Employment History', 'N/A');

        if ($statusBgc > 0 && ! empty($proceso['lleva_empleos'])) {
            $empleo = $generarFila('Employment History', 'Completed');
        }

        $globales = ! empty(
            $proceso['id_seccion_global_search']
        )
            ? (
                ! empty($proceso['tiene_global_search'])
                    ? $generarFila(
                        'Global Database Searches',
                        'Completed'
                    )
                    : $generarFila(
                        'Global Database Searches',
                        'In process'
                    )
            )
            : $generarFila(
                'Global Database Searches',
                'N/A'
            );

        if (
            $statusBgc > 0
            && ! empty(
                $proceso['id_seccion_global_search']
            )
        ) {
            $globales = $generarFila(
                'Global Database Searches',
                'Completed'
            );
        }

        $domicilios = ! empty($proceso['lleva_domicilios'])
            ? $generarFila('Address History', 'In process')
            : $generarFila('Address History', 'N/A');

        if (
            $statusBgc > 0
            && ! empty($proceso['lleva_domicilios'])
        ) {
            $domicilios = $generarFila(
                'Address History',
                'Completed'
            );
        }

        $criminal = ! empty($proceso['lleva_criminal'])
            ? (
                $statusBgc > 0
                    ? $generarFila(
                        'Criminal check',
                        'Completed'
                    )
                    : $generarFila(
                        'Criminal check',
                        'In process'
                    )
            )
            : $generarFila('Criminal check', 'N/A');

        $profesionales = (
            (int) (
                $proceso['cantidad_ref_profesionales']
                ?? 0
            ) > 0
        )
            ? $generarFila(
                'Professional references',
                'Registered'
            )
            : $generarFila(
                'Professional references',
                'N/A'
            );

        if (
            $statusBgc > 0
            && (int) (
                $proceso['cantidad_ref_profesionales']
                ?? 0
            ) > 0
        ) {
            $profesionales = $generarFila(
                'Professional references',
                'Completed'
            );
        }

        $credito = ! empty($proceso['lleva_credito'])
            ? $generarFila(
                'Credit History',
                'In process'
            )
            : $generarFila(
                'Credit History',
                'N/A'
            );

        if (
            $statusBgc > 0
            && ! empty($proceso['lleva_credito'])
        ) {
            $credito = $generarFila(
                'Credit History',
                'Completed'
            );
        }

        $personales = (
            (int) (
                $proceso['cantidad_ref_personales']
                ?? 0
            ) > 0
        )
            ? $generarFila(
                'Personal references',
                'Registered'
            )
            : $generarFila(
                'Personal references',
                'N/A'
            );

        if (
            $statusBgc > 0
            && (int) (
                $proceso['cantidad_ref_personales']
                ?? 0
            ) > 0
        ) {
            $personales = $generarFila(
                'Personal references',
                'Completed'
            );
        }

        $salida = '';
        $salida .= '<table class="table table-striped">';
        $salida .= '<thead><tr><th scope="col">Description</th><th scope="col">Status</th></tr></thead>';
        $salida .= '<tbody>';
        $salida .=
            $estudios .
            $identidad .
            $empleo .
            $profesionales .
            $globales .
            $domicilios .
            $criminal .
            $credito .
            $personales;
        $salida .= '</tbody></table>';

        return response($salida);
    }
}
