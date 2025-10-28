<?php
namespace App\Imports;

use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmpleadosGeneralImport implements ToCollection, WithHeadingRow
{
    protected $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }
    protected array $aliasDb = [
        'fecha_ingreso' => 'fecha_ingreso', // o 'created_at' si usas timestamps estándar
    ];
    protected $columnasFijas = [
        'id', 'id_empleado', 'nombre', 'paterno', 'materno',
        'telefono', 'correo', 'rfc', 'curp', 'nss',
        'departamento', 'puesto', 'fecha_nacimiento', 'fecha_ingreso',
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

        $str            = trim((string) $valor);
        $strSinEspacios = preg_replace('/\s+/', '', $str);
        $strUpper       = strtoupper($strSinEspacios);

        $valoresEliminar = ['', '--', 'BORRAR'];
        return in_array($strUpper, $valoresEliminar, true);
    }

    public function collection(Collection $rows)
    {
        $cabecerasObligatorias = ['id', 'nombre', 'paterno', 'telefono', 'correo'];

        $primeraFila      = $rows->first();
        $cabecerasArchivo = array_map(function ($campo) {
            return $this->normalizarCampo($campo);
        }, array_keys($primeraFila->toArray()));

        foreach ($cabecerasObligatorias as $campoEsperado) {
            $campoEsperadoNorm = $this->normalizarCampo($campoEsperado);
            if (! in_array($campoEsperadoNorm, $cabecerasArchivo)) {
                throw new \Exception(
                    "El archivo seleccionado no es válido para actualizar Información General. " .
                    "Faltan campos clave. Verifique que cargó el archivo correcto con el formato esperado."
                );
            }
        }

        // ===== Validación previa: existencia y pertenencia de cliente
        $idsInvalidos = [];
        foreach ($rows as $row) {
            // Con WithHeadingRow, $row trae keys ya “slugificadas” (minúsculas con _).
            $empleadoId = $row['id'] ?? null;

            if (! $empleadoId) {$idsInvalidos[] = '[sin ID]';
                continue;}

            $empleado = Empleado::find($empleadoId);
            if (! $empleado || (isset($this->idCliente) && $empleado->id_cliente != $this->idCliente)) {
                $idsInvalidos[] = $empleadoId;
            }
        }

        if (count($idsInvalidos) > 0) {
            throw new \Exception(
                "El archivo no se puede importar porque contiene empleados que no pertenecen a la sucursal/cliente actual. " .
                "Verifique que usa el archivo correcto y que corresponde a la sucursal seleccionada."
            );
        }

        $columnasFijasNorm = array_map([$this, 'normalizarCampo'], $this->columnasFijas);
        Log::debug('Columnas fijas normalizadas: ' . json_encode($columnasFijasNorm));

        foreach ($rows as $row) {
            try {
                // ===== Normaliza fila
                $filaNorm          = [];
                $valoresOriginales = [];
                foreach ($row as $campo => $valor) {
                    $campoNorm                 = $this->normalizarCampo($campo);
                    $valoresOriginales[$campo] = $valor;
                    $filaNorm[$campoNorm]      = $valor;
                }

                // >>>>>>>>>>>>>>>>>>>> FIX 1: definir $empleadoId
                $empleadoId = $filaNorm['id'] ?? ($row['id'] ?? null);
                if (! $empleadoId) {continue;}

                $empleado = Empleado::find($empleadoId);
                if (! $empleado) {continue;}

                $datosActualizar = [];

                foreach ($columnasFijasNorm as $campoFijo) {
                    if ($campoFijo === 'id') {
                        continue;
                    }

                    // Nombre real en BD (si hay alias, úsalo)
                    $columnaDb = $this->aliasDb[$campoFijo] ?? $campoFijo;

                    // fechas: nacimiento
                    if ($campoFijo === 'fecha_nacimiento') {
                        $fechaRaw                    = $filaNorm[$campoFijo] ?? null;
                        $datosActualizar[$columnaDb] = $this->parseFechaNullable($fechaRaw);
                        continue;
                    }

                    // fechas: ingreso (mapeará a 'creacion' o 'created_at')
                    if ($campoFijo === 'fecha_ingreso') {
                        $fechaRaw2   = $filaNorm[$campoFijo] ?? null;
                        $fechaParsed = $this->parseFechaNullable($fechaRaw2);

                        // Si tu columna es DATETIME y quieres hora fija:
                        // $fechaParsed = $fechaParsed ? ($fechaParsed.' 00:00:00') : null;

                        $datosActualizar[$columnaDb] = $fechaParsed;
                        continue;
                    }

                    // domicilio va por relación (sáltalo)
                    if (in_array($campoFijo, ['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'])) {
                        continue;
                    }

                    // otros campos
                    $valor                       = $filaNorm[$campoFijo] ?? null;
                    $datosActualizar[$columnaDb] = $this->esValorParaEliminar($valor) ? null : trim((string) $valor);
                }

                // Actualiza (revisa fillable en el modelo)
                if (! empty($datosActualizar)) {
                    $empleado->update($datosActualizar);
                }

                // ===== Domicilio (solo si existe la relación)
                if ($empleado->domicilioEmpleado) {
                    $datosDomicilio = [];
                    foreach (['pais', 'calle', 'num_int', 'num_ext', 'colonia', 'estado', 'cp', 'ciudad'] as $campoDomicilio) {
                        $valor                           = $filaNorm[$campoDomicilio] ?? null;
                        $datosDomicilio[$campoDomicilio] = $this->esValorParaEliminar($valor) ? null : trim((string) $valor);
                    }
                    $empleado->domicilioEmpleado->update($datosDomicilio);
                }

                // ===== Campos extras (NO borrar los que no vengan, a menos que lo pidas explícitamente)
                $camposExtraDelArchivo = [];
                foreach ($valoresOriginales as $campoOriginal => $valorOriginal) {
                    $campoNorm = $this->normalizarCampo($campoOriginal);

                    if (in_array($campoNorm, $columnasFijasNorm)) {
                        continue;
                    }

                    $valorLimpio             = $this->esValorParaEliminar($valorOriginal) ? null : trim((string) $valorOriginal);
                    $camposExtraDelArchivo[] = $campoNorm;

                    // >>>>>>>>>>>>>>>>>>>> FIX 3: buscar por nombre normalizado
                    $campoExistente = EmpleadoCampoExtra::where('id_empleado', $empleado->id)->get()
                        ->first(function ($item) use ($campoOriginal) {
                            return $this->normalizarCampo($item->nombre) === $this->normalizarCampo($campoOriginal);
                        });

                    if ($valorLimpio === null) {
                        if ($campoExistente) {
                            $campoExistente->delete();
                        }

                    } else {
                        if ($campoExistente) {
                            $campoExistente->valor = $valorLimpio;
                            // Mantén el nombre original tal como vino en Excel
                            $campoExistente->nombre = $campoExistente->nombre;
                            $campoExistente->save();
                        } else {
                            EmpleadoCampoExtra::create([
                                'id_empleado' => $empleado->id,
                                'nombre'      => $campoOriginal, // guarda el rotulo visible del Excel
                                'valor'       => $valorLimpio,
                            ]);
                        }
                    }
                }

                // >>>>>>>>>>>>>>>>>>>> FIX 4: no eliminar “todo lo que no venga”
                // Si realmente lo necesitas, protégelo con una bandera y úsalo conscientemente.

            } catch (\Exception $e) {
                Log::error("Error importando fila con ID " . ($row['id'] ?? 'N/A') . ": " . $e->getMessage());
            }
        }
    }
    protected function parseFechaNullable($raw): ?string
    {
        if ($this->esValorParaEliminar($raw)) {
            return null;
        }

        try {
            if (is_numeric($raw)) {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($raw);
                return \Carbon\Carbon::instance($dt)->format('Y-m-d');
            }
            $formatos = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'Y/m/d', 'm/d/Y', 'm-d-Y', 'd M Y', 'd-M-Y', 'd F Y', 'Ymd', 'd.m.Y', 'Y.m.d'];
            foreach ($formatos as $f) {
                try {
                    $fecha = \Carbon\Carbon::createFromFormat($f, trim((string) $raw));
                    if ($fecha) {
                        return $fecha->format('Y-m-d');
                    }

                } catch (\Exception $e) {}
            }
            throw new \Exception('Formato de fecha no reconocido');
        } catch (\Exception $e) {
            \Log::warning("Fecha inválida: '{$raw}', error: " . $e->getMessage());
            return null;
        }
    }

}
