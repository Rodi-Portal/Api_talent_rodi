<?php
namespace App\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;

class MedicalInfoImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        // Saltamos la primera fila (encabezados)
        $rows->shift();

        foreach ($rows as $row) {
            $idEmpleado = $row[0]; // Columna A (oculta)

            // Reemplazar "--" por null
            $data = $row->map(function ($value) {
                return trim($value) === '--' ? null : $value;
            });

            DB::connection('portal_main')->table('medical_info')->updateOrInsert(
                ['id_empleado' => $idEmpleado],
                [
                    'peso'                    => $data[3] ?? null,
                    'edad'                    => $data[4] ?? null,
                    'alergias_medicamentos'   => $data[5] ?? null,
                    'alergias_alimentos'      => $data[6] ?? null,
                    'enfermedades_cronicas'   => $data[7] ?? null,
                    'cirugias'                => $data[8] ?? null,
                    'tipo_sangre'             => $data[9] ?? null,
                    'contacto_emergencia'     => $data[10] ?? null,
                    'medicamentos_frecuentes' => $data[11] ?? null,
                    'lesiones'                => $data[12] ?? null,
                    'otros_padecimientos'     => $data[13] ?? null,
                    'otros_padecimientos2'    => $data[14] ?? null,
                ]
            );
        }
    }

}
