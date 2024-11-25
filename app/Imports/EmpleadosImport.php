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
            // Validar campos obligatorios
            if (empty($row[0]) || empty($row[1]) || empty($row[3]) ) {
                throw new \Exception('Faltan campos obligatorios en la fila: ' . json_encode($row));
            }
    
            // Preparar datos con valores opcionales
            $validatedData = [
              'nombre' => strtoupper($row[0]), // First Name*
                'paterno' => strtoupper($row[1]),// Last Name*
                'materno' => strtoupper($row[2] ?? null), // Middle Name
                'telefono' => $row[3], // Phone*
                'correo' => $row[4] ?? null, // Email
                'puesto' => strtoupper($row[5] ?? null), // Position
                'fecha_nacimiento' => $row[6] ?? null, // Date of Birth
                'curp' => strtoupper($row[7] ?? null), // CURP
                'nss' => $row[8] ?? null, // NSS
                'rfc' => strtoupper($row[9] ?? null), // RFC
                'id_empleado' => $row[10], // Employee ID*
                'domicilio_empleado' => [
                    'calle' => $row[11] ?? null, // Street
                    'num_ext' => $row[12] ?? null, // Exterior Number
                    'num_int' => $row[13] ?? null, // Interior Number
                    'colonia' => $row[14] ?? null, // Neighborhood
                    'ciudad' => $row[15] ?? null, // City
                    'estado' => $row[16] ?? null, // State
                    'pais' => $row[17] ?? null, // Country
                    'cp' => $row[18] ?? null, // Postal Code
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
                'fecha_nacimiento' => $validatedData['fecha_nacimiento'],
                'telefono' => $validatedData['telefono'],
                'id_domicilio_empleado' => $domicilio->id,
                'status' => 1,
                'eliminado' => 0,
            ]);
    
            // Crear información médica
            if (!empty($validatedData['fecha_nacimiento'])) {
                $fechaNacimiento = Carbon::parse($validatedData['fecha_nacimiento']);
                $fechaCreacion = Carbon::parse($this->generalData['creacion']);
                $edad = $fechaCreacion->diffInYears($fechaNacimiento);
    
                MedicalInfo::create([
                    'id_empleado' => $empleado->id,
                    'creacion' => $this->generalData['creacion'],
                    'edicion' => $this->generalData['creacion'],
                    'edad' => $edad,
                ]);
            }
    
            return $empleado;
        } catch (\Exception $e) {
            // Registrar errores en el log
            Log::error('Error al procesar fila: ' . json_encode($row) . ' | Error: ' . $e->getMessage());
            return null; // Continuar con las siguientes filas
        }
    }
}
