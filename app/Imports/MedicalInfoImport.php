<?php
namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MedicalInfoImport implements ToCollection, WithHeadingRow
{
    protected $idCliente;

    public function __construct($idCliente)
    {
        $this->idCliente = $idCliente;
    }
    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) {
            throw new \Exception("El archivo está vacío.");
        }

        // Cabeceras obligatorias (normalizadas como aparecen en el Excel)
        $cabecerasObligatorias = [
            'id_empleado',
            'peso',
            'edad',
            'tipo_sangre',
        ];

        // Obtenemos las cabeceras desde la primera fila
        $cabecerasArchivo = array_keys($rows->first()->toArray());
        //Log::info('Cabeceras recibidas desde Excel:', $cabecerasArchivo);

        // Normalizamos las cabeceras del archivo
        $cabecerasArchivoNorm = array_map(function ($val) {
            return mb_strtolower(trim($val));
        }, $cabecerasArchivo);

        $cabecerasFaltantes = [];
        foreach ($cabecerasObligatorias as $esperada) {
            if (! in_array($esperada, $cabecerasArchivoNorm)) {
                $cabecerasFaltantes[] = $esperada;
            }
        }

        if (! empty($cabecerasFaltantes)) {
            $faltantesStr = implode(", ", $cabecerasFaltantes);
            throw new \Exception(
                "El archivo seleccionado no es válido. Faltan campos clave. \n " .
                "Por favor, tenga cuidado y asegúrese de cargar el archivo correcto con el formato esperado."
            );
        }

        foreach ($rows as $row) {
            $idEmpleado = $row['id'] ?? null;
            if (! $idEmpleado) {
                continue;
            }
// Validar que el empleado pertenezca al cliente
            $empleado = DB::connection('portal_main')
                ->table('empleados')
                ->where('id', $idEmpleado)
                ->where('id_cliente', $this->idCliente)
                ->first();

            if (! $empleado) {
                throw new \Exception(
                    "El archivo no se puede importar porque contiene empleados que no pertenecen a la sucursal actual. " .
                    "Verifica que estás usando el archivo correcto para  esta  sucursal."
                );
            }

            $data = collect($row)->map(function ($value) {
                $value = is_string($value) ? trim($value) : $value;
                return $value === '--' ? null : $value;
            });
            DB::connection('portal_main')->table('medical_info')->updateOrInsert(
                ['id_empleado' => $idEmpleado],
                [
                    'peso'                    => $data['peso'] ?? null,
                    'edad'                    => $data['edad'] ?? null,
                    'alergias_medicamentos'   => $data['alergias_medicamentos'] ?? null,
                    'alergias_alimentos'      => $data['alergias_alimentos'] ?? null,
                    'enfermedades_cronicas'   => $data['enfermedades_cronicas'] ?? null,
                    'cirugias'                => $data['cirugias'] ?? null,
                    'tipo_sangre'             => $data['tipo_sangre'] ?? null,
                    'contacto_emergencia'     => $data['contacto_emergencia'] ?? null,
                    'medicamentos_frecuentes' => $data['medicamentos_frecuentes'] ?? null,
                    'lesiones'                => $data['lesiones'] ?? null,
                    'otros_padecimientos'     => $data['otros_padecimientos'] ?? null,
                    'otros_padecimientos2'    => $data['otros_padecimientos_2'] ?? null,
                ]
            );
        }
    }
}
