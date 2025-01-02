<?php

namespace App\Http\Controllers\PreEmpleado;
use App\Http\Controllers\Controller;

use App\Models\Empleado; // Asegúrate de tener el modelo de Empleado
use App\Models\Candidato; //
use Illuminate\Http\Request;
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
        $idCandidato = $request->input('id_candidato');
        $statusBgc = $request->input('status_bgc');
        $formulario = $request->input('formulario');

        $candidato = Candidato::with([
           
            'referenciasPersonales',
            'referenciasProfesionales',
            'globalSearches',
            'secciones', // Incluye la relación secciones
        ])->findOrFail($idCandidato);
    
        $secciones = $candidato->secciones; // Relación cargada automáticamente.
    
        if (!$secciones) {
            return response('No se encontraron secciones para este candidato.', 404);
        }
        // Asumiendo que tienes una relación o método para obtener secciones.

        $salida = '';

        // Función auxiliar para generar filas
        $generarFila = function ($descripcion, $estado) {
            return "<tr><th>{$descripcion}</th><th>{$estado}</th></tr>";
        };

        $estudios = $secciones->lleva_estudios
            ? ($candidato->verificacionMayoresEstudios ? $generarFila('Education', 'Registered') : $generarFila('Education', 'In process'))
            : $generarFila('Education', 'N/A');

        if ($statusBgc > 0 && $secciones->lleva_estudios) {
            $estudios = $generarFila('Education', 'Completed');
        }

        $identidad = $secciones->lleva_identidad
            ? ($candidato->verificacionDocumentosCandidato ? $generarFila('Identity', 'Registered') : $generarFila('Identity', 'In process'))
            : $generarFila('Identity', 'N/A');

        if ($statusBgc > 0 && $secciones->lleva_identidad) {
            $identidad = $generarFila('Identity', 'Completed');
        }

        $empleo = $secciones->lleva_empleos
            ? ($candidato->verificacionReferencias ? $generarFila('Employment History', 'Registered') : $generarFila('Employment History', 'In process'))
            : $generarFila('Employment History', 'N/A');

        if ($statusBgc > 0 && $secciones->lleva_empleos) {
            $empleo = $generarFila('Employment History', 'Completed');
        }

        $globales = $secciones->id_seccion_global_search
            ? ($candidato->globalSearches ? $generarFila('Global Database Searches', 'Completed') : $generarFila('Global Database Searches', 'In process'))
            : $generarFila('Global Database Searches', 'N/A');

        if ($statusBgc > 0 && $secciones->id_seccion_global_search) {
            $globales = $generarFila('Global Database Searches', 'Completed');
        }

        $domicilios = $secciones->lleva_domicilios
            ? ($candidato->historialDomicilios ? $generarFila('Address History', 'Registered') : $generarFila('Address History', 'In process'))
            : $generarFila('Address History', 'N/A');

        if ($statusBgc > 0 && $secciones->lleva_domicilios) {
            $domicilios = $generarFila('Address History', 'Completed');
        }

        $criminal = $secciones->lleva_criminal
            ? ($statusBgc > 0 ? $generarFila('Criminal check', 'Completed') : $generarFila('Criminal check', 'In process'))
            : $generarFila('Criminal check', 'N/A');

        $profesionales = $secciones->cantidad_ref_profesionales > 0
            ? ($candidato->referenciasProfesionales ? $generarFila('Professional references', 'Registered') : $generarFila('Professional references', 'In process'))
            : $generarFila('Professional references', 'N/A');

        if ($statusBgc > 0 && $secciones->cantidad_ref_profesionales > 0) {
            $profesionales = $generarFila('Professional references', 'Completed');
        }

        $credito = $secciones->lleva_credito
            ? ($candidato->checkCredito ? $generarFila('Credit History', 'Registered') : $generarFila('Credit History', 'In process'))
            : $generarFila('Credit History', 'N/A');

        if ($statusBgc > 0 && $secciones->lleva_credito) {
            $credito = $generarFila('Credit History', 'Completed');
        }

        $personales = $secciones->cantidad_ref_personales > 0
            ? ($candidato->referenciasPersonales ? $generarFila('Personal references', 'Registered') : $generarFila('Personal references', 'In process'))
            : $generarFila('Personal references', 'N/A');

        if ($statusBgc > 0 && $secciones->cantidad_ref_personales > 0) {
            $personales = $generarFila('Personal references', 'Completed');
        }

        $salida .= '<table class="table table-striped">';
        $salida .= '<thead><tr><th scope="col">Description</th><th scope="col">Status</th></tr></thead>';
        $salida .= '<tbody>';
        $salida .= $estudios . $identidad . $empleo . $profesionales . $globales . $domicilios . $criminal . $credito . $personales;
        $salida .= '</tbody></table>';

        return response($salida);
    }
}
