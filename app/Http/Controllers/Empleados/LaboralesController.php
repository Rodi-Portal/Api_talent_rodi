<?php
namespace App\Http\Controllers\Empleados;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Models\LaboralesEmpleado;
use App\Models\PreNominaEmpleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

// <- ESTA LÍNEA FALTABA

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
        // Log inicial: datos recibidos
        \Log::info('Datos recibidos en guardarDatosLaborales', $request->all());

        // Validación
        try {
            $request->validate([
                'id_empleado'           => 'required|exists:portal_main.empleados,id',
                'grupoNomina'           => 'string|max:255',
                'horasDia'              => 'numeric|min:0',
                'diasAguinaldo'         => 'numeric|min:0',
                'descuentoAusencia'     => 'numeric|min:0',
                'descuentoAusenciaA'    => 'nullable|numeric|min:0',
                'primaVacacional'       => 'numeric|min:0',
                'otroTipoContrato'      => 'nullable|string|max:255',
                'pagoDiaFestivo'        => 'nullable|numeric|min:0',
                'pagoDiaFestivoA'       => 'nullable|numeric|min:0',
                'pagoHoraExtra'         => 'nullable|numeric|min:0',
                'pagoHoraExtraA'        => 'nullable|numeric|min:0',
                'prestamoPendiente'     => 'nullable|numeric|min:0',
                'periodicidadPago'      => 'string|max:255',
                'sueldoDiario'          => 'nullable|numeric|min:0',
                'sueldoDiarioAsimilado' => 'nullable|numeric|min:0',
                'sueldoMes'             => 'required|numeric|min:0',
                'tipoContrato'          => 'nullable|string|max:255',
                'tipoJornada'           => 'string|max:255',
                'tipoRegimen'           => 'string|max:255',
                'vacacionesDisponibles' => 'required|numeric|min:0',
                'diasDescanso'          => 'array|min:0',
                'diasDescanso.*'        => 'string|in:Lunes,Martes,Miércoles,Jueves,Viernes,Sábado,Domingo',
                'sindicato'             => 'string|max:255',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Error de validación en guardarDatosLaborales', [
                'errores' => $e->errors(),
                'input'   => $request->all(),
            ]);
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        }

        // Buscar empleado
        $empleado = Empleado::find($request->id_empleado);

        if (! $empleado) {
            \Log::warning('Empleado no encontrado', ['id_empleado' => $request->id_empleado]);
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        try {
            // Guardar datos laborales
            $empleado->laborales()->create([
                'id_empleado'            => $request->id_empleado,
                'grupo_nomina'           => $request->grupoNomina,
                'horas_dia'              => $request->horasDia,
                'dias_aguinaldo'         => $request->diasAguinaldo,
                'descuento_ausencia'     => $request->descuentoAusencia,
                'descuento_ausencia_a'   => $request->descuentoAusenciaA ?? null,
                'prima_vacacional'       => $request->primaVacacional,
                'otro_tipo_contrato'     => $request->otroTipoContrato,
                'pago_dia_festivo'       => $request->pagoDiaFestivo,
                'pago_dia_festivo_a'     => $request->pagoDiaFestivoA ?? null,
                'pago_hora_extra'        => $request->pagoHoraExtra,
                'pago_hora_extra_A'      => $request->pagoHoraExtraA ?? null,
                'periodicidad_pago'      => $request->periodicidadPago,
                'prestamo_pendiente'     => $request->prestamoPendiente,
                'sueldo_diario'          => $request->sueldoDiario,
                'sueldo_asimilado'       => $request->sueldoDiarioAsimilado,
                'sueldo_mes'             => $request->sueldoMes,
                'tipo_contrato'          => $request->tipoContrato,
                'tipo_jornada'           => $request->tipoJornada,
                'tipo_regimen'           => $request->tipoRegimen,
                'sindicato'              => $request->sindicato,
                'vacaciones_disponibles' => $request->vacacionesDisponibles,
                'dias_descanso'          => json_encode($request->diasDescanso), // Guardar los días de descanso como un JSON
            ]);
            \Log::info('Datos laborales guardados correctamente para empleado', ['id_empleado' => $request->id_empleado]);
            return response()->json(['message' => 'Datos laborales guardados correctamente'], 201);

        } catch (\Exception $ex) {
            \Log::error('Error guardando datos laborales', [
                'id_empleado' => $request->id_empleado,
                'error'       => $ex->getMessage(),
                'input'       => $request->all(),
            ]);
            return response()->json(['message' => 'Error interno al guardar datos laborales'], 500);
        }
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
        //  Log::info('Datos recibidos: ', $request->all());
        $request->validate([
            'id_empleado'           => 'required|exists:portal_main.empleados,id',
            'grupoNomina'           => 'string|max:255',
            'horasDia'              => 'required|numeric|min:0',
            'diasAguinaldo'         => 'required|numeric|min:0',
            'descuentoAusencia'     => 'required|numeric|min:0',
            'descuentoAusenciaA'    => 'nullable|numeric|min:0',
            'primaVacacional'       => 'required|numeric|min:0',
            'otroTipoContrato'      => 'nullable|string|max:255',
            'pagoDiaFestivo'        => 'required|numeric|min:0',
            'pagoDiaFestivoA'       => 'nullable|numeric|min:0',
            'pagoHoraExtra'         => 'required|numeric|min:0',
            'pagoHoraExtraA'        => 'nullable|numeric|min:0',
            'periodicidadPago'      => 'string|max:255',
            'sueldoDiario'          => 'nullable|numeric|min:0',
            'sueldoDiarioAsimilado' => 'nullable|numeric|min:0',
            'sueldoMes'             => 'required|numeric|min:0',
            'tipoContrato'          => 'nullable|string|max:255',
            'tipoJornada'           => 'string|max:255',
            'tipoRegimen'           => 'string|max:255',
            'sindicaro'             => 'string|max:255',
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
            'descuento_ausencia_a'   => $request->descuentoAusenciaA ?? null,
            'prima_vacacional'       => $request->primaVacacional,
            'otro_tipo_contrato'     => $request->otroTipoContrato,
            'pago_dia_festivo'       => $request->pagoDiaFestivo,
            'pago_dia_festivo_a'     => $request->pagoDiaFestivoA ?? null,
            'pago_hora_extra'        => $request->pagoHoraExtra,
            'pago_hora_extra_a'      => $request->pagoHoraExtraA ?? null,
            'periodicidad_pago'      => $request->periodicidadPago,
            'sueldo_diario'          => $request->sueldoDiario,
            'sueldo_asimilado'       => $request->sueldoDiarioAsimilado,
            'sueldo_mes'             => $request->sueldoMes,
            'tipo_contrato'          => $request->tipoContrato,
            'tipo_jornada'           => $request->tipoJornada,
            'prestamo_pendiente'     => $request->prestamoPendiente,
            'tipo_regimen'           => $request->tipoRegimen,
            'sindicato'              => $request->sindicato,
            'vacaciones_disponibles' => $request->vacacionesDisponibles,
            'dias_descanso'          => json_encode($request->diasDescanso),
        ]);
        return response()->json(['message' => 'Datos laborales actualizados correctamente'], 200);
    }

    public function guardarPrenomina(Request $request)
    {
        Log::info('Datos recibidos: ', $request->all());

        try {
            $validated = $request->validate([
                'idEmpleado'         => 'required|numeric',
                'idPeriodo'          => 'required|numeric',
                'sueldoBase'         => 'required|numeric',
                'sueldoAsimilado'    => 'nullable|numeric',
                'horasExtras'        => 'nullable|numeric',
                'pagoHorasExtras'    => 'nullable|numeric',
                'pagoHorasExtrasA'   => 'nullable|numeric',
                'diasFestivos'       => 'nullable|numeric',
                'pagoDiasFestivos'   => 'nullable|numeric',
                'pagoDiasFestivosA'  => 'nullable|numeric',
                'diasAusencias'      => 'nullable|numeric',
                'aguinaldo'          => 'nullable|numeric',
                'aguinaldoA'         => 'nullable|numeric',
                'vacaciones'         => 'nullable|numeric',
                'pagoVacaciones'     => 'nullable|numeric',
                'pagoVacacionesA'    => 'nullable|numeric',
                'primaVacacional'    => 'required|numeric',
                'descuentoAusencia'  => 'nullable|numeric',
                'descuentoAusenciaA' => 'nullable|numeric',
                'prestamos'          => 'nullable|numeric',
                'deduccionesExtras'  => 'nullable|json',
                'prestacionesExtras' => 'nullable|json',
                'totalPagar'         => 'required|numeric',
                'totalPagarA'        => 'required|numeric',
                'totalPagarT'        => 'required|numeric',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Error en validación: ', $e->errors());
            return response()->json(['errors' => $e->errors()], 422);
        }

        try {
            // Buscar si ya existe un registro con ese empleado y periodo
            $registro = PreNominaEmpleado::where('id_empleado', $validated['idEmpleado'])
                ->where('id_periodo_nomina', $validated['idPeriodo'])
                ->first();

            // Si no existe, se crea uno nuevo
            if (! $registro) {
                $registro                    = new PreNominaEmpleado();
                $registro->id_empleado       = $validated['idEmpleado'];
                $registro->id_periodo_nomina = $validated['idPeriodo'];
            }

            // Actualizar campos (nuevo o existente)
            $registro->sueldo_base           = $validated['sueldoBase'];
            $registro->sueldo_Asimilado      = $validated['sueldoAsimilado'];
            $registro->horas_extras          = $validated['horasExtras'];
            $registro->pago_horas_extra      = $validated['pagoHorasExtras'];
            $registro->pago_horas_extra_a    = $validated['pagoHorasExtrasA'];
            $registro->dias_festivos         = $validated['diasFestivos'];
            $registro->pago_dias_festivos    = $validated['pagoDiasFestivos'];
            $registro->pago_dias_festivos_a  = $validated['pagoDiasFestivosA'];
            $registro->dias_ausencia         = $validated['diasAusencias'];
            $registro->descuento_ausencias   = $validated['descuentoAusencia'];
            $registro->descuento_ausencias_a = $validated['descuentoAusenciaA'];
            $registro->aguinaldo             = $validated['aguinaldo'];
            $registro->aguinaldo_a           = $validated['aguinaldoA'];
            $registro->dias_vacaciones       = $validated['vacaciones'];
            $registro->pago_vacaciones       = $validated['pagoVacaciones'];
            $registro->pago_vacaciones_a     = $validated['pagoVacacionesA'];
            $registro->prima_vacacional      = $validated['primaVacacional'];
            $registro->prestamos             = $validated['prestamos'];
            $registro->deducciones_extra     = $validated['deduccionesExtras'];
            $registro->prestaciones_extra    = $validated['prestacionesExtras'];
            $registro->sueldo_total          = $validated['totalPagar'];  // O ajusta si usas otro campo
            $registro->sueldo_total_a        = $validated['totalPagarA']; // O ajusta si usas otro campo
            $registro->sueldo_total_t        = $validated['totalPagarT'];

            $registro->save();

            // Actualizar vacaciones si aplica
            if (! empty($validated['vacaciones']) && $validated['vacaciones'] > 0) {
                $laborales = LaboralesEmpleado::where('id_empleado', $validated['idEmpleado'])->first();
                if ($laborales) {
                    $nuevasVacaciones                  = max(0, $laborales->vacaciones_disponibles - $validated['vacaciones']);
                    $laborales->vacaciones_disponibles = $nuevasVacaciones;
                    $laborales->save();
                } else {
                    Log::warning("No se encontró registro laborales_empleado para el empleado {$validated['idEmpleado']}");
                }
            }

            return response()->json(['message' => 'Datos registrados o actualizados correctamente.'], 201);

        } catch (\Exception $e) {
            Log::error('Excepción al guardar o actualizar datos: ' . $e->getMessage());
            return response()->json(['message' => 'Hubo un error al guardar los datos.'], 500);
        }
    }

    public function empleadosMasivoPrenomina(Request $request)
    {
        $idPortal     = (int) $request->input('id_portal');
        $idPeriodo    = $request->filled('id_periodo') ? (int) $request->input('id_periodo') : null;
        $idClienteStr = (string) $request->input('id_cliente');
        $periodicidad = $request->input('periodicidad_pago');

        if (! $idClienteStr || ! $idPortal) {
            return response()->json(['message' => 'Faltan parámetros.'], 400);
        }

        $idClientes = array_filter(array_map('intval', explode(',', $idClienteStr)));
        $cn         = DB::connection('portal_main');

        /**
         * 🔑 SUBQUERY:
         * - Con periodo: prenómina de ese periodo
         * - Sin periodo: subquery “vacía” (para forzar traer TODO de laborales)
         */
        if ($idPeriodo) {
            $sub = $cn->table('pre_nomina_empleados')->where('id_periodo_nomina', $idPeriodo);
        } else {
            // Fuerza que no haya match y por tanto todo vaya a laborales (sin arrastrar última prenómina)
            $sub = $cn->table('pre_nomina_empleados')->whereRaw('1=0');
        }

        $q = $cn->table('empleados as e')
            ->join('laborales_empleado as l', 'l.id_empleado', '=', 'e.id')
            ->join('cliente as c', 'c.id', '=', 'e.id_cliente')
            ->leftJoinSub($sub, 'p', function ($join) {
                $join->on('p.id_empleado', '=', 'e.id');
            })
            ->where('e.status', 1)
            ->where('e.eliminado', 0)
            ->whereIn('e.id_cliente', $idClientes)
            ->where('e.id_portal', $idPortal);

        if ($periodicidad) {
            $q->where('l.periodicidad_pago', $periodicidad);
        }

        /**
         * 🧠 Reglas:
         * - Sueldos base: COALESCE(p, cálculo por periodicidad desde laborales)
         * - Tarifas (pagos/desc): COALESCE(p, laborales)
         * - Contadores y montos variables (horas/días/aguinaldo/…): COALESCE(p, 0)
         * - JSONs: se decodifican abajo (si vienen null, quedan como [])
         */
        $rows = $q->select([
            'e.id',
            'e.id_empleado',
            $cn->raw("CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombre_completo"),

            // Laborales útiles (referencia)
            'l.horas_dia',
            'l.vacaciones_disponibles',
            'l.periodicidad_pago',
            'l.sueldo_diario',
            'l.sueldo_asimilado as sueldo_asimilado_diario',
            'l.pago_hora_extra  as tarifa_hora_extra',
            'l.pago_hora_extra_a as tarifa_hora_extra_a',
            'l.pago_dia_festivo as tarifa_dia_festivo',
            'l.pago_dia_festivo_a as tarifa_dia_festivo_a',
            'l.descuento_ausencia  as tarifa_desc_ausencia',
            'l.descuento_ausencia_a as tarifa_desc_ausencia_a',
            'l.dias_aguinaldo',
            'l.prima_vacacional',

            // Sueldo base / asimilado
            $cn->raw('COALESCE(p.sueldo_base,
            CASE l.periodicidad_pago
                WHEN "02" THEN l.sueldo_diario*7
                WHEN "03" THEN l.sueldo_diario*15
                WHEN "04" THEN l.sueldo_diario*30
                WHEN "05" THEN l.sueldo_diario*60
                ELSE l.sueldo_diario
            END
        ) AS sueldo_base'),

            $cn->raw('COALESCE(p.sueldo_asimilado,
            CASE l.periodicidad_pago
                WHEN "02" THEN l.sueldo_asimilado*7
                WHEN "03" THEN l.sueldo_asimilado*15
                WHEN "04" THEN l.sueldo_asimilado*30
                WHEN "05" THEN l.sueldo_asimilado*60
                ELSE l.sueldo_asimilado
            END
        ) AS sueldo_asimilado'),

            // Tarifas (prioriza periodo; si no hay, laborales)
            $cn->raw('COALESCE(p.pago_horas_extra,     l.pago_hora_extra)    AS pago_hora_extra'),
            $cn->raw('COALESCE(p.pago_horas_extra_a,   l.pago_hora_extra_a)  AS pago_hora_extra_a'),
            $cn->raw('COALESCE(p.pago_dias_festivos,   l.pago_dia_festivo)   AS pago_dia_festivo'),
            $cn->raw('COALESCE(p.pago_dias_festivos_a, l.pago_dia_festivo_a) AS pago_dia_festivo_a'),
            $cn->raw('COALESCE(p.descuento_ausencias,   l.descuento_ausencia)   AS descuento_ausencias'),
            $cn->raw('COALESCE(p.descuento_ausencias_a, l.descuento_ausencia_a) AS descuento_ausencias_a'),
            $cn->raw('COALESCE(p.prima_vacacional, l.prima_vacacional) AS prima_vacacional'),

            // Contadores / montos variables (prioriza periodo; si no hay, 0)
            $cn->raw('COALESCE(p.horas_extras,    0) AS horas_extras'),
            $cn->raw('COALESCE(p.dias_festivos,   0) AS dias_festivos'),
            $cn->raw('COALESCE(p.dias_ausencia,   0) AS dias_ausencia'),
            $cn->raw('COALESCE(p.dias_vacaciones, 0) AS vacaciones'),

            $cn->raw('COALESCE(p.pago_vacaciones,   0) AS pago_vacaciones'),
            $cn->raw('COALESCE(p.pago_vacaciones_a, 0) AS pago_vacaciones_a'),

            $cn->raw('COALESCE(p.aguinaldo,   0) AS aguinaldo'),
            $cn->raw('COALESCE(p.aguinaldo_a, 0) AS aguinaldo_a'),

            $cn->raw('COALESCE(p.prestamos,   0) AS prestamo'),

            // JSONs
            'p.prestaciones_extra',
            'p.deducciones_extra',
            'p.prestaciones_extra_a',
            'p.deducciones_extra_a',

            // Totales (si no hay, 0)
            $cn->raw('COALESCE(p.sueldo_total,0)   AS sueldo_total'),
            $cn->raw('COALESCE(p.sueldo_total_a,0) AS sueldo_total_a'),
            $cn->raw('COALESCE(p.sueldo_total_t,
                  COALESCE(p.sueldo_total,0)+COALESCE(p.sueldo_total_a,0)) AS sueldo_total_t'),

            'c.nombre as nombre_cliente',
        ])->get();

        // Decodificar JSONs con seguridad
        $rows = collect($rows)->map(function ($e) {
            foreach (['prestaciones_extra', 'deducciones_extra', 'prestaciones_extra_a', 'deducciones_extra_a'] as $k) {
                $v     = $e->$k ?? '[]';
                $e->$k = is_string($v) ? (json_decode($v, true) ?: []): (is_array($v) ? $v : []);
            }
            return $e;
        })->values();

        return response()->json($rows);
    }

/**  traer prenomina   anterior
public function empleadosMasivoPrenomina(Request $request)
{
$idPortal     = (int) $request->input('id_portal');
$idPeriodo    = $request->filled('id_periodo') ? (int) $request->input('id_periodo') : null;
$idClienteStr = (string) $request->input('id_cliente');
$periodicidad = $request->input('periodicidad_pago');

if (! $idClienteStr || ! $idPortal) {
return response()->json(['message' => 'Faltan parámetros.'], 400);
}

$idClientes = array_filter(array_map('intval', explode(',', $idClienteStr)));

$cn = DB::connection('portal_main');

// Subquery seguro
if ($idPeriodo) {
$sub = $cn->table('pre_nomina_empleados')->where('id_periodo_nomina', $idPeriodo);
} else {
$sub = $cn->table('pre_nomina_empleados as p1')
->select('p1.*')
->join($cn->raw('(SELECT id_empleado, MAX(id) AS max_id FROM pre_nomina_empleados GROUP BY id_empleado) p2'),
function ($j) {
$j->on('p1.id_empleado', '=', 'p2.id_empleado')
->on('p1.id', '=', 'p2.max_id');
});
}

$q = $cn->table('empleados as e')
->join('laborales_empleado as l', 'l.id_empleado', '=', 'e.id')
->join('cliente as c', 'c.id', '=', 'e.id_cliente')
->leftJoinSub($sub, 'p', function ($join) {
$join->on('p.id_empleado', '=', 'e.id');
})
->where('e.status', 1)
->where('e.eliminado', 0)
->whereIn('e.id_cliente', $idClientes)
->where('e.id_portal', $idPortal);

if ($periodicidad) {
$q->where('l.periodicidad_pago', $periodicidad);
}

$rows = $q->select([
'e.id',
'e.id_empleado',
$cn->raw("CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombre_completo"),

// Info laboral útil para defaults/tarifas
'l.horas_dia',
'l.vacaciones_disponibles',
'l.periodicidad_pago',
'l.sueldo_diario',
'l.sueldo_asimilado as sueldo_asimilado_diario',
'l.pago_hora_extra  as tarifa_hora_extra',
'l.pago_hora_extra_a as tarifa_hora_extra_a',
'l.pago_dia_festivo as tarifa_dia_festivo',
'l.pago_dia_festivo_a as tarifa_dia_festivo_a',
'l.descuento_ausencia  as tarifa_desc_ausencia',
'l.descuento_ausencia_a as tarifa_desc_ausencia_a',

// Sueldos calculados o guardados
$cn->raw('COALESCE(p.sueldo_base,
CASE l.periodicidad_pago
WHEN "02" THEN l.sueldo_diario*7
WHEN "03" THEN l.sueldo_diario*15
WHEN "04" THEN l.sueldo_diario*30
WHEN "05" THEN l.sueldo_diario*60
ELSE l.sueldo_diario
END
) AS sueldo_base'),

$cn->raw('COALESCE(p.sueldo_asimilado,
CASE l.periodicidad_pago
WHEN "02" THEN l.sueldo_asimilado*7
WHEN "03" THEN l.sueldo_asimilado*15
WHEN "04" THEN l.sueldo_asimilado*30
WHEN "05" THEN l.sueldo_asimilado*60
ELSE l.sueldo_asimilado
END
) AS sueldo_asimilado'),

// Campos guardados en prenómina (alias a nombres de UI)
'p.horas_extras                 as horas_extras',
'p.pago_horas_extra             as pago_hora_extra',
'p.pago_horas_extra_a           as pago_hora_extra_a',

'p.dias_festivos                as dias_festivos',
'p.pago_dias_festivos           as pago_dia_festivo',
'p.pago_dias_festivos_a         as pago_dia_festivo_a',

'p.dias_ausencia                as dias_ausencia',
'p.descuento_ausencias          as descuento_ausencias',
'p.descuento_ausencias_a        as descuento_ausencias_a',

// Si tu UI usa 'vacaciones' en vez de 'dias_vacaciones', cambia el alias:
'p.dias_vacaciones              as vacaciones', // <-- cámbialo a "vacaciones" si es necesario
'p.pago_vacaciones              as pago_vacaciones',
'p.pago_vacaciones_a            as pago_vacaciones_a',
'p.prima_vacacional             as prima_vacacional',

'p.prestamos                    as prestamo', // <-- aquí el singular para la UI

'p.aguinaldo                    as aguinaldo',
'p.aguinaldo_a                  as aguinaldo_a',

'p.prestaciones_extra',
'p.deducciones_extra',
'p.prestaciones_extra_a',
'p.deducciones_extra_a',

$cn->raw('COALESCE(p.sueldo_total,0)  as sueldo_total'),
$cn->raw('COALESCE(p.sueldo_total_a,0) as sueldo_total_a'),
$cn->raw('COALESCE(p.sueldo_total_t, COALESCE(p.sueldo_total,0)+COALESCE(p.sueldo_total_a,0)) as sueldo_total_t'),

'c.nombre as nombre_cliente',
])
->get();

// Log: cuántos y primera fila
if ($rows->count()) {
$first = (array) $rows->first();
Log::info('empleadosMasivoPrenomina', [
'count'      => $rows->count(),
'first_keys' => array_keys($first),
'first_row'  => $first,
]);
}

// Decodificar JSONs
$rows = collect($rows)->map(function ($e) {
foreach (['prestaciones_extra', 'deducciones_extra', 'prestaciones_extra_a', 'deducciones_extra_a'] as $k) {
$v     = $e->$k ?? '[]';
$e->$k = is_string($v) ? (json_decode($v, true) ?: []): (is_array($v) ? $v : []);
}
return $e;
})->values();

return response()->json($rows);
}
 */
    // use Illuminate\Http\Request;
    // use Illuminate\Support\Facades\{Log, DB, Validator};
    // use App\Models\{PreNominaEmpleado, LaboralesEmpleado};

    public function guardarPrenominaMasiva(Request $request)
    {
        /* ===== Helpers ===== */
        $num = function ($v) {
            if ($v === null || $v === '') {
                return 0.0;
            }

            if (is_numeric($v)) {
                return (float) $v;
            }

            if (is_string($v)) {
                $v = str_replace(['$', ',', ' '], '', $v);
                return is_numeric($v) ? (float) $v : 0.0;
            }
            if (is_bool($v)) {
                return $v ? 1.0 : 0.0;
            }

            return (float) $v;
        };
        $int = function ($v) use ($num) {return (int) round($num($v));};
        $jsonArr = function ($v) {
            if ($v === null || $v === '' || $v === '{}' || $v === '[]') {
                return [];
            }

            if (is_array($v)) {
                return $v;
            }

            if (is_string($v)) {
                $d = json_decode($v, true);
                return is_array($d) ? $d : [];
            }
            return [];
        };

        /* ===== Logs de entrada ===== */
        Log::info('PrenominaMasiva: headers', $request->headers->all());
        Log::info('PrenominaMasiva: query', $request->query());
        Log::info('PrenominaMasiva: raw', ['raw' => $request->getContent()]);
        Log::info('PrenominaMasiva: body', $request->all());

        /* ===== Validación ===== */
        $validator = Validator::make($request->all(), [
            'id_periodo_nomina'       => 'required|integer|exists:portal_main.periodos_nomina,id',
            'datos'                   => 'required|array|min:1',

            // PK real de empleados (guardada en pre_nomina_empleados.id_empleado)
            'datos.*.id'              => 'required|integer|exists:portal_main.empleados,id',

            // Totales mínimos
            'datos.*.sueldo_base'     => 'required|numeric|min:0',
            'datos.*.sueldo_total'    => 'required|numeric|min:0',
            'datos.*.sueldo_total_a'  => 'required|numeric|min:0',
            'datos.*.sueldo_total_t'  => 'required|numeric|min:0',

            // Alias opcionales
            'datos.*.codigo_empleado' => 'nullable|string',
            'datos.*.id_empleado'     => 'nullable|string',
        ]);

        if ($validator->fails()) {
            Log::error('Validación fallida en prenómina masiva:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $idPeriodoNomina = (int) $request->input('id_periodo_nomina');
        $datos           = $request->input('datos', []);
        Log::info('PrenominaMasiva: datos_count', ['count' => is_array($datos) ? count($datos) : 0]);
        if (is_array($datos) && count($datos) > 0) {
            Log::info('PrenominaMasiva: datos[0]_keys', ['keys' => array_keys($datos[0])]);
            Log::info('PrenominaMasiva: datos[0]_sample', $datos[0]);
        }

        $procesados   = [];
        $errores      = [];
        $creados      = 0;
        $actualizados = 0;

        DB::beginTransaction();
        // Solo depurar PRIMERA FILA
        $soloPrimera = true;

        try {
            foreach ($datos as $index => $item) {
                Log::info("Fila {$index} (raw)", $item);

                // PK real del empleado
                $empleadoIdPk = (int) ($item['id'] ?? 0);

                // Regla: procesamos si PK válida y sueldo_base > 0
                $sueldoBase = $num($item['sueldo_base'] ?? 0);
                if ($empleadoIdPk <= 0 || $sueldoBase <= 0) {
                    Log::warning("Fila {$index} saltada (empleadoIdPk o sueldo_base inválidos)", [
                        'id' => $empleadoIdPk, 'sueldo_base' => $sueldoBase,
                    ]);
                    if ($soloPrimera) {
                        break;
                    }
                    // en modo depuración, terminamos
                    continue;
                }

                $codigoEmpleado = $item['codigo_empleado'] ?? ($item['id_empleado'] ?? null);

                $datosEmpleado = [
                    'id_empleado'           => $empleadoIdPk,
                    'id_periodo_nomina'     => $idPeriodoNomina,

                    'sueldo_base'           => $num($item['sueldo_base'] ?? 0),
                    'sueldo_asimilado'      => $num($item['sueldo_asimilado'] ?? 0),

                    'horas_extras'          => $int($item['horas_extras'] ?? 0),
                    'pago_horas_extra'      => $num($item['pago_hora_extra'] ?? 0),
                    'pago_horas_extra_a'    => $num($item['pago_hora_extra_a'] ?? 0),

                    'dias_festivos'         => $int($item['dias_festivos'] ?? 0),
                    'pago_dias_festivos'    => $num($item['pago_dia_festivo'] ?? 0),
                    'pago_dias_festivos_a'  => $int($item['pago_dia_festivo_a'] ?? 0), // DECIMAL(10,0)

                    'dias_ausencia'         => $int($item['dias_ausencia'] ?? 0),
                    'descuento_ausencias'   => $num($item['descuento_ausencias'] ?? 0),
                    'descuento_ausencias_a' => $num($item['descuento_ausencias_a'] ?? 0),

                    'dias_vacaciones'       => $int($item['dias_vacaciones'] ?? 0),
                    'prima_vacacional'      => $num($item['prima_vacacional'] ?? 0),
                    'pago_vacaciones'       => $num($item['pago_vacaciones'] ?? 0),
                    'pago_vacaciones_a'     => $num($item['pago_vacaciones_a'] ?? 0),

                    'aguinaldo'             => $num($item['aguinaldo'] ?? 0),
                    'aguinaldo_a'           => $num($item['aguinaldo_a'] ?? 0),

                    // FE manda "prestamo" => columna "prestamos"
                    'prestamos'             => $num($item['prestamo'] ?? 0),

                    // JSON (el modelo los castea a array)
                    'prestaciones_extra'    => $jsonArr($item['prestaciones_extra'] ?? []),
                    'deducciones_extra'     => $jsonArr($item['deducciones_extra'] ?? []),
                    'prestaciones_extra_a'  => $jsonArr($item['prestaciones_extra_a'] ?? []),
                    'deducciones_extra_a'   => $jsonArr($item['deducciones_extra_a'] ?? []),

                    'sueldo_total'          => $num($item['sueldo_total'] ?? 0),
                    'sueldo_total_a'        => $num($item['sueldo_total_a'] ?? 0),
                    'sueldo_total_t'        => $num($item['sueldo_total_t'] ?? (
                        $num($item['sueldo_total'] ?? 0) + $num($item['sueldo_total_a'] ?? 0)
                    )),

                    'creacion'              => now(),
                    'edicion'               => now(),
                ];

                Log::info("Fila {$index} (mapeado)", $datosEmpleado);

                try {
                    // --- Guardado robusto ---
                    $valores = $datosEmpleado;
                    unset($valores['id_empleado'], $valores['id_periodo_nomina']);

                    $registro = PreNominaEmpleado::updateOrCreate(
                        [
                            'id_empleado'       => $empleadoIdPk,
                            'id_periodo_nomina' => $idPeriodoNomina,
                        ],
                        $valores
                    );

                    // Verificar lo que quedó en BD
                    $confirm = $registro->fresh(['id', 'dias_vacaciones', 'prestamos', 'pago_vacaciones', 'pago_vacaciones_a']);
                    Log::info("Post-save (fresh)", [
                        'id'                => $confirm->id,
                        'dias_vacaciones'   => $confirm->dias_vacaciones,
                        'prestamos'         => $confirm->prestamos,
                        'pago_vacaciones'   => $confirm->pago_vacaciones,
                        'pago_vacaciones_a' => $confirm->pago_vacaciones_a,
                    ]);

                    // Si por alguna razón vienen nulos/0, probamos un forceFill (descarta problemas de $fillable)
                    if ((int) $confirm->dias_vacaciones !== (int) $datosEmpleado['dias_vacaciones']
                        || (string) $confirm->prestamos !== number_format($datosEmpleado['prestamos'], 2, '.', '')
                    ) {
                        Log::warning('Valores no coinciden tras updateOrCreate. Intentando forceFill().', [
                            'esperado_dias_vac'  => $datosEmpleado['dias_vacaciones'],
                            'esperado_prestamos' => $datosEmpleado['prestamos'],
                            'actual_dias_vac'    => $confirm->dias_vacaciones,
                            'actual_prestamos'   => $confirm->prestamos,
                        ]);

                        $registro->forceFill($valores);
                        $registro->save();

                        $confirm2 = $registro->fresh(['id', 'dias_vacaciones', 'prestamos']);
                        Log::info('Post-save (forceFill) ->', [
                            'id'              => $confirm2->id,
                            'dias_vacaciones' => $confirm2->dias_vacaciones,
                            'prestamos'       => $confirm2->prestamos,
                        ]);
                    }

                    // (Opcional) Ajuste de vacaciones disponibles
                    if ($datosEmpleado['dias_vacaciones'] > 0) {
                        $laborales = LaboralesEmpleado::where('id_empleado', $empleadoIdPk)->first();
                        if ($laborales) {
                            $antes     = (int) $laborales->vacaciones_disponibles;
                            $nuevoDisp = max(0, $antes - (int) $datosEmpleado['dias_vacaciones']);
                            $laborales->update(['vacaciones_disponibles' => $nuevoDisp]);

                            $procesados[] = [
                                'empleado_pk'             => $empleadoIdPk,
                                'codigo_empleado'         => $codigoEmpleado,
                                'accion'                  => $registro->wasRecentlyCreated ? 'creado' : 'actualizado',
                                'id_registro'             => $registro->id,
                                'vacaciones_actualizadas' => [
                                    'anterior'        => $antes,
                                    'nueva'           => $nuevoDisp,
                                    'dias_utilizados' => (int) $datosEmpleado['dias_vacaciones'],
                                ],
                            ];
                        } else {
                            $procesados[] = [
                                'empleado_pk'     => $empleadoIdPk,
                                'codigo_empleado' => $codigoEmpleado,
                                'accion'          => $registro->wasRecentlyCreated ? 'creado' : 'actualizado',
                                'id_registro'     => $registro->id,
                                'nota'            => 'Sin laborales_empleado; no se ajustaron vacaciones disponibles',
                            ];
                            Log::warning("No se encontró laborales_empleado para empleado PK {$empleadoIdPk}");
                        }
                    } else {
                        $procesados[] = [
                            'empleado_pk'     => $empleadoIdPk,
                            'codigo_empleado' => $codigoEmpleado,
                            'accion'          => $registro->wasRecentlyCreated ? 'creado' : 'actualizado',
                            'id_registro'     => $registro->id,
                        ];
                    }

                    // 🔎 Solo primera fila para depurar
                    if ($soloPrimera) {
                        DB::commit();
                        return response()->json([
                            'success' => true,
                            'message' => 'Prenómina (fila de depuración) guardada',
                            'data'    => [
                                'creados'         => $registro->wasRecentlyCreated ? 1 : 0,
                                'actualizados'    => $registro->wasRecentlyCreated ? 0 : 1,
                                'procesados'      => $procesados,
                                'errores_detalle' => $errores,
                            ],
                        ], 200);
                    }

                } catch (\Exception $e) {
                    Log::error("Error procesando empleado PK {$empleadoIdPk}: {$e->getMessage()}", [
                        'datos' => $datosEmpleado,
                        'error' => $e->getMessage(),
                    ]);

                    $errores[] = [
                        'empleado_pk'     => $empleadoIdPk,
                        'codigo_empleado' => $codigoEmpleado,
                        'error'           => $e->getMessage(),
                    ];
                }
            } // foreach

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Prenómina procesada exitosamente',
                'data'    => [
                    'creados'         => $creados,
                    'actualizados'    => $actualizados,
                    'errores'         => count($errores),
                    'procesados'      => $procesados,
                    'errores_detalle' => $errores,
                ],
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en prenómina masiva:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar prenómina masiva: ' . $e->getMessage(),
            ], 500);
        }
    }

}
