<?php

namespace App\Imports;

use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\MedicalInfo;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;

class EmpleadosImport implements ToModel
{
    protected $generalData;

    public function __construct($generalData)
    {
        $this->generalData = $generalData;
    }

    public function model(array $row)
    {
        try {

            if ($this->isHeaderRow($row)) {
                return null; // No procesar esta fila
            }
            // Validar campos obligatorios
            if (empty($row[0]) || empty($row[1]) || empty($row[3])) {
                throw new \Exception('Faltan campos obligatorios en la fila: ' . json_encode($row));
            }

            // Preparar datos con valores opcionales
            $validatedData = [
                'nombre' => strtoupper($row[0]), // First Name*
                'paterno' => strtoupper($row[1]), // Last Name*
                'materno' => strtoupper($row[2] ?? null), // Middle Name
                'telefono' => $row[3], // Phone*
                'correo' => $row[4] ?? null, // Email
                'puesto' => strtoupper($row[5] ?? null), // Position
                'curp' => strtoupper($row[6] ?? null), // CURP
                'nss' => $row[7] ?? null, // NSS
                'rfc' => strtoupper($row[8] ?? null), // RFC
                'id_empleado' => $row[9], // Employee ID*
                'domicilio_empleado' => [
                    'calle' => $row[10] ?? null, // Street
                    'num_ext' => $row[11] ?? null, // Exterior Number
                    'num_int' => $row[12] ?? null, // Interior Number
                    'colonia' => $row[13] ?? null, // Neighborhood
                    'ciudad' => $row[14] ?? null, // City
                    'estado' => $row[15] ?? null, // State
                    'pais' => $row[16] ?? null, // Country
                    'cp' => $row[17] ?? null, // Postal Code
                ],
            ];

            // Crear el domicilio solo si hay datos
            $domicilio = DomicilioEmpleado::create($validatedData['domicilio_empleado']);

            // Crear el empleado
            $empleado = Empleado::create([
                'creacion' => $this->generalData['creacion'],
                'edicion' => $this->generalData['edicion'],
                'id_portal' => $this->generalData['id_portal'],
                'id_usuario' => $this->generalData['id_usuario'],
                'id_cliente' => $this->generalData['id_cliente'],
                'id_empleado' => $validatedData['id_empleado'],
                'correo' => $validatedData['correo'],
                'curp' => $validatedData['curp'],
                'nombre' => $validatedData['nombre'],
                'nss' => $validatedData['nss'],
                'rfc' => $validatedData['rfc'],
                'paterno' => $validatedData['paterno'],
                'materno' => $validatedData['materno'],
                'puesto' => $validatedData['puesto'],
                'telefono' => $validatedData['telefono'],
                'id_domicilio_empleado' => $domicilio->id,
                'status' => 1,
                'eliminado' => 0,
            ]);

            // Crear información médica
            
                
                $fechaCreacion = Carbon::parse($this->generalData['creacion']);
                

                MedicalInfo::create([
                    'id_empleado' => $empleado->id,
                    'creacion' => $this->generalData['creacion'],
                    'edicion' => $this->generalData['creacion'],
                    
                ]);
            

            return $empleado;
        } catch (\Exception $e) {
            // Registrar errores en el log
            Log::error('Error al procesar fila: ' . json_encode($row) . ' | Error: ' . $e->getMessage());
            return null; // Continuar con las siguientes filas
        }
    }

    private function isHeaderRow($row)
    {
        // Aquí puedes agregar lógica para verificar si la fila contiene las cabeceras
        // Por ejemplo, verificar si los valores corresponden a las cabeceras esperadas:
        $expectedHeaders = [
            'First Name*',
            'Last Name*',
            'Middle Name',
            'Phone*',
            'Email',
            'Position',
            'CURP',
            'NSS',
            'RFC',
            'Employee ID',
            'Street',
            'Exterior Number',
            'Interior Number',
            'Neighborhood',
            'City',
            'State',
            'Country',
            'Postal Code'
        ];
    
        return $row === $expectedHeaders;
    }
}
