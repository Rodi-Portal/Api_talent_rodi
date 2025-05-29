<?php
namespace App\Imports;

use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmpleadosGeneralImport implements ToCollection, WithHeadingRow
{
    protected $columnasFijas = [
        'id', 'id_empleado', 'nombre', 'paterno', 'materno',
        'telefono', 'correo', 'rfc', 'curp', 'nss',
        'departamento', 'puesto', 'fecha_nacimiento',
        'pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad',
    ];

    protected function normalizarCampo(string $campo): string
    {
        $campo = trim($campo);
        $campo = strtolower($campo);
        return str_replace(' ', '_', $campo);
    }

    public function collection(Collection $rows)
    {
        $columnasFijasNorm = array_map([$this, 'normalizarCampo'], $this->columnasFijas);

        foreach ($rows as $row) {
            try {
                $filaNorm          = [];
                $valoresOriginales = [];
                foreach ($row as $campo => $valor) {
                    $campoNorm                 = $this->normalizarCampo($campo);
                    $valoresOriginales[$campo] = $valor;
                    $filaNorm[$campoNorm]      = $valor;
                }

                // Buscar ID empleado para actualizar
                $empleadoId = $filaNorm['id'] ?? ($filaNorm['id_empleado'] ?? null);

                if (! $empleadoId) {
                    //::warning("Fila ignorada por no tener ID o id_empleado.");
                    continue;
                }

                $empleado = Empleado::find($empleadoId);

                if (! $empleado) {
                    //Log::warning("Empleado con ID {$empleadoId} no encontrado, fila ignorada.");
                    continue;
                }

                // Actualizar campos fijos (excepto id) con null si vienen vacíos o con '--'
                $datosActualizar = [];
                foreach ($columnasFijasNorm as $campoFijo) {
                    if ($campoFijo === 'id') {
                        continue;
                    }

                    // Manejar fecha_nacimiento con Carbon

                    if ($campoFijo === 'fecha_nacimiento') {
                        $fechaRaw = $filaNorm[$campoFijo] ?? null;

                        if (empty($fechaRaw) || $fechaRaw === '--') {
                            $datosActualizar[$campoFijo] = null;
                        } else {
                            // Log::info("Fecha raw para empleado {$empleado->id}: '{$fechaRaw}'");

                            try {
                                if (is_numeric($fechaRaw)) {
                                    // Convertir número Excel a fecha (si el número es razonable)
                                    if ($fechaRaw > 59) {
                                        // Corregir bug excel para año bisiesto falso en 1900
                                        $fechaRaw -= 1;
                                    }
                                    $fecha = Carbon::createFromTimestamp(($fechaRaw - 25569) * 86400);
                                    //Log::info("Fecha convertida desde número Excel para empleado {$empleado->id}: " . $fecha->toDateString());
                                } else {
                                    // Intentar parsear como d/m/Y, d-m-Y o Y-m-d, más flexible
                                    $formatos = [
                                        'd/m/Y', // 31/12/2020
                                        'd-m-Y', // 31-12-2020
                                        'Y-m-d', // 2020-12-31
                                        'Y/m/d', // 2020/12/31
                                        'm/d/Y', // 12/31/2020
                                        'm-d-Y', // 12-31-2020
                                        'd M Y', // 31 Dec 2020
                                        'd-M-Y', // 31-Dec-2020
                                        'd F Y', // 31 December 2020
                                        'Ymd',   // 20201231
                                        'd.m.Y', // 31.12.2020
                                        'Y.m.d', // 2020.12.31
                                    ];

                                    $fecha = null;
                                    foreach ($formatos as $formato) {
                                        try {
                                            $fecha = Carbon::createFromFormat($formato, $fechaRaw);
                                            if ($fecha) {
                                                break;
                                            }

                                        } catch (\Exception $e) {
                                            // Intentar siguiente formato
                                        }
                                    }
                                    if (! $fecha) {
                                        throw new \Exception("Formato fecha no reconocido");
                                    }

                                    //  Log::info("Fecha parseada desde texto para empleado {$empleado->id}: " . $fecha->toDateString());
                                }
                                $datosActualizar[$campoFijo] = $fecha->format('Y-m-d');
                            } catch (\Exception $e) {
                                Log::warning("Fecha inválida para empleado {$empleado->id}: '{$fechaRaw}', error: " . $e->getMessage());
                                $datosActualizar[$campoFijo] = null;
                            }
                        }
                        continue;
                    }

                    // Excluir los campos domicilio para actualizarlos por separado
                    if (in_array($campoFijo, ['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'])) {
                        continue;
                    }

                    $valor                       = $filaNorm[$campoFijo] ?? null;
                    $valorLimpio                 = ($valor === '' || $valor === '--') ? null : trim($valor);
                    $datosActualizar[$campoFijo] = $valorLimpio;
                }

                // Actualizamos empleado
                $empleado->update($datosActualizar);

                // Ahora actualizamos domicilio si existe
                if ($empleado->domicilioEmpleado) {
                    $datosDomicilio = [];
                    foreach (['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'] as $campoDomicilio) {
                        $valor                           = $filaNorm[$campoDomicilio] ?? null;
                        $valorLimpio                     = ($valor === '' || $valor === '--') ? null : trim($valor);
                        $datosDomicilio[$campoDomicilio] = $valorLimpio;
                    }
                    $empleado->domicilioEmpleado->update($datosDomicilio);
                    //Log::info("Domicilio actualizado para empleado {$empleado->id}");
                } else {
                    // Log::info("Empleado {$empleado->id} no tiene domicilio asociado para actualizar.");
                }

                // Campos extra: actualizar o crear nuevos campos extra
                foreach ($valoresOriginales as $campoOriginal => $valorOriginal) {
                    $campoNorm = $this->normalizarCampo($campoOriginal);

                    if (in_array($campoNorm, $columnasFijasNorm)) {
                        continue;
                    }
                    // no es campo extra

                    $valorLimpio = ($valorOriginal === '' || $valorOriginal === '--' || $valorOriginal === null) ? null : trim($valorOriginal);

                    if ($valorLimpio === null) {
                        // Eliminar campo extra para el empleado
                        EmpleadoCampoExtra::where('id_empleado', $empleado->id)
                            ->whereRaw("LOWER(REPLACE(TRIM(nombre), ' ', '_')) = ?", [$this->normalizarCampo($campoOriginal)])
                            ->delete();
                        Log::info("Campo extra eliminado: Empleado {$empleado->id}, Campo '{$campoOriginal}'");
                    } else {
                        // Actualizar o crear campo extra
                        EmpleadoCampoExtra::updateOrCreate(
                            ['id_empleado' => $empleado->id, 'nombre' => $campoOriginal],
                            ['valor' => $valorLimpio]
                        );
                        Log::info("Campo extra actualizado o creado: Empleado {$empleado->id}, Campo '{$campoOriginal}', Valor: {$valorLimpio}");
                    }
                }

                Log::info("Empleado {$empleado->id} actualizado correctamente.");

            } catch (\Exception $e) {
                Log::error("Error importando fila con ID {$row['id']}: " . $e->getMessage());
            }
        }
    }
}
