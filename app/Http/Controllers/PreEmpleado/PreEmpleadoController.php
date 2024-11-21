<?php

namespace App\Http\Controllers\PreEmpleado;

use App\Models\Empleado; // Asegúrate de tener el modelo de Empleado
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
}
