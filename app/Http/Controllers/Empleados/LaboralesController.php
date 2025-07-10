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
        $request->validate([
            'id_empleado'           => 'required|exists:portal_main.empleados,id',
            'grupoNomina'           => 'string|max:255',
            'horasDia'              => 'numeric|min:0',
            'diasAguinaldo'         => 'numeric|min:0',
            'descuentoAusencia'     => 'numeric|min:0',
            'descuentoAusenciaA'    => 'nullable|numeric|min:0',
            'primaVacacional'       => 'numeric|min:0',
            'otroTipoContrato'      => 'nullable|string|max:255',
            'pagoDiaFestivo'        => 'required|numeric|min:0',
            'pagoDiaFestivoA'       => 'nullable|numeric|min:0',
            'pagoHoraExtra'         => 'required|numeric|min:0',
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
        dd($request->all());
        $empleado = Empleado::find($request->id_empleado);

        if (! $empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }
        // \Log::debug('Datos recibidos en guardarDatosLaborales:', $request->all());

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
        $idCliente = $request->input('id_cliente');
        $idPortal  = $request->input('id_portal');
        $idPeriodo = $request->input('id_periodo');

        if (! $idCliente || ! $idPortal) {
            return response()->json(['message' => 'Faltan parámetros.'], 400);
        }

        // Subquery condicional para LEFT JOIN
        $subquery = '';

        if ($idPeriodo) {
            // Traer datos de ese periodo
            $subquery = "
            SELECT *
            FROM pre_nomina_empleados
            WHERE id_periodo_nomina = $idPeriodo
        ";
        } else {
            // Traer el último registro por empleado
            $subquery = "
            SELECT p1.*
            FROM pre_nomina_empleados p1
            INNER JOIN (
                SELECT id_empleado, MAX(id) AS max_id
                FROM pre_nomina_empleados
                GROUP BY id_empleado
            ) p2 ON p1.id_empleado = p2.id_empleado AND p1.id = p2.max_id
        ";
        }

        // Consulta principal
        $empleados = DB::connection('portal_main')
            ->table('empleados as e')
            ->join('laborales_empleado as l', 'l.id_empleado', '=', 'e.id')
            ->leftJoin(DB::raw("($subquery) AS p"), 'p.id_empleado', '=', 'e.id')
            ->where('e.status', 1)
            ->where('e.eliminado', 0)
            ->where('e.id_cliente', $idCliente)
            ->where('e.id_portal', $idPortal)
            ->select(
                'e.id',
                'e.id_empleado',
                DB::raw("CONCAT_WS(' ', e.nombre, e.paterno, e.materno) AS nombre_completo"),
                'l.horas_dia',
                'l.vacaciones_disponibles',
                'l.sueldo_diario',
                'l.sueldo_asimilado',
                'l.periodicidad_pago',
                'l.pago_dia_festivo',
                'l.dias_aguinaldo',
                'l.pago_hora_extra',
                'l.pago_hora_extra_a',
                'l.pago_dia_festivo',
                'l.pago_dia_festivo_a',
                'l.prima_vacacional',
                'l.prestamo_pendiente',
                'l.descuento_ausencia',
                'l.descuento_ausencia_a',
                'p.horas_extras',
                'p.prestamos',
                'p.dias_festivos',
                'p.dias_ausencia',
                'p.dias_vacaciones',
                'p.prestaciones_extra',
                'p.deducciones_extra',
                'p.prestaciones_extra_a',
                'p.deducciones_extra_a',
            )
            ->get();
        //Log::info('Empleados:', $empleados->toArray());
        $empleados = collect(json_decode(json_encode($empleados), false));

        // Procesamiento y cálculo
        $empleados = $empleados->map(function ($empleado) {

            // Decodificar los campos si son strings (a veces llegan ya como arrays)
            foreach (['prestaciones_extra', 'deducciones_extra', 'prestaciones_extra_a', 'deducciones_extra_a'] as $campo) {
                $valor = $empleado->$campo ?? '[]';

                if (is_string($valor)) {
                    $empleado->$campo = json_decode($valor, true) ?? [];
                } elseif (is_array($valor)) {
                    $empleado->$campo = $valor;
                } else {
                    $empleado->$campo = [];
                }
            }

            // Realiza los cálculos y campos extra como sueldo_base, sueldo_asim
            $sueldoDiario    = floatval($empleado->sueldo_diario);
            $sueldoAsimilado = floatval($empleado->sueldo_asimilado);
            $periodicidad    = $empleado->periodicidad_pago;

            $sueldoBase = 0;
            $sueldoAsim = 0;

            switch ($periodicidad) {
                case '01': // Diario
                case '07': // Honorarios
                case '08': // Asimilados
                case '09': // Otros
                    $sueldoBase = $sueldoDiario;
                    $sueldoAsim = $sueldoAsimilado;
                    break;
                case '02': // Semanal
                    $sueldoBase = $sueldoDiario * 7;
                    $sueldoAsim = $sueldoAsimilado * 7;
                    break;
                case '03': // Quincenal
                    $sueldoBase = $sueldoDiario * 15;
                    $sueldoAsim = $sueldoAsimilado * 15;
                    break;
                case '04': // Mensual
                    $sueldoBase = $sueldoDiario * 30;
                    $sueldoAsim = $sueldoAsimilado * 30;
                    break;
                case '05': // Bimestral
                    $sueldoBase = $sueldoDiario * 60;
                    $sueldoAsim = $sueldoAsimilado * 60;
                    break;
                default:
                    $sueldoBase = 0;
                    $sueldoAsim = 0;
            }

            $empleado->sueldo_base = round($sueldoBase, 2);
            $empleado->sueldo_asim = round($sueldoAsim, 2);

            return $empleado;
        });
        Log::info('Empleados:', $empleados->map(fn($e) => (array) $e)->values()->all());

        return response()->json($empleados->toArray());
    }

    public function guardarPrenominaMasiva(Request $request)
    {
        /* Log::info('Datos recibidos para prenómina masiva:', [
            'id_periodo_nomina' => $request->input('id_periodo_nomina'),
            'datos'             => $request->input('datos'),
        ]); */

        // Validación inicial
        $validator = Validator::make($request->all(), [
            'id_periodo_nomina'      => 'required|integer|exists:portal_main.periodos_nomina,id',
            'datos'                  => 'required|array|min:1',
            'datos.*.id'             => 'required|integer', // ID del empleado como string
            'datos.*.sueldo_base'    => 'required|numeric|min:0',
            'datos.*.sueldo_total'   => 'required|numeric|min:0',
            'datos.*.sueldo_total_a' => 'required|numeric|min:0',
            'datos.*.sueldo_total_t' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            Log::error('Validación fallida en prenómina masiva:', $validator->errors()->toArray());
            return response()->json([
                'success' => false,
                'message' => 'Datos inválidos',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $idPeriodoNomina = $request->input('id_periodo_nomina');
        $datos           = $request->input('datos');

        $procesados   = [];
        $errores      = [];
        $actualizados = 0;
        $creados      = 0;

        DB::beginTransaction();

        try {
            foreach ($datos as $index => $item) {
                // Saltar registros sin empleado o con sueldo base 0
                if (empty($item['id_empleado']) || $item['sueldo_base'] <= 0) {
                    //  Log::info("Saltando registro {$index}: empleado vacío o sueldo base 0", $item);
                    continue;
                }

                // Mapear campos del request al modelo
                $datosEmpleado = [
                    'id_empleado'          => $item['id'],
                    'id_periodo_nomina'    => $idPeriodoNomina,
                    'sueldo_base'          => $item['sueldo_base'] ?? 0,
                    'horas_extras'         => $item['horas_extras'] ?? 0,
                    'pago_horas_extra'     => $item['pago_hora_extra'] ?? 0,
                    'dias_festivos'        => $item['dias_festivos'] ?? 0,
                    'pago_dias_festivos'   => $item['pago_dia_festivo'] ?? 0,
                    'dias_ausencia'        => $item['dias_ausencia'] ?? 0,
                    'descuento_ausencias'  => $item['descuento_ausencias'] ?? 0, // Ya viene correcto
                    'pago_vacaciones'      => $item['pago_vacaciones'] ?? 0,
                    'dias_vacaciones'      => $item['dias_vacaciones'] ?? 0,
                    'aguinaldo'            => $item['aguinaldo'] ?? 0,
                    'prestamos'            => $item['prestamo'] ?? 0,
                    'prestaciones_extra'   => is_string($item['prestaciones_extra']) ? $item['prestaciones_extra'] : json_encode($item['prestaciones_extra'] ?? []),
                    'deducciones_extra'    => is_string($item['deducciones_extra']) ? $item['deducciones_extra'] : json_encode($item['deducciones_extra'] ?? []),
                    'prestaciones_extra_a' => is_string($item['prestaciones_extra_a']) ? $item['prestaciones_extra_a'] : json_encode($item['prestaciones_extra_a'] ?? []),
                    'deducciones_extra_a'  => is_string($item['deducciones_extra_a']) ? $item['deducciones_extra_a'] : json_encode($item['deducciones_extra_a'] ?? []),

                    'creacion'             => now(),
                    'edicion'              => now(),
                ];

                // Los cálculos ya vienen hechos desde el frontend
                $datosEmpleado['sueldo_total']   = $item['sueldo_total'] ?? 0;
                $datosEmpleado['sueldo_total_a'] = $item['sueldo_total_a'] ?? 0;
                $datosEmpleado['sueldo_total_t'] = $item['sueldo_total_t'] ?? 0;
                try {
                    // Verificar si ya existe un registro para este empleado y período
                    $existente = PreNominaEmpleado::where('id_empleado', $item['id'])
                        ->where('id_periodo_nomina', $idPeriodoNomina)
                        ->first();

                    if ($existente) {
                        // Actualizar registro existente
                        $datosEmpleado['edicion'] = now();
                        $existente->update($datosEmpleado);
                        $actualizados++;
                        $procesados[] = [
                            'id_empleado' => $item['id_empleado'],
                            'accion'      => 'actualizado',
                            'id_registro' => $existente->id,
                        ];
                    } else {
                        // Crear nuevo registro
                        $nuevo = PreNominaEmpleado::create($datosEmpleado);
                        $creados++;
                        $procesados[] = [
                            'id_empleado' => $item['id_empleado'],
                            'accion'      => 'creado',
                            'id_registro' => $nuevo->id,
                        ];
                    }

                    // Actualizar vacaciones disponibles si hay días de vacaciones
                    if (isset($item['dias_vacaciones']) && $item['dias_vacaciones'] > 0) {
                        $diasVacaciones = intval($item['dias_vacaciones']);

                        $laboralesEmpleado = LaboralesEmpleado::where('id_empleado', $item['id'])
                            ->first();

                        if ($laboralesEmpleado) {
                            $vacacionesDisponibles       = $laboralesEmpleado->vacaciones_disponibles;
                            $nuevasVacacionesDisponibles = max(0, $vacacionesDisponibles - $diasVacaciones);

                            $laboralesEmpleado->update([
                                'vacaciones_disponibles' => $nuevasVacacionesDisponibles,
                            ]);

                            /* Log::info("Vacaciones actualizadas para empleado {$item['id']}: {$vacacionesDisponibles} -> {$nuevasVacacionesDisponibles}"); */

                            $procesados[count($procesados) - 1]['vacaciones_actualizadas'] = [
                                'anterior'        => $vacacionesDisponibles,
                                'nueva'           => $nuevasVacacionesDisponibles,
                                'dias_utilizados' => $diasVacaciones,
                            ];
                        } else {
                            Log::warning("No se encontró registro LaboralesEmpleado para empleado {$item['id']}");
                        }
                    }

                } catch (\Exception $e) {
                    Log::error("Error procesando empleado {$item['id_empleado']}: {$e->getMessage()}", [
                        'datos' => $datosEmpleado,
                        'error' => $e->getMessage(),
                    ]);

                    $errores[] = [
                        'id_empleado' => $item['id_empleado'],
                        'error'       => $e->getMessage(),
                    ];
                }
            }

            DB::commit();

            /*  Log::info('Prenómina masiva procesada exitosamente:', [
                'periodo'      => $idPeriodoNomina,
                'creados'      => $creados,
                'actualizados' => $actualizados,
                'errores'      => count($errores),
            ]); */

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