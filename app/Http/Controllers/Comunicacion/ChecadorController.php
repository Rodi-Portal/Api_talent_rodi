<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion\ChecadorMapping;
use Illuminate\Http\Request; // Modelo Eloquent para checador_mappings
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;


// Para leer XLSX/XLS

/**
 * Controlador de Checador:
 * - Administrar configuraciones de mapeo (listado y guardado)
 * - Importar CSV/XLSX normalizando columnas (id empleado, fecha/hora, tipo, dispositivo)
 *
 * Convenciones:
 *  - id_portal: proviene del front (en tu UI está fijo/solo lectura)
 *  - id_cliente: requerido al importar (no usamos branch_key del archivo)
 *  - config_json: puede venir como {"map": {...}} o directamente {...}. Se toleran ambas.
 */
class ChecadorController extends Controller
{
    /**
     * GET /checador/mappings?id_portal=1[&id_cliente=70]
     * Lista las configuraciones de mapeo para un portal (y opcionalmente para una sucursal).
     */
    public function indexMappings(Request $req)
    {
        $portalId   = (int) ($req->query('id_portal') ?? $req->query('portal_id'));
        $sucursalId = ($req->query('id_cliente') ?? $req->query('sucursal_id'));

        $items = ChecadorMapping::portal($portalId)
            ->sucursal($sucursalId ? (int) $sucursalId : null)
            ->orderByDesc('activo')
            ->orderByDesc('id')
            ->get();

        return response()->json($items);
    }

    /**
     * POST /checador/mappings
     * Guarda una configuración de mapeo para un portal (y opcionalmente sucursal).
     * Espera:
     * - id_portal (int)
     * - id_cliente (int|null)
     * - nombre (string)
     * - headers_fingerprint (string|null)  // huella simple de headers para reconocimiento
     * - config_json (array|json)           // puede venir como {"map":{...}} o {...}
     */
    public function storeMapping(Request $req)
    {
        $data = $req->validate([
            'id_portal'           => 'required|integer',
            'id_cliente'          => 'nullable|integer',
            'nombre'              => 'required|string|max:120',
            'headers_fingerprint' => 'nullable|string|max:255',
            'config_json'         => 'required',
        ]);

        if (is_string($data['config_json'])) {
            $data['config_json'] = json_decode($data['config_json'], true);
        }

        $row = ChecadorMapping::create([
            'portal_id'           => (int) $data['id_portal'],    // 👈 ojo
            'sucursal_id'         => $data['id_cliente'] ?? null, // 👈 ojo
            'nombre'              => $data['nombre'],
            'headers_fingerprint' => $data['headers_fingerprint'] ?? null,
            'config_json'         => $data['config_json'],
            'activo'              => 1,
        ]);

        return response()->json(['ok' => true, 'id' => $row->id]);
    }

    /**
     * POST /checador/import
     * Importa un archivo de checadas, normalizando según la configuración del front.
     * Requiere:
     * - id_portal   (int)
     * - id_cliente (int)     // obligatorio: ya no usamos branch_key del archivo
     * - file        (csv|txt|xlsx|xls)
     * - config_json (json)    // puede venir como {"map":{...}} o {...}
     */
    public function import(Request $req)
    {
        // ===== 0) Debug & conexión =====
        $debug = filter_var($req->input('debug', $req->query('debug', false)), FILTER_VALIDATE_BOOLEAN);
        $CONN  = 'portal_main'; // usa la misma conexión donde viven empleados/checadas

        // ===== 1) Validación básica =====
        $req->validate([
            'id_portal'   => 'required|integer',
            'id_cliente'  => 'required|integer',
            'file'        => 'required|file|mimes:csv,txt,xlsx,xls',
            'config_json' => 'required',
        ]);

        $portalId   = (int) $req->input('id_portal');
        $sucursalId = (int) $req->input('id_cliente');
        $file       = $req->file('file');

        Log::info('[ChecadorImport] inicio', [
            'portal'  => $portalId,
            'cliente' => $sucursalId,
            'file'    => $file ? $file->getClientOriginalName() : null,
            'ext'     => $file ? strtolower($file->getClientOriginalExtension()) : null,
            'size'    => $file ? $file->getSize() : null,
            'debug'   => $debug,
        ]);

        // ===== 2) Config =====
        $cfg = $this->parseConfigFromRequest($req);
        if (! is_array($cfg) || empty($cfg)) {
            Log::warning('[ChecadorImport] config_json vacío/invalid');
            return response()->json(['ok' => false, 'msg' => 'config_json vacío o inválido'], 422);
        }

        // Defaults datetime
        $cfg['datetime']                  = $cfg['datetime'] ?? [];
        $cfg['datetime']['mode']          = $cfg['datetime']['mode'] ?? 'single';
        $cfg['datetime']['excelBase']     = $cfg['datetime']['excelBase'] ?? '1900';
        $cfg['datetime']['offsetMinutes'] = (int) ($cfg['datetime']['offsetMinutes'] ?? 0);
        $cfg['datetime']['format']        = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
        $cfg['datetime']['dateFmt']       = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
        $cfg['datetime']['timeFmt']       = $cfg['datetime']['timeFmt'] ?? 'H:i:s';

        if ($debug) {
            $cfgLog = $cfg;
            if (isset($cfgLog['type']['dict']) && is_array($cfgLog['type']['dict'])) {
                // evitar logs enormes: solo muestra primeras 12 claves del dict
                $cfgLog['type']['dict'] = array_slice($cfgLog['type']['dict'], 0, 12, true);
            }
            Log::debug('[ChecadorImport] config normalizada', $cfgLog);
        }

        // ===== 3) Leer archivo =====
        $rows    = $this->readFileToRows($file);
        $headers = array_keys($rows[0] ?? []);
        Log::info('[ChecadorImport] archivo leído', [
            'rows'    => count($rows),
            'headers' => $headers,
        ]);

        // ===== 4) Validación rápida con muestra =====
        $sample                   = array_slice($rows, 0, 20);
        [$okPreview, $errPreview] = $this->validatePreview($sample, $cfg);
        if (! $okPreview) {
            Log::warning('[ChecadorImport] validatePreview FALLÓ', [
                'reason'      => $errPreview,
                'sample_rows' => count($sample),
            ]);
            return response()->json(['ok' => false, 'msg' => $errPreview], 422);
        }
        if ($debug) {
            // Loguear cómo se ve la normalización de las primeras filas
            $normPreview = [];
            foreach ($sample as $i => $r) {
                $n             = $this->normalizeRow($r, $cfg);
                $normPreview[] = [
                    'i'            => $i,
                    'employee_key' => $n['employee_key'],
                    'datetime'     => $n['datetime'],
                    'type'         => $n['type'],
                    'device'       => $n['device'],
                ];
            }
            Log::debug('[ChecadorImport] preview normalizada', ['rows' => $normPreview]);
        }

        // ===== 5) Insertar normalizado =====
        $insertados = 0;
        $duplicados = 0;
        $errores    = 0;

        // Para diagnosticar: guarda razones de descarte (limitado)
        $WHY_MAX = 80;
        $why     = [];

        foreach ($rows as $idx => $row) {
            $norm = $this->normalizeRow($row, $cfg); // employee_key, datetime, type, type_raw, device

            if (! $norm['employee_key']) {
                $errores++;
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = ['i' => $idx, 'reason' => 'sin_employee_key', 'row' => $row];
                }
                continue;
            }
            if (! $norm['datetime']) {
                $errores++;
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = ['i' => $idx, 'reason' => 'sin_datetime', 'row' => $row];
                }
                continue;
            }

            // Lookup del empleado en la misma conexión
            $emp = DB::connection($CONN)->table('empleados')
                ->where('id_portal', $portalId)
                ->where('id_cliente', $sucursalId)
                ->where('id_empleado', $norm['employee_key'])
                ->first();

            if (! $emp) {
                $errores++;
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = [
                        'i'            => $idx,
                        'reason'       => 'empleado_no_encontrado',
                        'employee_key' => $norm['employee_key'],
                        'row'          => $row,
                    ];
                }
                continue;
            }
            $idEmpleado = (int) $emp->id;

            $dt    = $norm['datetime']; // "YYYY-MM-DD HH:mm:ss"
            $fecha = substr($dt, 0, 10);

            // tipo/clase
            [$tipo, $clase] = $this->mapTipoYClase($norm['type'], $norm['type_raw'] ?? null);

            // hash idempotente
            $hash = sha1($idEmpleado . '|' . $dt . '|' . ($tipo ?: 'NULL') . '|' . ($clase ?: 'NULL'));

            try {
                DB::connection($CONN)->table('checadas')->insert([
                    'id_portal'   => $portalId,
                    'id_cliente'  => $sucursalId,
                    'id_empleado' => $idEmpleado,
                    'fecha'       => $fecha,
                    'check_time'  => $dt,
                    'tipo'        => $tipo,  // 'in'|'out'|null
                    'clase'       => $clase, // 'work'|'break'|'meal'|...|null
                    'dispositivo' => $norm['device'] ?: null,
                    'origen'      => $this->originFromFile($file),
                    'observacion' => null,
                    'hash'        => $hash,
                    'creado_en'   => now(),
                ]);
                $insertados++;
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage() ?? '');
                if (Str::contains($msg, ['duplicate', 'unique'])) {
                    $duplicados++;
                    if ($debug && count($why) < $WHY_MAX) {
                        $why[] = [
                            'i'      => $idx,
                            'reason' => 'duplicate_key',
                            'hash'   => $hash,
                            'dt'     => $dt,
                            'emp_id' => $idEmpleado,
                        ];
                    }
                } else {
                    $errores++;
                    Log::error('[ChecadorImport] error insertando fila', [
                        'i'     => $idx,
                        'error' => $e->getMessage(),
                        'row'   => $row,
                        'norm'  => ['emp_id' => $idEmpleado, 'dt' => $dt, 'tipo' => $tipo, 'clase' => $clase],
                    ]);
                    if ($debug && count($why) < $WHY_MAX) {
                        $why[] = ['i' => $idx, 'reason' => 'insert_error', 'error' => $e->getMessage()];
                    }
                }
            }
        }

        // ===== 6) Fin & logs =====
        Log::info('[ChecadorImport] fin', [
            'portal'     => $portalId,
            'cliente'    => $sucursalId,
            'insertados' => $insertados,
            'duplicados' => $duplicados,
            'errores'    => $errores,
            'why_count'  => $debug ? count($why) : 0,
        ]);
        if ($debug && $why) {
            // Dump controlado de razones (capado a WHY_MAX)
            Log::debug('[ChecadorImport] detalles de descarte', ['why' => $why]);
        }

        return response()->json([
            'ok'    => true,
            'msg'   => 'Importación completa',
            'stats' => compact('insertados', 'duplicados', 'errores'),
        ]);
    }

    /* ========================= Helpers internos ========================= */

    /**
     * Mapea un "tipo" crudo usando un diccionario tolerante (ignora mayúsculas, acentos, espacios).
     * Ej.: "IN", "in", "Entrada", "entrada" → "entrada"
     */
    private function mapTypeTolerant(string $raw, array $dict): ?string
    {
        if ($raw === '') {
            return null;
        }

        // 1) Coincidencia exacta tal cual
        if (array_key_exists($raw, $dict)) {
            return $dict[$raw];
        }

        // 2) Normalizar y comparar claves del dict
        $norm = $this->normalizeText($raw);
        foreach ($dict as $k => $v) {
            if ($this->normalizeText((string) $k) === $norm) {
                return $v;
            }

        }
        return null;
    }

    /**
     * Normaliza texto: trim, minúsculas, sin acentos, espacios colapsados.
     */
    private function normalizeText(string $s): string
    {
        $s = trim(mb_strtolower($s));
        // Quitar acentos/diacríticos
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        // Colapsar múltiples espacios
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    /**
     * Suma/resta un offset en minutos a una fecha/hora en string "Y-m-d H:i:s".
     * Útil cuando el archivo viene en otra zona horaria.
     */
    private function applyOffset(string $dtStr, int $minutes): string
    {
        try {
            $dt       = new \DateTime($dtStr, new \DateTimeZone('UTC'));
            $interval = new \DateInterval('PT' . abs($minutes) . 'M');
            if ($minutes >= 0) {$dt->add($interval);} else { $dt->sub($interval);}
            return $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $dtStr;
        }
    }

    /**
     * Convierte serial de Excel a "Y-m-d H:i:s".
     * Soporta base "1900" (Windows) y "1904" (Mac).
     */

    /**
     * Devuelve "excel" o "csv" según la extensión del archivo subido.
     */
    private function originFromFile($file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        return in_array($ext, ['xlsx', 'xls']) ? 'excel' : 'csv';
    }

    /**
     * Lee la primera hoja de un XLSX/XLS y la convierte a arreglo de filas asociativas.
     * Primera fila = encabezados.
     */
    private function sheetToAssoc(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
    {
        $rows = $ws->toArray(null, true, true, true); // índices de columna (A,B,C...)
        if (count($rows) < 2) {
            return [];
        }

        // Fila 1 → headers
        $headers = $this->cleanHeaders(array_values($rows[1]));
        $out     = [];

        for ($r = 2; $r <= count($rows); $r++) {
            $row = $rows[$r];
            if (! $row) {
                continue;
            }

            $assoc = [];
            $i     = 0;
            foreach ($row as $col => $val) {
                $hdr         = $headers[$i] ?? ('Col' . $i);
                $assoc[$hdr] = $val;
                $i++;
            }
            $out[] = $assoc;
        }
        return $out;
    }

    /**
     * Valida una muestra de filas ya normalizadas: al menos 70% deben tener id_empleado + datetime.
     */
    private function validatePreview(array $sample, array $cfg): array
    {
        if (! $sample) {
            return [false, 'Archivo vacío'];
        }

        $ok    = 0;
        $total = 0;
        $fails = [];
        foreach ($sample as $row) {
            $n = $this->normalizeRow($row, $cfg);
            $total++;
            if ($n['employee_key'] && $n['datetime']) {
                $ok++;
            } elseif (count($fails) < 5) {
                $fails[] = [
                    'employee_key' => $n['employee_key'],
                    'datetime'     => $n['datetime'],
                    'seen_headers' => array_slice(array_keys($row), 0, 10),
                    'map'          => [
                        'employee_col' => $cfg['employee_key']['col'] ?? null,
                        'mode'         => $cfg['datetime']['mode'] ?? null,
                        'col'          => $cfg['datetime']['col'] ?? null,
                        'dateCol'      => $cfg['datetime']['dateCol'] ?? null,
                        'timeCol'      => $cfg['datetime']['timeCol'] ?? null,
                        'serialCol'    => $cfg['datetime']['serialCol'] ?? null,
                        'format'       => $cfg['datetime']['format'] ?? null,
                    ],
                ];
            }
        }

        $rate = $ok / max(1, $total);
        if ($rate < 0.7) {
            $msg = 'Formato de fecha/hora o columnas no reconocido. '
            . "Filas OK: {$ok}/{$total}. Ejemplos: " . json_encode($fails);
            return [false, $msg];
        }
        return [true, null];
    }

/**
 * Lee config_json desde el Request tolerando:
 *  - string JSON (input normal)
 *  - array (ya decodificado)
 *  - archivo (cuando el front lo envía como Blob en FormData)
 * Retorna SIEMPRE el objeto de mapeo (si venía como {"map": {...}} devuelve {...}).
 */
    private function parseConfigFromRequest(Request $req): array
    {
        // 1) Intentar como input "normal"
        $rawInput = $req->input('config_json');

        // 2) Si no viene por input, revisar si vino como archivo (Blob)
        if (! $rawInput && $req->hasFile('config_json')) {
            try {
                $rawInput = file_get_contents($req->file('config_json')->getRealPath());
            } catch (\Throwable $e) {
                $rawInput = null;
            }
        }

        // 3) Si ya es array, úsalo; si es string intenta decodificarlo
        if (is_array($rawInput)) {
            $raw = $rawInput;
        } elseif (is_string($rawInput)) {
            $raw = json_decode($rawInput, true);
        } else {
            $raw = null;
        }

        // 4) Si viene como {"map": {...}} regresa {...}
        if (is_array($raw) && array_key_exists('map', $raw) && is_array($raw['map'])) {
            return $raw['map'];
        }

        // 5) Si ya es el mapa plano, regresa tal cual
        return is_array($raw) ? $raw : [];
    }

    /**
     * Normaliza UNA fila del archivo según el mapeo.
     * Salida: employee_key, datetime (Y-m-d H:i:s), type, device.
     */
    private function normalizeRow(array $row, array $cfg): array
    {
        // --- ID empleado ---
        $ekCol       = $cfg['employee_key']['col'] ?? null;
        $employeeKey = $ekCol ? trim((string) ($row[$ekCol] ?? '')) : '';

        // --- Fecha/hora ---
        $mode      = $cfg['datetime']['mode'] ?? 'single';
        $offset    = (int) ($cfg['datetime']['offsetMinutes'] ?? 0);
        $excelBase = $cfg['datetime']['excelBase'] ?? '1900';
        $dtStr     = '';

        if ($mode === 'single') {
            $col   = $cfg['datetime']['col'] ?? null;
            $fmt   = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
            $dtStr = $this->parseDateTime((string) ($row[$col] ?? ''), $fmt);
        } elseif ($mode === 'split') {
            $dcol = $cfg['datetime']['dateCol'] ?? null;
            $tcol = $cfg['datetime']['timeCol'] ?? null;
            $dfmt = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
            $tfmt = $cfg['datetime']['timeFmt'] ?? 'H:i:s';
            $d    = $this->parseDate((string) ($row[$dcol] ?? ''), $dfmt);
            $t    = $this->parseTime((string) ($row[$tcol] ?? ''), $tfmt);
            if ($d && $t) {
                $dtStr = $d . ' ' . $t;
            }

        } elseif ($mode === 'excel_serial') {
            $scol  = $cfg['datetime']['serialCol'] ?? null;
            $dtStr = $this->fromExcelSerialToYmdHms($row[$scol] ?? null, $excelBase);
        }

        if ($dtStr && $offset !== 0) {
            $dtStr = $this->applyOffset($dtStr, $offset);
        }

        // --- Tipo de marca ---
        $type    = null;
        $typeRaw = null;
        if (! empty($cfg['type']['col'])) {
            $typeRaw = trim((string) ($row[$cfg['type']['col']] ?? ''));
            $dict    = $cfg['type']['dict'] ?? [];
            $type    = $this->mapTypeTolerant($typeRaw, $dict);
        }

        // --- Dispositivo (opcional) ---
        $device = ! empty($cfg['device']['col']) ? trim((string) ($row[$cfg['device']['col']] ?? '')) : '';

        return [
            'employee_key' => $employeeKey,
            'datetime'     => $dtStr,        // "YYYY-MM-DD HH:mm:ss"
            'type'         => $type ?: null, // 'entrada'|'salida'|'descanso_entrada'|'descanso_salida'|null
            'type_raw'     => $typeRaw ?: null,
            'device'       => $device ?: null,
            'raw'          => $row,
        ];
    }

    /**
     * Intenta parsear una fecha/hora según formato; como fallback, strtotime().
     * Ej. formatos: Y-m-d H:i:s, d/m/Y H:i, etc.
     */
    private function parseDateTime(string $val, string $fmt): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }

        // Primero intenta con el formato declarado
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val);
        if ($ts instanceof \DateTime) {
            return $ts->format('Y-m-d H:i:s');
        }

        // Fallbacks comunes
        $fallbacks = [
            'Y-m-d H:i',
            'd/m/Y H:i:s',
            'd/m/Y H:i',
            'd-m-Y H:i:s',
            'd-m-Y H:i',
            'Y/m/d H:i:s',
            'Y/m/d H:i',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i',
        ];
        foreach ($fallbacks as $f) {
            $ts = \DateTime::createFromFormat($f, $val);
            if ($ts instanceof \DateTime) {
                return $ts->format('Y-m-d H:i:s');
            }

        }

        // Último recurso: strtotime
        $t = strtotime($val);
        if ($t !== false) {
            return date('Y-m-d H:i:s', $t);
        }

        return '';
    }

    /**
     * Parsea solo fecha.
     */
    private function parseDate(string $val, string $fmt): string
    {
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val) ?: (strtotime($val) ? new \DateTime($val) : null);
        return $ts ? $ts->format('Y-m-d') : '';
    }

    /**
     * Parsea solo hora.
     */
    private function parseTime(string $val, string $fmt): string
    {
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val) ?: (strtotime($val) ? new \DateTime($val) : null);
        return $ts ? $ts->format('H:i:s') : '';
    }

    /**
     * En este caso, el formato ya viene "en estilo PHP" desde el front (Y-m-d H:i:s, d/m/Y, H:i, etc.).
     * Si en el futuro recibes tokens tipo moment.js, aquí los mapearías.
     */
    private function phpFormat(string $fmt): string
    {
        return $fmt;
    }

    /**
     * Lee un archivo subido (CSV/TXT/XLS/XLSX) y devuelve arreglo de filas asociativas.
     * - Para Excel: usa PhpSpreadsheet.
     * - Para CSV/TXT: detecta delimitador entre ',', ';', '\t' o '|'.
     */
    private function readFileToRows($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());

        // Excel (XLSX/XLS)
        if (in_array($ext, ['xlsx', 'xls'])) {
            $spreadsheet = IOFactory::load($file->getPathname());
            $ws          = $spreadsheet->getSheet(0);
            return $this->sheetToAssoc($ws);
        }

        // CSV / TXT con detección simple de delimitador
        $rows = [];
        if (($h = fopen($file->getPathname(), 'r')) !== false) {
            $firstLine = fgets($h);
            rewind($h);

            $delim = $this->guessDelimiter((string) $firstLine); // ',', ';', '\t' o '|'

            $headers = null;
            while (($data = fgetcsv($h, 0, $delim)) !== false) {
                if ($headers === null) {
                    $headers = $this->cleanHeaders($data); // 👈 aquí
                    continue;
                }
                $rows[] = @array_combine($headers, $data) ?: [];
            }
            fclose($h);
        }
        return $rows;
    }

    /**
     * Heurística muy simple para adivinar el delimitador del CSV.
     */
    private function guessDelimiter(string $firstLine): string
    {
        $candidates = [",", "\t", ";", "|"];
        $best       = ",";
        $max        = -1;
        foreach ($candidates as $d) {
            $count = substr_count($firstLine, $d);
            if ($count > $max) {$max = $count;
                $best                           = $d;}
        }
        return $best;
    }
    /**
     * Decide 'tipo' ('in'|'out') y 'clase' ('work'|'break'|'meal'|'personal'|'other')
     * a partir del type normalizado y del texto crudo (para distinguir 'meal' vs 'break').
     */
    private function mapTipoYClase(?string $normType, ?string $rawType): array
    {
        $norm = $normType ?: '';
        $raw  = $this->normalizeText((string) $rawType);

        // ¿suena a comida?
        $looksMeal = false;
        foreach (['lunch', 'comida', 'meal', 'almuerzo', 'lunch out', 'meal out', 'comida salida'] as $kw) {
            if ($kw !== '' && str_contains($raw, $kw)) {$looksMeal = true;
                break;}
        }

        switch ($norm) {
            case 'entrada':return ['in', 'work'];
            case 'salida':return ['out', 'work'];
            case 'descanso_entrada':return ['out', $looksMeal ? 'meal' : 'break'];
            case 'descanso_salida':return ['in', $looksMeal ? 'meal' : 'break'];
            default: return [null, null]; // si no vino el tipo, lo dejamos null
        }
    }
    private function fromExcelSerialToYmdHms($num, string $base = '1900'): string
    {
        $n = (float) $num;
        if ($n <= 0) {
            return '';
        }

        $epoch = ($base === '1904') ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00';
        $dt    = \DateTime::createFromFormat('Y-m-d H:i:s', $epoch, new \DateTimeZone('UTC'));
        // segundos con fracción
        $seconds = (int) floor($n * 86400);
        $dt->modify('+' . $seconds . ' seconds');
        return $dt->format('Y-m-d H:i:s');
    }
    private function cleanHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $h = (string) $h;
            // Quitar BOM si viniera pegado y espacios
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return trim($h);
        }, $headers);
    }

}
