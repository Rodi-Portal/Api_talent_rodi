<?php
namespace App\Imports;

use App\Models\DomicilioEmpleado;
use App\Models\Empleado;
use App\Models\EmpleadoCampoExtra;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class EmpleadosImport implements ToModel, WithHeadingRow
{
    protected $generalData;
    protected $duplicados = [];
    protected $insertados = 0;
    protected $rowNumber  = 1;

    protected $columnMap = [
        'nombre'           => ['nombre', 'first name', 'first_name', 'name'],
        'paterno'          => ['apellido paterno', 'paterno', 'apellido_paterno', 'last name', 'lastname', 'last_name'],
        'materno'          => ['apellido materno', 'materno', 'apellido_materno', 'middle name', 'middle_name'],
        'telefono'         => ['telefono', 'teléfono', 'phone'],
        'correo'           => ['correo', 'email', 'correo_electrónico', 'correo_electronico'],
        'puesto'           => ['puesto', 'position', 'cargo'],
        'curp'             => ['curp'],
        'nss'              => ['nss', 'numero seguro social', 'social security'],
        'rfc'              => ['rfc'],
        'id_empleado'      => ['id empleado', 'id_empleado', 'employee id'],

        // Dirección
        'calle'            => ['calle', 'street'],
        'num_ext'          => ['numero exterior', 'número exterior', 'num_ext', 'exterior number', 'numero_exterior', 'número_exterior'],
        'num_int'          => ['numero interior', 'número interior', 'num_int', 'interior number', 'numero_interior', 'número_interior'],
        'colonia'          => ['colonia', 'neighborhood'],
        'ciudad'           => ['ciudad', 'city'],
        'estado'           => ['estado', 'state'],
        'pais'             => ['pais', 'país', 'country'],
        'cp'               => [
            'codigo_postal', 'codigo postal', 'código postal', 'código_postal',
            'postal code', 'zip code',
            'cp', 'c.p.', // <- las cortas al final
        ],

        // Fecha de nacimiento
        'fecha_nacimiento' => [
            'fecha nacimiento', 'fecha de nacimiento', 'fecha_nacimiento', 'nacimiento', 'f. nacimiento',
            'fecha nac.', 'fechanac', 'dob', 'date of birth', 'birthdate', 'birthday',
        ],
            'fecha_ingreso' => [
            'fecha de ingreso', 'fecha ingreso', 'fecha_ingreso', 'ingreso', 'f. ingreso',
            'fecha ing.', 'fechaing', 'doi', 'date of join',
        ],
                'departamento'           => ['departamento', 'area'],

    ];

    public function __construct($generalData)
    {
        $this->generalData = $generalData;
    }

    public function getDuplicados()
    {return $this->duplicados;}
    public function getInsertados()
    {return $this->insertados;}

    private function normKey(string $s): string
    {
        $sAscii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
        $sAscii = strtolower(trim($sAscii));
        $sAscii = str_replace(['.', '’', '´', '`'], '', $sAscii);
        $sAscii = preg_replace('/\s+/', ' ', $sAscii);
        return Str::slug($sAscii, '_'); // "código postal" -> "codigo_postal", "c.p." -> "cp"
    }

    private function parseExcelDate($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($value);
                return Carbon::instance($dt)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        $v = trim((string) $value);
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'm/d/Y', 'm-d-Y', 'd.m.Y'] as $fmt) {
            try {return Carbon::createFromFormat($fmt, $v)->format('Y-m-d');} catch (\Throwable $e) {}
        }

        try {return Carbon::parse($v)->format('Y-m-d');} catch (\Throwable $e) {return null;}
    }

    private function normalizeCp($value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Normaliza espacios NBSP y a string
        $s = trim((string) $value);
        $s = str_replace("\xC2\xA0", ' ', $s);

        // Caso 1: valor puramente numérico, quizá con decimales "44130.0" o "00450.00"
        if (preg_match('/^\s*\d+([.,]\d+)?\s*$/', $s)) {
                                                           // toma solo la parte entera antes del primer punto/coma
            $intPart = preg_replace('/[^\d].*$/', '', $s); // corta desde el primer no-dígito en adelante
            $digits  = $intPart;
        } else {
            // Caso 2: texto mixto; si hay una secuencia de 5 dígitos, prefierela (MX)
            if (preg_match('/\d{5}/', $s, $m)) {
                $digits = $m[0];
            } else {
                // último recurso: todos los dígitos
                $digits = preg_replace('/\D/', '', $s);
            }
        }

        if ($digits === '') {
            return null;
        }

        // MX: exactamente 5 dígitos (si sobran, toma los primeros; si faltan, rellena con ceros a la izquierda)
        if (strlen($digits) > 8) {
            $digits = substr($digits, 0, 8);
        } else {
            $digits = str_pad($digits, 8, ' ', STR_PAD_LEFT);
        }

        return $digits;
    }

    // respaldo si quieres usar tolerancia extra (no imprescindible con normKey)
    private function fuzzyMatch($a, $b): bool
    {
        return levenshtein($a, $b) <= 2;
    }

    public function model(array $row)
    {
        if ($this->rowNumber === 1) {
            // Loguea exactamente como Maatwebsite entrega los encabezados
            /*Log::info('Cabeceras detectadas (raw y normalizadas)', [
                'raw'  => array_keys($row),
                'norm' => array_map(fn($h) => $this->normKey((string) $h), array_keys($row)),
            ]);*/
        }
        $this->rowNumber++;

        // getter flexible por alias
        $headerMap = [];
        foreach ($row as $header => $v) {
            $headerMap[$this->normKey((string) $header)] = $header;
        }

        $get = function ($key) use ($row, $headerMap) {
            $key     = strtolower(trim($key));
            $aliases = $this->columnMap[$key] ?? [];
            if (empty($aliases)) {
                return null;
            }

            // 1) Exact match primero (normalizado)
            foreach ($aliases as $alias) {
                $aliasNorm = $this->normKey($alias);
                if (isset($headerMap[$aliasNorm])) {
                    $originalHeader = $headerMap[$aliasNorm];
                    return $row[$originalHeader];
                }
            }

            // 2) Fuzzy match (desactivado para 'cp' para evitar confundir con 'curp')
            if ($key === 'cp') {
                return null;
            }

            $best     = null;
            $bestDist = PHP_INT_MAX;

            foreach ($aliases as $alias) {
                $aliasNorm = $this->normKey($alias);
                // evita fuzzy en alias muy cortos (cp, rfc, nss...)
                if (mb_strlen($aliasNorm) < 3) {
                    continue;
                }

                foreach ($headerMap as $hNorm => $originalHeader) {
                    if (mb_strlen($hNorm) < 3) {
                        continue;
                    }
                    // ignora encabezados muy cortos
                    $d = levenshtein($aliasNorm, $hNorm);
                    if ($d < $bestDist) {
                        $bestDist = $d;
                        $best     = $originalHeader;
                    }
                }
            }

            // Umbral conservador para fuzzy
            if ($best !== null && $bestDist <= 2) {
                return $row[$best];
            }

            return null;
        };

        // --- valores base ---
        $nombre  = trim((string) $get('nombre'));
        $paterno = trim((string) $get('paterno'));
        if ($nombre === '' || $paterno === '') {
            Log::warning('Fila inválida (nombre/paterno vacío)', ['row' => $row]);
            return null;
        }

        $correo = $get('correo');
        if ($correo && ! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Correo no válido', ['correo' => $correo]);
            return null;
        }

        // fecha y cp
        $fechaRaw     = $get('fecha_nacimiento');
        $fechaRaw2     = $get('fecha_ingreso');
        $cpRaw        = $get('cp');
        $departamentoRaw        = $get('departamento');

        $fechaParsed  = $this->parseExcelDate($fechaRaw);
        $fechaParsed2  = $this->parseExcelDate($fechaRaw2);

        $cpNormalized = $this->normalizeCp($cpRaw);

       /* Log::debug('Parse row fecha/cp', [
            'fecha_raw' => $fechaRaw,
            'fecha_db'  => $fechaParsed,
            'cp_raw'    => $cpRaw,
            'cp_db'     => $cpNormalized,
        ]);
        Log::debug('Debug directo', [
            'keys'         => array_keys($row),
            'fecha_direct' => $row['fecha_de_nacimiento'] ?? '(sin clave)',
            'cp_direct'    => $row['codigo_postal'] ?? '(sin clave)',
        ]);*/

        // campos extra (opcional)
        $aliasNormSet = [];
        foreach ($this->columnMap as $k => $aliases) {
            foreach ($aliases as $a) {$aliasNormSet[$this->normKey($a)] = true;}
        }
        $camposExtras = [];
        foreach ($row as $key => $valor) {
            $keyNorm = $this->normKey((string) $key);
            if (! isset($aliasNormSet[$keyNorm])) {
                $camposExtras[trim((string) $key)] = $valor;
            }
        }

        // Datos
        $validatedData = [
            'nombre'             => mb_strtoupper($nombre, 'UTF-8'),
            'paterno'            => mb_strtoupper($paterno, 'UTF-8'),
            'materno'            => mb_strtoupper((string) $get('materno'), 'UTF-8'),
            'telefono'           => $get('telefono'),
            'correo'             => $correo,
            'puesto'             => mb_strtoupper((string) $get('puesto'), 'UTF-8'),
            'curp'               => mb_strtoupper((string) $get('curp'), 'UTF-8'),
            'nss'                => $get('nss'),
            'rfc'                => mb_strtoupper((string) $get('rfc'), 'UTF-8'),
            'id_empleado'        => $get('id_empleado'),

            'domicilio_empleado' => [
                'calle'   => $get('calle'),
                'num_ext' => $get('num_ext'),
                'num_int' => $get('num_int'),
                'colonia' => $get('colonia'),
                'ciudad'  => $get('ciudad'),
                'estado'  => $get('estado'),
                'pais'    => $get('pais'),
                'cp'      => $cpNormalized, // 👈 guardamos CP normalizado
            ],
        ];

        // Duplicados
        $existe = Empleado::whereRaw('UPPER(nombre)=?', [mb_strtoupper($validatedData['nombre'], 'UTF-8')])
            ->whereRaw('UPPER(paterno)=?', [mb_strtoupper($validatedData['paterno'], 'UTF-8')])
            ->where('id_cliente', $this->generalData['id_cliente'])
            ->where('id_portal', $this->generalData['id_portal'])
            ->exists();

        if ($existe) {
            $this->duplicados[] = [
                'nombre'      => $validatedData['nombre'],
                'paterno'     => $validatedData['paterno'],
                'id_empleado' => $validatedData['id_empleado'],
            ];
            return null;
        }

        // Persistencia
        $domicilio = DomicilioEmpleado::create($validatedData['domicilio_empleado']);

        $empleado = Empleado::create([
            'creacion'              => $fechaParsed2,
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
            'departamento'          => $departamentoRaw,
            'telefono'              => $validatedData['telefono'],
            'id_domicilio_empleado' => $domicilio->id,
            'status'                => 1,
            'eliminado'             => 0,
            'fecha_nacimiento'      => $fechaParsed, // 👈 guardamos fecha
        ]);

      /*  Log::debug('Guardados', [
            'empleado_id'      => $empleado->id,
            'fecha_nacimiento' => $empleado->fecha_nacimiento?->format('Y-m-d'),
            'dom_cp'           => $domicilio->cp,
        ]);*/

        // Extras
        foreach ($camposExtras as $campo => $valor) {
            $campoNorm = strtolower(trim((string) $campo));
            if (is_numeric($campoNorm) || is_null($valor) || trim((string) $valor) === '') {
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

        $this->insertados++;
        return $empleado;
    }

}