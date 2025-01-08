<?php

namespace App\Http\Controllers\Empleados;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\Response;
use App\Imports\EmpleadosImport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Exports\CargaMasivaPlantillaExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller; 
use Maatwebsite\Excel\Facades\Excel;// Importa correctamente el controlador base

class CsvController extends Controller
{
    public function downloadTemplate()
    {
        // Aquí defines los encabezados para la descarga de Excel
        $encabezados = [
            'First Name*', 'Last Name*', 'Middle Name', 'Phone*', 'Email', 'Position', 
            'CURP', 'NSS', 'RFC', 'Employee ID', 'Street', 
            'Exterior Number', 'Interior Number', 'Neighborhood', 'City', 'State', 
            'Country', 'Postal Code'
        ];

        // Llamar a la clase de exportación para la plantilla
        return Excel::download(new CargaMasivaPlantillaExport($encabezados), 'plantilla_carga_masiva.xlsx');
    }


    public function import(Request $request)
    {
        // Validar que se haya subido un archivo y los datos generales
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,csv',
            'creacion' => 'required|date',
            'edicion' => 'required|date',
            'id_portal' => 'required|numeric',
            'id_usuario' => 'required|numeric',
            'id_cliente' => 'required|numeric',
        ]);
    
    
        // Registrar detalles del archivo si está presente
        if ($request->hasFile('file')) {
            $file = $request->file('file');
          
        } else {
            \Log::error('No se detectó archivo en la solicitud.');
        }
    
        // Verificar si la validación falla
        if ($validator->fails()) {
           
    
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación.',
                'errors' => $validator->errors(),
            ], 422);
        }
    
        // Datos generales
        $generalData = [
            'creacion' => $request->input('creacion'),
            'edicion' => $request->input('edicion'),
            'id_portal' => $request->input('id_portal'),
            'id_usuario' => $request->input('id_usuario'),
            'id_cliente' => $request->input('id_cliente'),
        ];
    
        try {
            // Leer cabeceras del archivo para validar
            $file = $request->file('file');
            $headings = Excel::toArray([], $file)[0][0] ?? [];
    
            // Registrar cabeceras detectadas
    
            // Definir las cabeceras esperadas
            $expectedHeadings = [
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
    
            if ($headings !== $expectedHeadings) {
                \Log::error('Cabeceras no coinciden con las esperadas.', [
                    'cabeceras_detectadas' => $headings,
                    'cabeceras_esperadas' => $expectedHeadings,
                ]);
    
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no tiene las cabeceras esperadas.',
                    'errors' => [
                        'file' => 'Las cabeceras del archivo no coinciden con el formato esperado.'
                    ]
                ], 422);
            }
    
            // Importar el archivo Excel
            Excel::import(new EmpleadosImport($generalData), $file);
    
            return response()->json([
                'success' => true,
                'message' => 'Empleados importados correctamente',
            ], 200);
        } catch (\Exception $e) {
            // Registrar errores
            \Log::error('Error al importar el archivo:', [
                'mensaje' => $e->getMessage(),
                'traza' => $e->getTraceAsString(),
            ]);
    
            return response()->json([
                'success' => false,
                'message' => 'Error al importar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }
    
    
    
}