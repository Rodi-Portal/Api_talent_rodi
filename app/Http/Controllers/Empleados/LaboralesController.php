<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use Illuminate\Http\Request;

class LaboralesController extends Controller
{
    /**
     * Obtener los datos laborales del empleado junto con sus relaciones.
     *
     * @param  int  $id_empleado
     * @return \Illuminate\Http\Response
     */
    public function obtenerDatosLaborales($id_empleado)
    {
        // Usamos el modelo Empleado para obtener los datos y relaciones
        $empleado = Empleado::obtenerEmpleadoConRelacionados($id_empleado);

        // Verificamos si el empleado existe
        if (! $empleado) {
            return response()->json([
                'message' => 'Empleado no encontrado',
            ], 404);
        }

        // Devolvemos la información del empleado con las relaciones
        return response()->json($empleado, 200);
    }

    /**
     * Guardar datos laborales de un empleado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function guardarDatosLaborales(Request $request)
    {
        $request->validate([
            'id_empleado'           => 'required|exists:portal_main.empleados,id',
            'grupoNomina'           => 'string|max:255',
            'horasDia'              => 'numeric|min:0',
            'imss'                  => 'numeric|min:0',
            'infonavit'             => 'numeric|min:0',
            'diasAguinaldo'         => 'numeric|min:0',
            'descuentoAusencia'     => 'numeric|min:0',
            'primaVacacional'       => 'numeric|min:0',
            'otroTipoContrato'      => 'nullable|string|max:255',
            'pagoDiaFestivo'        => 'required|numeric|min:0',
            'pagoHoraExtra'         => 'required|numeric|min:0',
            'periodicidadPago'      => 'string|max:255',
            'sueldoDiario'          => 'nullable|numeric|min:0',
            'sueldoMes'             => 'required|numeric|min:0',
            'tipoContrato'          => 'nullable|string|max:255',
            'tipoJornada'           => 'string|max:255',
            'tipoNomina'            => 'string|max:255',
            'tipoRegimen'           => 'string|max:255',
            'vacacionesDisponibles' => 'required|numeric|min:0',
            'diasDescanso'          => 'array|min:0',
            'diasDescanso.*'        => 'string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
        ]);

        $empleado = Empleado::find($request->id_empleado);

        if (! $empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        // Guardar datos laborales
        $empleado->laborales()->create([
            'id_empleado'            => $request->id_empleado,
            'grupo_nomina'           => $request->grupoNomina,
            'horas_dia'              => $request->horasDia,
            'dias_aguinaldo'         => $request->diasAguinaldo,
            'descuento_ausencia'     => $request->descuentoAusencia,
            'prima_vacacional'       => $request->primaVacacional,
            'imss'                   => $request->imss,
            'infonavit'              => $request->infonavit,
            'otro_tipo_contrato'     => $request->otroTipoContrato,
            'pago_dia_festivo'       => $request->pagoDiaFestivo,
            'pago_hora_extra'        => $request->pagoHoraExtra,
            'periodicidad_pago'      => $request->periodicidadPago,
            'sueldo_diario'          => $request->sueldoDiario,
            'sueldo_mes'             => $request->sueldoMes,
            'tipo_contrato'          => $request->tipoContrato,
            'tipo_jornada'           => $request->tipoJornada,
            'tipo_nomina'            => $request->tipoNomina,
            'tipo_regimen'           => $request->tipoRegimen,
            'vacaciones_disponibles' => $request->vacacionesDisponibles,
            'dias_descanso'          => json_encode($request->diasDescanso), // Guardar los días de descanso como un JSON
        ]);

        return response()->json(['message' => 'Datos laborales guardados correctamente'], 201);
    }

    /**
     * Actualizar datos laborales de un empleado.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id_empleado
     * @return \Illuminate\Http\Response
     */
    public function actualizarDatosLaborales(Request $request, $id_empleado)
    {
        $request->validate([
            'id_empleado'           => 'required|exists:portal_main.empleados,id',
            'grupoNomina'           => 'string|max:255',
            'horasDia'              => 'required|numeric|min:0',
            'imss'                  => 'required|numeric|min:0',
            'infonavit'             => 'required|numeric|min:0',
            'diasAguinaldo'         => 'required|numeric|min:0',
            'descuentoAusencia'     => 'required|numeric|min:0',
            'primaVacacional'       => 'required|numeric|min:0',
            'otroTipoContrato'      => 'nullable|string|max:255',
            'pagoDiaFestivo'        => 'required|numeric|min:0',
            'pagoHoraExtra'         => 'required|numeric|min:0',
            'periodicidadPago'      => 'string|max:255',
            'sueldoDiario'          => 'nullable|numeric|min:0',
            'sueldoMes'             => 'required|numeric|min:0',
            'tipoContrato'          => 'nullable|string|max:255',
            'tipoJornada'           => 'string|max:255',
            'tipoNomina'            => 'string|max:255',
            'tipoRegimen'           => 'string|max:255',
            'vacacionesDisponibles' => 'required|numeric|min:0',
            'diasDescanso'          => 'array|min:0',
            'diasDescanso.*'        => 'string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
        ]);

        $empleado = Empleado::find($id_empleado);

        if (! $empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        // Verificar si tiene datos laborales
        if (! $empleado->laborales) {
            return response()->json(['message' => 'No hay datos laborales para actualizar'], 404);
        }

        // Actualizar datos laborales
        $empleado->laborales()->update([
            'id_empleado'            => $request->id_empleado,
            'grupo_nomina'           => $request->grupoNomina,
            'horas_dia'              => $request->horasDia,
            'dias_aguinaldo'         => $request->diasAguinaldo,
            'descuento_ausencia'     => $request->descuentoAusencia,
            'prima_vacacional'       => $request->primaVacacional,
            'imss'                   => $request->imss,
            'infonavit'              => $request->infonavit,
            'otro_tipo_contrato'     => $request->otroTipoContrato,
            'pago_dia_festivo'       => $request->pagoDiaFestivo,
            'pago_hora_extra'        => $request->pagoHoraExtra,
            'periodicidad_pago'      => $request->periodicidadPago,
            'sueldo_diario'          => $request->sueldoDiario,
            'sueldo_mes'             => $request->sueldoMes,
            'tipo_contrato'          => $request->tipoContrato,
            'tipo_jornada'           => $request->tipoJornada,
            'tipo_nomina'            => $request->tipoNomina,
            'tipo_regimen'           => $request->tipoRegimen,
            'vacaciones_disponibles' => $request->vacacionesDisponibles,
            'dias_descanso'          => json_encode($request->diasDescanso), // Guardar los días de descanso como un JSON
        ]);
        return response()->json(['message' => 'Datos laborales actualizados correctamente'], 200);
    }
}
