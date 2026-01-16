<?php
namespace App\Imports;

use App\Models\Empleado;
use App\Models\LaboralesEmpleado;
use App\Services\SatCatalogosService;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Row;

class EmpleadosLaboralesImport implements OnEachRow, WithHeadingRow, WithCalculatedFormulas
{
    protected int $idCliente;
    protected SatCatalogosService $sat;

    public function __construct(int $idCliente, SatCatalogosService $sat)
    {
        $this->idCliente = $idCliente;
        $this->sat       = $sat;
    }

    /** Normaliza strings (minúsculas + trim) o null si viene vacío/“--” */
    private function norm(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $t = trim((string) $v);
        if ($t === '' || $t === '--') {
            return null;
        }

        // normaliza acentos/espacios extra
        $t = preg_replace('/\s+/u', ' ', $t);
        return mb_strtolower($t);
    }

    /** Invierte un mapa clave=>descripcion a descripcion(normalizada)=>clave */
    private function invert(array $mapClaveDesc): array
    {
        $out = [];
        foreach ($mapClaveDesc as $clave => $desc) {
            $out[$this->norm($desc)] = $clave;
        }
        return $out;
    }

    /** Devuelve [clave,desc] resolviendo entrada que puede ser clave o descripción */
    private function resolverClaveYDesc(?string $raw, array $claveDesc, array $descClave): array
    {
        $n = $this->norm($raw);
        if ($n === null) {
            return [null, null];
        }

        // 1) Si vino la clave SAT directa (e.g. "04")
        if (isset($claveDesc[$n])) {
            $clave = $n;
            $desc  = $claveDesc[$clave] ?? null;
            return [$clave, $desc];
        }

        // 2) Si vino descripción (e.g. "Mensual")
        if (isset($descClave[$n])) {
            $clave = $descClave[$n];
            $desc  = $claveDesc[$clave] ?? null;
            return [$clave, $desc];
        }

        // 3) No se reconoció
        return [null, null];
    }
    private array $columnMap = []; // letra → key del heading

    public function headingRow(): int
    {
        return 1;
    }

    public function onRow(Row $row)
    {
        static $validatedHeaders = false;

        $rowArray = $row->toArray();

// Crear mapa letra → cabecera solo una vez
        if (empty($this->columnMap)) {
            $headers = array_keys($rowArray);
            $letters = $this->generarLetras(count($headers));

            foreach ($headers as $i => $header) {
                $this->columnMap[$letters[$i]] = $header;
            }

            \Log::debug('Mapa de columnas generado:', $this->columnMap);
        }

        // ===== Validación de cabeceras (solo una vez) =====
        if (! $validatedHeaders) {
            $requiredHeaders = [
                'id',
                'tipo_contrato',
                'tipo_regimen',
                'tipo_jornada',
                'periodicidad_pago',
            ];

            $headersFromFile = array_map(fn($val) => mb_strtolower(trim($val)), array_keys($rowArray));
            \Log::debug('Cabeceras detectadas en import laborales:', $headersFromFile);

            $missing = array_filter($requiredHeaders, fn($h) => ! in_array($h, $headersFromFile, true));
            if (! empty($missing)) {
                throw new \Exception(
                    "El archivo seleccionado no es válido para actualizar Información Laboral. " .
                    "Faltan cabeceras: " . implode(', ', $missing)
                );
            }
            $validatedHeaders = true;
        }

        // ===== Datos base de la fila =====
        $row   = $row->toArray();
        $clean = fn($v) => (trim((string) $v) === '' || trim((string) $v) === '--') ? null : trim((string) $v);

        $empleadoId = $clean($row['id'] ?? null);
        if (! $empleadoId) {
            return;
        }

        $empleado = Empleado::find($empleadoId);
        if (! $empleado || (int) $empleado->id_cliente !== (int) $this->idCliente) {
            throw new \Exception(
                "No fue posible actualizar los datos. El archivo contiene empleados que no pertenecen a esta sucursal."
            );
        }

                                                           // ===== Catálogos SAT desde BD =====
        $catContratos      = $this->sat->contratos();      // ['01'=>'Tiempo indeterminado', ...]
        $catRegimenes      = $this->sat->regimenes();      // ['02'=>'Sueldos', '09'=>'Asimilados...', ...]
        $catJornadas       = $this->sat->jornadas();       // ['01'=>'Diurna', ...]
        $catPeriodicidades = $this->sat->periodicidades(); // ['01'=>'Diario','02'=>'Semanal',...]

        // Invertidos para resolver por descripción
        $invContratos      = $this->invert($catContratos);
        $invRegimenes      = $this->invert($catRegimenes);
        $invJornadas       = $this->invert($catJornadas);
        $invPeriodicidades = $this->invert($catPeriodicidades);

        // ===== Leer valores (pueden ser clave o descripción) =====
        $tipoContratoRaw     = $clean($row['tipo_contrato'] ?? null);
        $tipoRegimenRaw      = $clean($row['tipo_regimen'] ?? null);
        $tipoJornadaRaw      = $clean($row['tipo_jornada'] ?? null);
        $periodicidadPagoRaw = $clean($row['periodicidad_pago'] ?? null);

        // ===== Resolver a [clave, descripción] =====
        [$claveContrato, $descContrato]         = $this->resolverClaveYDesc($tipoContratoRaw, $catContratos, $invContratos);
        [$claveRegimen, $descRegimen]           = $this->resolverClaveYDesc($tipoRegimenRaw, $catRegimenes, $invRegimenes);
        [$claveJornada, $descJornada]           = $this->resolverClaveYDesc($tipoJornadaRaw, $catJornadas, $invJornadas);
        [$clavePeriodicidad, $descPeriodicidad] = $this->resolverClaveYDesc($periodicidadPagoRaw, $catPeriodicidades, $invPeriodicidades);

        // Logs si algo no mapeó
        if ($tipoContratoRaw && ! $claveContrato) {
            \Log::warning('Import laborales: tipo_contrato no reconocido', ['valor' => $tipoContratoRaw, 'id_empleado' => $empleadoId]);
        }

        if ($tipoRegimenRaw && ! $claveRegimen) {
            \Log::warning('Import laborales: tipo_regimen no reconocido', ['valor' => $tipoRegimenRaw, 'id_empleado' => $empleadoId]);
        }

        if ($tipoJornadaRaw && ! $claveJornada) {
            \Log::warning('Import laborales: tipo_jornada no reconocido', ['valor' => $tipoJornadaRaw, 'id_empleado' => $empleadoId]);
        }

        if ($periodicidadPagoRaw && ! $clavePeriodicidad) {
            \Log::warning('Import laborales: periodicidad_pago no reconocida', ['valor' => $periodicidadPagoRaw, 'id_empleado' => $empleadoId]);
        }

        // ===== Días de descanso (encaja con tu export) =====
        $diasDescanso = [];
        foreach ([
            'lunes'     => 'Lunes',
            'martes'    => 'Martes',
            'miercoles' => 'Miércoles',
            'jueves'    => 'Jueves',
            'viernes'   => 'Viernes',
            'sabado'    => 'Sábado',
            'domingo'   => 'Domingo',
        ] as $campo => $nombreDia) {
            $val = $this->norm($row['descanso_' . $campo] ?? 'no');
            if ($val === 'sí' || $val === 'si') {
                $diasDescanso[] = $nombreDia;
            }
        }

        // Sindicato
        $valorSindicato = $this->norm($row['pertenece_sindicato'] ?? '');
        $sindicato      = in_array($valorSindicato, ['sí', 'si'], true) ? 'SI' : 'NO';

        // ===== Guardar: legacy = descripción; *_sat = clave SAT =====
// ...dentro de updateOrCreate([...], [ ... ])
        LaboralesEmpleado::updateOrCreate(
            ['id_empleado' => (int) $empleadoId],
            [
                // Legacy (estos 3 pueden ir como descripción: caben en tus columnas)
                'tipo_contrato'          => $descContrato ?? $tipoContratoRaw,
                'tipo_regimen'           => $descRegimen ?? $tipoRegimenRaw,
                'tipo_jornada'           => $descJornada ?? $tipoJornadaRaw,

                                                                // ⚠️ ESTE es el que truenaba: la columna es VARCHAR(2)
                                                                // Guarda SIEMPRE la CLAVE de 2 chars
                'periodicidad_pago'      => $clavePeriodicidad, // <- clave ("04", "05", etc.)

                // Claves SAT (todas cortas)
                'tipo_contrato_sat'      => $claveContrato,
                'tipo_regimen_sat'       => $claveRegimen,
                'tipo_jornada_sat'       => $claveJornada,
                'periodicidad_pago_sat'  => $clavePeriodicidad,

                // Resto igual
                'otro_tipo_contrato'     => $clean($row['otro_tipo_contrato'] ?? null),
                'horas_dia'              => $clean($row['horas_dia'] ?? null),
                'grupo_nomina'           => $clean($row['grupo_nomina'] ?? null),
                'sindicato'              => $sindicato,
                'vacaciones_disponibles' => $clean($row['vacaciones_disponibles'] ?? null),
                'sueldo_diario'          => $this->numericOrNull($row['sueldo_diario'] ?? null, $row),
                'sueldo_asimilado'       => $this->numericOrNull($row['sueldo_diario_asimilado'] ?? null, $row),
                'pago_dia_festivo'       => $this->numericOrNull($row['pago_dia_festivo'] ?? null, $row),
                'pago_dia_festivo_a'     => $this->numericOrNull($row['pago_dia_festivo_asimilado'] ?? null, $row),
                'pago_hora_extra'        => $this->numericOrNull($row['pago_hora_extra'] ?? null, $row),
                'pago_hora_extra_a'      => $this->numericOrNull($row['pago_hora_extra_asimilado'] ?? null, $row),
                'dias_aguinaldo'         => $this->numericOrNull($row['dias_aguinaldo'] ?? null, $row),
                'prima_vacacional'       => $this->numericOrNull($row['prima_vacacional'] ?? null, $row),
                'prestamo_pendiente'     => $clean($row['prestamo_pendiente'] ?? null),
                'descuento_ausencia'     => $this->numericOrNull($row['descuento_ausencia'] ?? null, $row),
                'descuento_ausencia_a'   => $this->numericOrNull($row['descuento_ausencia_asimilado'] ?? null, $row),
                'dias_descanso'          => json_encode($diasDescanso),
            ]
        );

    }
    /**
     * Acepta:
     * - números
     * - strings numéricos
     * - fórmulas de Excel (ya evaluadas por Maatwebsite)
     */
    private function numericOrNull($value, array $row): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $v = trim((string) $value);

        if ($v === '' || $v === '--') {
            return null;
        }

        // Si es fórmula → evaluarla
        if (str_starts_with($v, '=')) {
            return $this->evaluarFormulaBasica($v, $row);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private function evaluarFormulaBasica(string $formula, array $row): ?float
    {
        $expr = ltrim($formula, '=');

        // Reemplazar TODAS las referencias tipo M20, L20, O5...
        $expr = preg_replace_callback('/([A-Z]+)(\d+)/i', function ($m) use ($row) {

            $col    = strtoupper($m[1]);
            $header = $this->columnMap[$col] ?? null;

            if (! $header) {
                return 0;
            }

            $value = $row[$header] ?? null;

            return is_numeric($value) ? $value : 0;

        }, $expr);

        // Seguridad
        if (! preg_match('/^[0-9\.\+\-\*\/\(\) ]+$/', $expr)) {
            \Log::warning("Fórmula insegura detectada: $expr");
            return null;
        }

        try {
            $result = eval("return ($expr);");
            return is_numeric($result) ? (float) $result : null;
        } catch (\Throwable $e) {
            \Log::warning("Error evaluando fórmula: $formula → $expr");
            return null;
        }
    }
    /**
     * Genera letras de columnas ilimitadas:
     * A, B, ..., Z, AA, AB, ..., ZZ, AAA...
     */
    private function generarLetras(int $cantidad): array
    {
        $letras = [];
        $n      = $cantidad;

        for ($i = 0; $i < $n; $i++) {
            $temp = $i;
            $col  = '';

            while ($temp >= 0) {
                $col  = chr(($temp % 26) + 65) . $col;
                $temp = intdiv($temp, 26) - 1;
            }

            $letras[] = $col;
        }

        return $letras;
    }

}
