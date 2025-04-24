<?php
namespace App\Http\Controllers\Empleados;

use App\Exports\CargaMasivaPlantillaExport;
use App\Http\Controllers\Controller;
use App\Imports\EmpleadosImport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

// Importa correctamente el controlador base

class CsvController extends Controller
{
    public function downloadTemplate()
    {
        // Aquí defines los encabezados para la descarga de Excel
        $encabezados = [
            'Nombre*',
            'Apellido Paterno*',
            'Apellido Materno',
            'Teléfono*',
            'Correo Electrónico',
            'Puesto',
            'CURP',
            'NSS',
            'RFC',
            'ID Empleado',
            'Calle',
            'Número Exterior',
            'Número Interior',
            'Colonia',
            'Ciudad',
            'Estado',
            'País',
            'Código Postal',
        ];

        // Llamar a la clase de exportación para la plantilla
        return Excel::download(new CargaMasivaPlantillaExport($encabezados), 'plantilla_carga_masiva.xlsx');
    }

    public function import(Request $request)
    {
        $errors = [];

        // Validar que se haya subido un archivo y los datos generales
        $validator = Validator::make($request->all(), [
            'file'       => 'required|file|mimes:xlsx,csv',
            'creacion'   => 'required|date',
            'edicion'    => 'required|date',
            'id_portal'  => 'required|numeric',
            'id_usuario' => 'required|numeric',
            'id_cliente' => 'required|numeric',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
        } else {
            \Log::error('No se detectó archivo en la solicitud.');
            return response()->json([
                'success' => false,
                'message' => 'No se detectó archivo en la solicitud.',
            ], 422);
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $generalData = [
            'creacion'   => $request->input('creacion'),
            'edicion'    => $request->input('edicion'),
            'id_portal'  => $request->input('id_portal'),
            'id_usuario' => $request->input('id_usuario'),
            'id_cliente' => $request->input('id_cliente'),
        ];

        try {
            // Leer cabeceras del archivo
            $headings         = array_map('strtolower', array_map('trim', Excel::toArray([], $file)[0][0] ?? []));
            $expectedHeadings = [
                'nombre*',
                'apellido paterno*',
                'apellido materno',
                'teléfono*',
                'correo electrónico',
                'puesto',
                'curp',
                'nss',
                'rfc',
                'id empleado',
                'calle',
                'número exterior',
                'número interior',
                'colonia',
                'ciudad',
                'estado',
                'país',
                'código postal',
            ];

            // Compara las cabeceras de forma flexible
            if ($headings !== array_map('strtolower', array_map('trim', $expectedHeadings))) {
                \Log::error('Cabeceras no coinciden con las esperadas.');
                return response()->json([
                    'success' => false,
                    'message' => 'El archivo no tiene las cabeceras esperadas.',
                    'errors'  => ['file' => 'Las cabeceras del archivo no coinciden con el formato esperado.'],
                ], 422);
            }

            // Importar los datos
            
            $import = new EmpleadosImport($generalData);
            
            Excel::import($import, $file);

            $duplicados      = $import->getDuplicados();
            $totalInsertados = $import->getInsertados();

            return response()->json([
                'success'          => true,
                'message'          => $totalInsertados . ' Empleados importados correctamente',
                'total_duplicados' => count($duplicados),
                'duplicados'       => $duplicados, // Puedes mostrar esto en el frontend
            ], 200);
        } catch (\Exception $e) {
            // Registrar errores
            \Log::error('Error al importar el archivo:', ['mensaje' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al importar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

}
