<?php
namespace App\Imports;

use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\MedicalInfo;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class EmpleadosImport implements ToModel, WithHeadingRow
{
    protected $generalData;

    protected $duplicados = [];
    protected $insertados = 0;

    protected $columnMap = [
        'nombre'      => ['nombre', 'first name', 'first_name', 'name'],
        'paterno' => ['apellido paterno', 'paterno', 'apellido_paterno', 'last name', 'lastname', 'last_name'],
        'materno' => ['apellido materno', 'materno', 'apellido_materno', 'middle name', 'middle_name'],
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

    protected $rowNumber = 1;

   public function model(array $row)
{
    // Si es la primera fila, la cabecera, la mostramos en los logs
    if ($this->rowNumber == 1) {
        \Log::info("Cabeceras detectadas: " . json_encode($row));
    }

    // Función para obtener el valor de una columna basada en su alias
    $get = function ($key) use ($row) {
        // Normalizamos la clave
        $key = strtolower(trim($key)); 

        foreach ($this->columnMap[$key] ?? [] as $alias) {
            // Normalizamos los alias y las cabeceras
            $normalizedAlias = strtolower(trim($alias));
            foreach ($row as $header => $value) {
                // Normalizamos las cabeceras y los valores antes de comparar
                $normalizedHeader = strtolower(trim($header));
                if ($normalizedHeader === $normalizedAlias) {
                    return $value;
                }
            }
        }
        return null;
    };

    // Logueamos los datos recibidos para la fila
    \Log::info("Procesando fila: " . json_encode($row));

    // Validación de campos obligatorios: nombre y paterno
    $nombre = trim($get('nombre'));
    $paterno = trim($get('paterno'));

    // Log para comprobar los valores de nombre y paterno
    \Log::info("Nombre obtenido: '$nombre'");
    \Log::info("Apellido Paterno obtenido: '$paterno'");

    // Verificamos si `paterno` sigue siendo vacío después de obtenerlo
    if (empty($paterno)) {
        \Log::warning("Apellido paterno vacío en la fila: " . json_encode($row));
    }

    $nombre = trim($row['nombre'] ?? '');
    $apellidoPaterno = trim($row['apellido_paterno'] ?? '');
    
    if ($nombre === '' || $apellidoPaterno === '') {
        Log::warning("Fila $fila sin nombre o apellido paterno válido", $row);
        return null ;
    }


        // Logueamos que los campos obligatorios fueron encontrados
        \Log::info("Campos obligatorios validados: Nombre: $nombre, Paterno: $paterno");

        // Validación de correo electrónico
        $correo = $get('correo');
        if ($correo && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            \Log::warning('Correo no válido en la fila: ' . json_encode($row));
            return null; // Salimos si el correo no es válido
        }

        // Preparar los datos del empleado
        $validatedData = [
            'nombre'    => strtoupper($nombre),
            'paterno'   => strtoupper($paterno),
            'materno'   => strtoupper($get('materno')), // ← Agrega esta línea
            'telefono'  => $get('telefono'),
            'correo'    => $correo,
            'puesto'    => strtoupper($get('puesto')),
            'curp'      => strtoupper($get('curp')),
            'nss'       => $get('nss'),
            'rfc'       => strtoupper($get('rfc')),
            'id_empleado' => $get('id_empleado'),
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

        // Logueamos los datos validados antes de proceder con la inserción
        \Log::info("Datos validados para el empleado: " . json_encode($validatedData));

        // Verificar si ya existe un empleado con el mismo nombre y apellido
        $existeEmpleado = Empleado::whereRaw('UPPER(nombre) = ?', [strtoupper($validatedData['nombre'])])
            ->whereRaw('UPPER(paterno) = ?', [strtoupper($validatedData['paterno'])])
            ->where('id_cliente', $this->generalData['id_cliente'])
            ->where('id_portal', $this->generalData['id_portal'])
            ->exists();

        if ($existeEmpleado) {
            \Log::info("Empleado duplicado encontrado: " . json_encode($validatedData));
            $this->duplicados[] = [
                'nombre'      => $validatedData['nombre'],
                'paterno'     => $validatedData['paterno'],
                'id_empleado' => $validatedData['id_empleado'],
            ];
            return null; // Salimos si el empleado ya existe
        }

        // Si no es duplicado, creamos el domicilio y el empleado
        $domicilio = DomicilioEmpleado::create($validatedData['domicilio_empleado']);
        $empleado = Empleado::create([
            'creacion'    => $this->generalData['creacion'],
            'edicion'     => $this->generalData['edicion'],
            'id_portal'   => $this->generalData['id_portal'],
            'id_usuario'  => $this->generalData['id_usuario'],
            'id_cliente'  => $this->generalData['id_cliente'],
            'id_empleado' => $validatedData['id_empleado'],
            'correo'      => $validatedData['correo'],
            'curp'        => $validatedData['curp'],
            'nombre'      => $validatedData['nombre'],
            'nss'         => $validatedData['nss'],
            'rfc'         => $validatedData['rfc'],
            'paterno'     => $validatedData['paterno'],
            'materno'     => $validatedData['materno'],
            'puesto'      => $validatedData['puesto'],
            'telefono'    => $validatedData['telefono'],
            'id_domicilio_empleado' => $domicilio->id,
            'status'      => 1,
            'eliminado'   => 0,
        ]);

        \Log::info("Empleado creado con éxito: " . json_encode($empleado));

        // Crear la información médica para el nuevo empleado
        MedicalInfo::create([
            'id_empleado' => $empleado->id,
            'creacion'    => $this->generalData['creacion'],
            'edicion'     => $this->generalData['creacion'],
        ]);

        // Aumentar el contador de insertados
        $this->insertados++;

        return $empleado;
    }
}
