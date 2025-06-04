<?php
namespace App\Http\Controllers\Empleados;

use App\Exports\CargaMasivaPlantillaExport;
use App\Exports\EmpleadosGeneralExport;
use App\Exports\EmpleadosLaboralesExport;
use App\Exports\EmpleadosMedicalExport;
use App\Http\Controllers\Controller;
use App\Imports\EmpleadosGeneralImport;
use App\Imports\EmpleadosImport;
use App\Imports\EmpleadosLaboralesImport;
use App\Imports\MedicalInfoImport;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $validator = Validator::make($request->all(), [
            'file'       => 'required|file|mimes:xlsx,csv',
            'creacion'   => 'required|date',
            'edicion'    => 'required|date',
            'id_portal'  => 'required|numeric',
            'id_usuario' => 'required|numeric',
            'id_cliente' => 'required|numeric',
        ]);

        if (! $request->hasFile('file')) {
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

        $file = $request->file('file');

        $generalData = [
            'creacion'   => $request->input('creacion'),
            'edicion'    => $request->input('edicion'),
            'id_portal'  => $request->input('id_portal'),
            'id_usuario' => $request->input('id_usuario'),
            'id_cliente' => $request->input('id_cliente'),
        ];

        try {
            // Leer las cabeceras del archivo
            $rawHeadings = Excel::toArray([], $file)[0][0] ?? [];

            // Filtrar solo cabeceras que sean strings y no estén vacías
            $filteredRawHeadings = array_filter($rawHeadings, function ($item) {
                return is_string($item) && trim($item) !== '';
            });

            $headings = array_map('strtolower', array_map('trim', $filteredRawHeadings));

            // Cabeceras esperadas
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

            $normalizeHeaders = function ($headers) {
                return array_map(function ($header) {
                    return strtolower(trim($header));
                }, $headers);
            };

            $normalizedHeadings         = $normalizeHeaders($headings);
            $normalizedExpectedHeadings = $normalizeHeaders($expectedHeadings);

            // Detectar diferencias
            $extraHeadings   = array_diff($normalizedHeadings, $normalizedExpectedHeadings);
            $missingHeadings = array_diff($normalizedExpectedHeadings, $normalizedHeadings);

            // Verificamos si hay diferencia
            if (! empty($missingHeadings)) {
                \Log::error('Faltan cabeceras obligatorias.', [
                    'cabeceras_detectadas' => $headings,
                    'cabeceras_esperadas'  => $expectedHeadings,
                    'cabeceras_faltantes'  => $missingHeadings,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Faltan columnas obligatorias en el archivo.',
                    'errors'  => ['file' => 'El archivo no contiene todas las columnas requeridas.'],
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
                'duplicados'       => $duplicados,
            ], 200);
        } catch (\Exception $e) {
            \Log::error('Error al importar el archivo:', ['mensaje' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al importar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function downloadTemplateMedical(Request $request)
    {
        $empleadoId = $request->query('empleado_id');
        // Log::info('ID recibido desde el frontend:', ['empleado_id' => $empleadoId]);

        if (! $empleadoId) {
            Log::warning('No se proporcionó ID de empleado en la solicitud');
            return response()->json(['error' => 'ID de empleado no proporcionado'], 400);
        }

        $empleado = Empleado::with('cliente')->findOrFail($empleadoId);
        // Log::info('Empleado encontrado:', $empleado->toArray());

        $cliente = $empleado->cliente;
        // Log::info('Cliente del empleado:', $cliente ? $cliente->toArray() : 'No encontrado');

        if (! $cliente) {
            return response()->json(['error' => 'Cliente no encontrado para el empleado'], 404);
        }

        // Obtener empleados del mismo cliente con la información médica
        $empleados = DB::connection('portal_main')->table('empleados')
            ->leftJoin('medical_info', 'empleados.id', '=', 'medical_info.id_empleado')
            ->where('empleados.id_cliente', $cliente->id)
            ->where('empleados.status', 1)
            ->where('empleados.eliminado', 0)
            ->select([
                'empleados.id',
                'empleados.id_empleado',
                DB::raw("CONCAT_WS(' ', empleados.nombre, empleados.paterno, empleados.materno) as nombre_completo"),
                // Campos de medical_info excepto creacion y edicion
                'medical_info.id_empleado',
                'medical_info.peso',
                'medical_info.edad',
                'medical_info.alergias_medicamentos',
                'medical_info.alergias_alimentos',
                'medical_info.enfermedades_cronicas',
                'medical_info.cirugias',
                'medical_info.tipo_sangre',
                'medical_info.contacto_emergencia',
                'medical_info.medicamentos_frecuentes',
                'medical_info.lesiones',
                'medical_info.otros_padecimientos',
                'medical_info.otros_padecimientos2',
            ])
            ->get();
        //Log::info('Datos médicos de empleados:', $empleados->toArray());

        return Excel::download(new EmpleadosMedicalExport($empleados), 'plantilla_informacion_medica.xlsx');
    }

    public function downloadTemplateGeneral(Request $request)
    {
        $empleadoId = $request->query('empleado_id');

        if (! $empleadoId) {
            Log::warning('No se proporcionó ID de empleado en la solicitud');
            return response()->json(['error' => 'ID de empleado no proporcionado'], 400);
        }

        $empleado = Empleado::with('cliente')->findOrFail($empleadoId);

        $cliente = $empleado->cliente;

        if (! $cliente) {
            return response()->json(['error' => 'Cliente no encontrado para el empleado'], 404);
        }

        // Obtener empleados activos del mismo cliente
        $empleados = Empleado::on('portal_main')
            ->with([
                'camposExtra'       => function ($query) {
                    $query->select('id_empleado', 'nombre', 'valor');
                },
                'domicilioEmpleado' => function ($query) {
                    $query->select([
                        'id', 'pais', 'estado', 'ciudad', 'colonia', 'calle', 'num_int', 'num_ext',
                        'cp',
                    ]);
                },
            ])
            ->where('id_cliente', $cliente->id)
            ->where('status', 1)
            ->where('eliminado', 0)
            ->get([
                'id',
                'id_empleado',
                'id_domicilio_empleado', // <- ¡necesario para la relación!
                'nombre',
                'paterno',
                'materno',
                'telefono',
                'correo',
                'rfc',
                'curp',
                'nss',
                'departamento',
                'puesto',
                'fecha_nacimiento',

            ]);
        //Log::info('Datos médicos de empleados:', $empleados->toArray());
        // exit;
        return Excel::download(new EmpleadosGeneralExport($empleados), 'plantilla_general_info.xlsx');
    }

    public function downloadTemplateLaboral(Request $request)
    {
        $id_cliente = $request->query('id_cliente');

        if (! $id_cliente) {
            Log::warning('No se proporcionó ID del cliente');
            return response()->json(['error' => 'ID de cliente no proporcionado'], 400);
        }

        // Obtener empleados del mismo cliente con la información laboral
        $empleados = DB::connection('portal_main')->table('empleados')
            ->leftJoin('laborales_empleado as LAB', 'empleados.id', '=', 'LAB.id_empleado')
            ->where('empleados.id_cliente', $id_cliente)
            ->where('empleados.status', 1)
            ->where('empleados.eliminado', 0)
            ->select([
                'empleados.id',
                'empleados.id_empleado',
                DB::raw("CONCAT_WS(' ', empleados.nombre, empleados.paterno, empleados.materno) as nombre_completo"),

                // Campos de LAB
                'LAB.tipo_contrato',
                'LAB.otro_tipo_contrato',
                'LAB.tipo_regimen',
                'LAB.tipo_jornada',
                'LAB.horas_dia',
                'LAB.grupo_nomina',
                'LAB.periodicidad_pago',
                'LAB.tipo_nomina',
                'LAB.dias_descanso',
                'LAB.vacaciones_disponibles',
                'LAB.sueldo_diario',
                'LAB.pago_dia_festivo',
                'LAB.pago_hora_extra',
                'LAB.dias_aguinaldo',
                'LAB.prima_vacacional',
                'LAB.descuento_ausencia',
            ])
            ->get();

        return Excel::download(new EmpleadosLaboralesExport($empleados), 'plantilla_informacion_laboral.xlsx');
    }

    public function importGeneralInfo(Request $request)
    {
        if (! $request->hasFile('file')) {
            return response()->json(['error' => 'No se proporcionó un archivo'], 400);
        }
        if (! $request->has('id_cliente')) {
            return response()->json(['error' => 'No esta  asociado a una sucursal refresque la  pagina  e intentelo nuevamente'], 400);
        }

        $id_cliente = $request->input('id_cliente');
        try {
            Excel::import(new EmpleadosGeneralImport($id_cliente), $request->file('file'));
            return response()->json(['success' => 'Información actualizada correctamente']);
        } catch (\Exception $e) {
            Log::error('Error al importar archivo Excel: ' . $e->getMessage());
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    public function uploadMedicalInfo(Request $request)
    {
        $request->validate([
            'file'   => 'required|mimes:xlsx,xls',
            'id_rol' => 'required|integer',
        ]);
        if (! $request->hasFile('file')) {
            return response()->json(['error' => 'No se proporcionó un archivo'], 400);
        }
        if (! $request->has('id_cliente')) {
            return response()->json(['error' => 'No esta  asociado a una sucursal refresque la  pagina  e intentelo nuevamente'], 400);
        }
        $id_cliente = $request->input('id_cliente');

        try {
            Excel::import(new MedicalInfoImport($id_cliente), $request->file('file'));

            return response()->json(['message' => 'Información médica actualizada correctamente.']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

    public function uploadLaboralesInfo(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv',
        ]);
         if (! $request->hasFile('file')) {
            return response()->json(['error' => 'No se proporcionó un archivo'], 400);
        }
        if (! $request->has('id_cliente')) {
            return response()->json(['error' => 'No esta  asociado a una sucursal refresque la  pagina  e intentelo nuevamente'], 400);
        }

     $id_cliente = $request->input('id_cliente');

        try {
            Excel::import(new EmpleadosLaboralesImport($id_cliente), $request->file('file'));

            return response()->json(['message' => 'Importación completada correctamente.'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Error al procesar el archivo: ' . $e->getMessage()], 500);
        }
    }

}
