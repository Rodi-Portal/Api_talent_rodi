<?php
namespace App\Imports;

use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use App\Models\MedicalInfo;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmpleadosImport implements ToModel, WithHeadingRow
{
    protected $generalData;
    protected $duplicados = [];
    protected $insertados = 0;
    protected $rowNumber  = 1;

    // Este mapa puede ser extendido dinámicamente si el usuario agrega nuevas columnas
    protected $columnMap = [
        'nombre'      => ['nombre', 'first name', 'first_name', 'name'],
        'paterno'     => ['apellido paterno', 'paterno', 'apellido_paterno', 'last name', 'lastname', 'last_name'],
        'materno'     => ['apellido materno', 'materno', 'apellido_materno', 'middle name', 'middle_name'],
        'telefono'    => ['telefono', 'teléfono', 'phone'],
        'correo'      => ['correo', 'email', 'correo_electrónico', 'correo_electronico'],
        'puesto'      => ['puesto', 'position', 'cargo'],
        'curp'        => ['curp'],
        'nss'         => ['nss', 'numero seguro social', 'social security'],
        'rfc'         => ['rfc'],
        'id_empleado' => ['id empleado', 'id_empleado', 'employee id'],
        'calle'       => ['calle', 'street'],
        'num_ext'     => ['numero exterior', 'num_ext', 'exterior number', 'numero_exterior'],
        'num_int'     => ['numero interior', 'num_int', 'interior number', 'numero_interior'],
        'colonia'     => ['colonia', 'neighborhood'],
        'ciudad'      => ['ciudad', 'city'],
        'estado'      => ['estado', 'state'],
        'pais'        => ['pais', 'país', 'country'],
        'cp'          => ['cp', 'codigo_postal', 'codigo postal', 'postal code', 'zip code'],
    ];

    public function __construct($generalData)
    {
        $this->generalData = $generalData;
    }

    public function getDuplicados()
    {
        return $this->duplicados;
    }

    public function getInsertados()
    {
        return $this->insertados;
    }
    // Método fuzzyMatch para la comparación de cadenas
    public function fuzzyMatch($string1, $string2)
    {
        return levenshtein($string1, $string2) <= 2; // Ajusta el umbral según la tolerancia deseada
    }

    public function model(array $row)
    {
        if ($this->rowNumber === 1) {
            // Mostrar las cabeceras detectadas para depuración
            Log::info("Cabeceras detectadas: " . json_encode(array_keys($row)));
        }
        $this->rowNumber++;

        // Función para obtener el valor de una columna basada en su alias, con tolerancia a diferencias de formato
        $get = function ($key) use ($row) {
            $key = strtolower(trim($key));

            $cabecerasDisponibles = array_keys($row);

            foreach ($this->columnMap[$key] ?? [] as $alias) {
                $alias = strtolower(trim($alias));

                // Intentar coincidencia EXACTA primero
                foreach ($cabecerasDisponibles as $header) {
                    if (strtolower(trim($header)) === $alias) {
                        return $row[$header];
                    }
                }

                // Luego intentar coincidencia difusa (solo si no es un campo crítico)
                if (! in_array($key, ['paterno', 'materno', 'curp', 'cp', 'rfc', 'nss'])) {
                    foreach ($cabecerasDisponibles as $header) {
                        if ($this->fuzzyMatch($alias, strtolower(trim($header)))) {
                            return $row[$header];
                        }
                    }
                }
            }

            return null;
        };

        // Función para hacer una comparación difusa entre alias y cabeceras
        // Esto puede utilizar un algoritmo como Levenshtein para comprobar la similitud
        $this->fuzzyMatch = function ($string1, $string2) {
                                                         // Usamos la distancia de Levenshtein para determinar la similitud entre cadenas
            return levenshtein($string1, $string2) <= 2; // Ajusta el umbral según la tolerancia deseada
        };

        // Obtener los valores de las columnas
        $nombre  = trim($get('nombre'));
        $paterno = trim($get('paterno'));

        // Validar si el nombre y paterno no están vacíos
        if (empty($nombre) || empty($paterno)) {
            Log::warning("Fila inválida (nombre o paterno vacío): " . json_encode($row));
            return null;
        }

        // Validación del correo
        $correo = $get('correo');
        if ($correo && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Log::warning("Correo no válido: $correo");
            return null;
        }

        // Detectar campos extra que no están en el mapeo
        $mapeados     = collect($this->columnMap)->flatten()->map(fn($a) => strtolower($a))->toArray();
        $camposExtras = [];
        foreach ($row as $key => $valor) {
            if (! in_array(strtolower(trim($key)), $mapeados)) {
                $camposExtras[trim($key)] = $valor;
            }
        }
        $materno = $get('materno');
        $materno = $materno ? mb_strtoupper($materno, 'UTF-8') : null;
        // Validar y preparar los datos para el empleado
        $validatedData = [
            'nombre'             => mb_strtoupper($nombre, 'UTF-8'),
            'paterno'            => mb_strtoupper($paterno, 'UTF-8'),
            'materno'            => $materno,
            'telefono'           => $get('telefono'),
            'correo'             => $correo,
            'puesto'             => mb_strtoupper($get('puesto'), 'UTF-8'),
            'curp'               => mb_strtoupper($get('curp'), 'UTF-8'),
            'nss'                => $get('nss'),
            'rfc'                => mb_strtoupper($get('rfc'), 'UTF-8'),
            'id_empleado'        => $get('id_empleado'),
            'domicilio_empleado' => [
                'calle'   => $get('calle'),
                'num_ext' => $get('num_ext'),
                'num_int' => $get('num_int'),
                'colonia' => $get('colonia'),
                'ciudad'  => $get('ciudad'),
                'estado'  => $get('estado'),
                'pais'    => $get('pais'),
                'cp'      => $get('cp'),
            ],
        ];

        // Comprobar si el empleado ya existe para evitar duplicados
        $nombre1  = mb_strtoupper($validatedData['nombre'], 'UTF-8');
        $paterno1 = mb_strtoupper($validatedData['paterno'], 'UTF-8');

        $existeEmpleado = Empleado::whereRaw('UPPER(nombre) = ?', [$nombre1])
            ->whereRaw('UPPER(paterno) = ?', [$paterno1])
            ->where('id_cliente', $this->generalData['id_cliente'])
            ->where('id_portal', $this->generalData['id_portal'])
            ->exists();

        if ($existeEmpleado) {
            $this->duplicados[] = [
                'nombre'      => $validatedData['nombre'],
                'paterno'     => $validatedData['paterno'],
                'id_empleado' => $validatedData['id_empleado'],
            ];
            return null;
        }

        // Crear domicilio y empleado
        $domicilio = DomicilioEmpleado::create($validatedData['domicilio_empleado']);
        $empleado  = Empleado::create([
            'creacion'              => $this->generalData['creacion'],
            'edicion'               => $this->generalData['edicion'],
            'id_portal'             => $this->generalData['id_portal'],
            'id_usuario'            => $this->generalData['id_usuario'],
            'id_cliente'            => $this->generalData['id_cliente'],
            'id_empleado'           => $validatedData['id_empleado'],
            'correo'                => $validatedData['correo'],
            'curp'                  => $validatedData['curp'],
            'nombre'                => $validatedData['nombre'],
            'nss'                   => $validatedData['nss'],
            'rfc'                   => $validatedData['rfc'],
            'paterno'               => $validatedData['paterno'],
            'materno'               => $validatedData['materno'],
            'puesto'                => $validatedData['puesto'],
            'telefono'              => $validatedData['telefono'],
            'id_domicilio_empleado' => $domicilio->id,
            'status'                => 1,
            'eliminado'             => 0,
        ]);

        // Crear información médica y campos extra
        MedicalInfo::create([
            'id_empleado' => $empleado->id,
            'creacion'    => $this->generalData['creacion'],
            'edicion'     => $this->generalData['creacion'],
        ]);

        foreach ($camposExtras as $campo => $valor) {
            $campoNormalizado = strtolower(trim($campo));

            // Saltar si el nombre del campo es numérico o el valor es null
            if (is_numeric($campoNormalizado) || is_null($valor) || trim($valor) === '') {
                Log::warning("Campo extra inválido ignorado: campo=[$campo] valor=[" . var_export($valor, true) . "]");
                continue;
            }

            EmpleadoCampoExtra::create([
                'id_empleado' => $empleado->id,
                'nombre'      => $campo,
                'valor'       => $valor,
                'creacion'    => $this->generalData['creacion'],
                'edicion'     => $this->generalData['creacion'],
            ]);
        }

        // Contabilizar los registros insertados
        $this->insertados++;
        return $empleado;
    }
}
