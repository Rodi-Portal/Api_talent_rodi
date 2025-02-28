<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\LaboralesEmpleado;

use App\Models\PreNominaEmpleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'dias_descanso'          => json_encode($request->diasDescanso), 
        ]);
        return response()->json(['message' => 'Datos laborales actualizados correctamente'], 200);
    }

    public function guardarPrenomina(Request $request)
{
    // Registra todos los datos recibidos para asegurarte de que la solicitud llega correctamente
  //  Log::info('Datos recibidos: ', $request->all());

    // Validar los datos si es necesario
    try {
        $validated = $request->validate([
            'idEmpleado'         => 'required|numeric',
            'sueldoBase'         => 'required|numeric',
            'horasExtras'        => 'nullable|numeric',
            'pagoHorasExtras'    => 'nullable|numeric',
            'comisiones'         => 'nullable|numeric',
            'bonificaciones'     => 'nullable|numeric',
            'diasFestivos'       => 'nullable|numeric',
            'pagoDiasFestivos'   => 'nullable|numeric',
            'diasAusencias'      => 'nullable|numeric',
            'aguinaldo'          => 'nullable|numeric',
            'vacaciones'         => 'nullable|numeric',
            'pagoVacaciones'     => 'nullable|numeric',
            'primaVacacional'    => 'required|numeric',
            'valesDespensa'      => 'nullable|numeric',
            'fondoAhorro'        => 'nullable|numeric',
            'descuentoAusencia'  => 'nullable|numeric',
            'descuentoImss'      => 'nullable|numeric',
            'descuentoInfonavit' => 'nullable|numeric',
            'prestamos'          => 'nullable|numeric',
            'deduccionesExtras'  => 'nullable|json',
            'prestacionesExtras' => 'nullable|json',
            'salarioNeto'        => 'required|numeric',
            'totalPagar'         => 'required|numeric',
        ]);
    
    } catch (\Illuminate\Validation\ValidationException $e) {
        Log::error('Error en validación: ', $e->errors());
        return response()->json(['errors' => $e->errors()], 422);
    }
    

    // Si pasa la validación, continúa con el guardado
    try {
      //  Log::info('Intentando guardar el registro en la base de datos.');
    
        $registro                     = new PreNominaEmpleado();
        $registro->id_empleado        = $validated['idEmpleado'];
        $registro->sueldo_base        = $validated['sueldoBase'];
        $registro->horas_extras       = $validated['horasExtras'];
        $registro->pago_horas_extra   = $validated['pagoHorasExtras'];
        $registro->comisiones         = $validated['comisiones'];
        $registro->bonificaciones     = $validated['bonificaciones'];
        $registro->dias_festivos      = $validated['diasFestivos'];
        $registro->dias_ausencia      = $validated['diasAusencias'];
        $registro->descuento_ausencias= $validated['descuentoAusencia'];
        $registro->descuento_imss     = $validated['descuentoImss'];
        $registro->descuento_infonavit= $validated['descuentoInfonavit'];
        $registro->aguinaldo          = $validated['aguinaldo'];
        $registro->dias_vacaciones    = $validated['vacaciones'];
        $registro->pago_vacaciones    = $validated['pagoVacaciones'];
        $registro->pago_dias_festivos = $validated['pagoDiasFestivos'];
        $registro->prima_vacacional   = $validated['primaVacacional'];
        $registro->vales_despensa     = $validated['valesDespensa'];
        $registro->fondo_ahorro       = $validated['fondoAhorro'];
        $registro->prestamos          = $validated['prestamos'];
        $registro->deducciones_extra  = json_encode($validated['deduccionesExtras']);
        $registro->prestaciones_extra = json_encode($validated['prestacionesExtras']);
        $registro->sueldo_neto        = $validated['salarioNeto'];
        $registro->sueldo_total       = $validated['totalPagar'];
    
        if ($registro->save()) {
          //  Log::info('Registro guardado exitosamente.');

            // Verificar si vacaciones es mayor que 0 y actualizar vacaciones_disponibles
            if (!empty($validated['vacaciones']) && $validated['vacaciones'] > 0) {
                $laborales = LaboralesEmpleado::where('id_empleado', $validated['idEmpleado'])->first();
                
                if ($laborales) {
                    $nuevasVacaciones = max(0, $laborales->vacaciones_disponibles - $validated['vacaciones']);
                    $laborales->vacaciones_disponibles = $nuevasVacaciones;
                    $laborales->save();

                  // Log::info("Vacaciones actualizadas para el empleado {$validated['idEmpleado']}: {$nuevasVacaciones}");
                } else {
                    Log::warning("No se encontró registro en laborales_empleado para el empleado {$validated['idEmpleado']}");
                }
            }

            return response()->json(['message' => 'Datos registrados correctamente.'], 201);
        } else {
            Log::error('Error al guardar el registro en la base de datos.');
            return response()->json(['message' => 'Error al guardar los datos.'], 500);
        }
    
    } catch (\Exception $e) {
        Log::error('Excepción al guardar datos: ' . $e->getMessage());
        return response()->json(['message' => 'Hubo un error al guardar los datos.'], 500);
    }
}

}
