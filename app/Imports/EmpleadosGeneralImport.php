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
        $campo = mb_strtolower($campo, 'UTF-8');
        $campo = preg_replace('/\s+/', '_', $campo);
        $campo = preg_replace('/[^a-z0-9_]/', '', $campo);
        return $campo;
    }

    protected function esValorParaEliminar($valor): bool
    {
        if ($valor === null) {
            return true;
        }

        $str = trim((string) $valor);
        $strSinEspacios = preg_replace('/\s+/', '', $str);
        $strUpper = strtoupper($strSinEspacios);

        $valoresEliminar = ['', '--', 'BORRAR'];

        return in_array($strUpper, $valoresEliminar, true);
    }

    public function collection(Collection $rows)
    {
        $cabecerasObligatorias = ['id', 'nombre', 'paterno', 'telefono', 'correo'];

        $primeraFila = $rows->first();
        $cabecerasArchivo = array_map(function ($campo) {
            return $this->normalizarCampo($campo);
        }, array_keys($primeraFila->toArray()));

        foreach ($cabecerasObligatorias as $campoEsperado) {
            $campoEsperadoNorm = $this->normalizarCampo($campoEsperado);
            if (!in_array($campoEsperadoNorm, $cabecerasArchivo)) {
              throw new \Exception(
                "El archivo seleccionado no es válido para  actualizar Informacion General. Faltan campos clave. \n " .
                "Por favor, tenga cuidado y asegúrese de cargar el archivo correcto con el formato esperado."
            );
            }
        }

        $columnasFijasNorm = array_map([$this, 'normalizarCampo'], $this->columnasFijas);
        Log::debug('Columnas fijas normalizadas: ' . json_encode($columnasFijasNorm));

        foreach ($rows as $row) {
            try {
                $filaNorm = [];
                $valoresOriginales = [];

                foreach ($row as $campo => $valor) {
                    $campoNorm = $this->normalizarCampo($campo);
                    $valoresOriginales[$campo] = $valor;
                    $filaNorm[$campoNorm] = $valor;
                }

                $empleadoId = $filaNorm['id'] ?? null;
                if (!$empleadoId) continue;

                $empleado = Empleado::find($empleadoId);
                if (!$empleado) continue;

                $datosActualizar = [];

                foreach ($columnasFijasNorm as $campoFijo) {
                    if ($campoFijo === 'id') continue;

                    if ($campoFijo === 'fecha_nacimiento') {
                        $fechaRaw = $filaNorm[$campoFijo] ?? null;

                        if ($this->esValorParaEliminar($fechaRaw)) {
                            $datosActualizar[$campoFijo] = null;
                        } else {
                            try {
                                if (is_numeric($fechaRaw)) {
                                    if ($fechaRaw > 59) {
                                        $fechaRaw -= 1;
                                    }
                                    $fecha = Carbon::createFromTimestamp(($fechaRaw - 25569) * 86400);
                                } else {
                                    $formatos = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y', 'm-d-Y', 'd M Y', 'd-M-Y', 'd F Y', 'Ymd', 'd.m.Y', 'Y.m.d'];
                                    $fecha = null;
                                    foreach ($formatos as $formato) {
                                        try {
                                            $fecha = Carbon::createFromFormat($formato, $fechaRaw);
                                            if ($fecha) break;
                                        } catch (\Exception $e) {
                                            continue;
                                        }
                                    }
                                    if (!$fecha) {
                                        throw new \Exception("Formato de fecha no reconocido");
                                    }
                                }
                                $datosActualizar[$campoFijo] = $fecha->format('Y-m-d');
                            } catch (\Exception $e) {
                                Log::warning("Fecha inválida para empleado {$empleado->id}: '{$fechaRaw}', error: " . $e->getMessage());
                                $datosActualizar[$campoFijo] = null;
                            }
                        }
                        continue;
                    }

                    if (in_array($campoFijo, ['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'])) {
                        continue;
                    }

                    $valor = $filaNorm[$campoFijo] ?? null;
                    $datosActualizar[$campoFijo] = $this->esValorParaEliminar($valor) ? null : trim((string) $valor);
                }

                $empleado->update($datosActualizar);

                // Actualiza domicilio si existe
                if ($empleado->domicilioEmpleado) {
                    $datosDomicilio = [];
                    foreach (['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'] as $campoDomicilio) {
                        $valor = $filaNorm[$campoDomicilio] ?? null;
                        $datosDomicilio[$campoDomicilio] = $this->esValorParaEliminar($valor) ? null : trim((string) $valor);
                    }
                    $empleado->domicilioEmpleado->update($datosDomicilio);
                }

                // Campos extras
                $camposExtraDelArchivo = [];
                foreach ($valoresOriginales as $campoOriginal => $valorOriginal) {
                    $campoNorm = $this->normalizarCampo($campoOriginal);
                    Log::debug("Revisando campo extra: original = {$campoOriginal}, normalizado = {$campoNorm}");

                    if (in_array($campoNorm, $columnasFijasNorm)) continue;

                    $valorLimpio = $this->esValorParaEliminar($valorOriginal) ? null : trim((string) $valorOriginal);
                    $camposExtraDelArchivo[] = $campoNorm;

                    if ($valorLimpio === null) {
                        $campoExtra = EmpleadoCampoExtra::where('id_empleado', $empleado->id)
                            ->get()
                            ->first(function ($item) use ($campoOriginal) {
                                return $this->normalizarCampo($item->nombre) === $this->normalizarCampo($campoOriginal);
                            });

                        if ($campoExtra) {
                            $campoExtra->delete();
                        }
                    } else {
                        EmpleadoCampoExtra::updateOrCreate(
                            ['id_empleado' => $empleado->id, 'nombre' => $campoOriginal],
                            ['valor' => $valorLimpio]
                        );
                    }
                }

                // Eliminar campos extra que no estén presentes en el archivo actual
                $camposExtraBD = EmpleadoCampoExtra::where('id_empleado', $empleado->id)->get();
                foreach ($camposExtraBD as $campoBD) {
                    $nombreNorm = $this->normalizarCampo($campoBD->nombre);
                    if (!in_array($nombreNorm, $camposExtraDelArchivo)) {
                        $campoBD->delete();
                    }
                }

            } catch (\Exception $e) {
                Log::error("Error importando fila con ID {$row['id']}: " . $e->getMessage());
            }
        }
    }
}
