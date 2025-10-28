<?php
namespace App\Http\Controllers\Comunicacion;

use App\Http\Controllers\Controller;
use App\Models\Comunicacion\ChecadorMapping;
use App\Services\Asistencia\AsistenciaServicio;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ChecadorController extends Controller
{
    /** ========================= MAPPINGS ========================= */
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
            'portal_id'           => (int) $data['id_portal'],
            'sucursal_id'         => $data['id_cliente'] ?? null,
            'nombre'              => $data['nombre'],
            'headers_fingerprint' => $data['headers_fingerprint'] ?? null,
            'config_json'         => $data['config_json'],
            'activo'              => 1,
        ]);

        return response()->json(['ok' => true, 'id' => $row->id]);
    }

    /** ========================= IMPORT ========================= */
    /*
        public function import(Request $req)
        {
            $debug = filter_var($req->input('debug', $req->query('debug', false)), FILTER_VALIDATE_BOOLEAN);
            $CONN  = 'portal_main';

            // ===== Validación =====
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

            // ===== Config =====
            $cfg = $this->parseConfigFromRequest($req);
            if (! is_array($cfg) || empty($cfg)) {
                return response()->json(['ok' => false, 'msg' => 'config_json vacío o inválido'], 422);
            }
            $cfg['datetime']                  = $cfg['datetime'] ?? [];
            $cfg['datetime']['mode']          = $cfg['datetime']['mode'] ?? 'single';
            $cfg['datetime']['excelBase']     = $cfg['datetime']['excelBase'] ?? '1900';
            $cfg['datetime']['offsetMinutes'] = (int) ($cfg['datetime']['offsetMinutes'] ?? 0);
            $cfg['datetime']['format']        = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
            $cfg['datetime']['dateFmt']       = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
            $cfg['datetime']['timeFmt']       = $cfg['datetime']['timeFmt'] ?? 'H:i:s';

            Log::info('[ChecadorImport] cfg columnas', [
                'emp_col'   => $cfg['employee_key']['col'] ?? '',
                'name_col'  => $cfg['employee_name']['col'] ?? '',
                'dt_mode'   => $cfg['datetime']['mode'] ?? '',
                'dt_col'    => $cfg['datetime']['col'] ?? '',
                'dateCol'   => $cfg['datetime']['dateCol'] ?? '',
                'timeCol'   => $cfg['datetime']['timeCol'] ?? '',
                'serialCol' => $cfg['datetime']['serialCol'] ?? '',
                'excelBase' => $cfg['datetime']['excelBase'] ?? '1900',
                'type_col'  => $cfg['type']['col'] ?? '',
            ]);

            // ===== Archivo → filas =====
            $rows    = $this->readFileToRows($file);
            $headers = array_keys($rows[0] ?? []);
            Log::info('[ChecadorImport] archivo leído', ['rows' => count($rows), 'headers' => $headers]);
            if (! count($rows)) {
                return response()->json(['ok' => false, 'msg' => 'Archivo vacío o sin encabezados reconocibles'], 422);
            }

            // ===== Validación previa (muestra) — solo si existe el helper =====
            if (is_callable([$this, 'validatePreview'])) {
                $sample                   = array_slice($rows, 0, 50);
                [$okPreview, $errPreview] = $this->validatePreview($sample, $cfg);
                if (! $okPreview) {
                    Log::warning('[ChecadorImport] validatePreview FALLÓ', ['reason' => $errPreview]);
                    return response()->json(['ok' => false, 'msg' => $errPreview], 422);
                }
            } else {
                Log::warning('[ChecadorImport] validatePreview no existe en el controlador; se omite esta verificación.');
            }

            // ===== Índices de empleados (por id_empleado y por nombre normalizado) =====
            // Nota: este helper debe ser el que te pasé (firma: ($conn, $portalId, $sucursalId) => [byKey, byName])
            [$empByKey, $empByName] = $this->buildEmployeeIndexes($CONN, $portalId, $sucursalId);

            // ===== Bucle principal (UPSERT) =====
            $insertados   = 0;
            $actualizados = 0;
            $errores      = 0;
            $WHY_MAX      = 120;
            $why          = [];

            foreach ($rows as $idx => $row) {
                $norm = $this->normalizeRow($row, $cfg);

                // --- Resolver empleado: por key o por nombre ---
                $ek    = trim((string) ($norm['employee_key'] ?? ''));
                $empId = null;

                if ($ek !== '' && isset($empByKey[$ek])) {
                    $empId = (int) $empByKey[$ek];
                }
                if (! $empId && ! empty($cfg['employee_name']['col'])) {
                    $nombreRaw = (string) ($row[$cfg['employee_name']['col']] ?? '');
                    if ($nombreRaw !== '') {
                        $nomKey = $this->normalizeName($nombreRaw); // <- quita acentos y puntuación (incl. ".")
                        $empId  = isset($empByName[$nomKey]) ? (int) $empByName[$nomKey] : null;
                    }
                }
                if (! $empId) {
                    $errores++;
                    if ($debug && count($why) < $WHY_MAX) {
                        $why[] = ['i' => $idx, 'reason' => 'empleado_no_encontrado', 'ek' => $ek, 'name' => ($row[$cfg['employee_name']['col'] ?? ''] ?? null)];
                    }
                    continue;
                }

                // --- datetime ---
                $dt = trim((string) ($norm['datetime'] ?? ''));
                if ($dt === '') {
                    $errores++;
                    if ($debug && count($why) < $WHY_MAX) {
                        $why[] = ['i' => $idx, 'reason' => 'sin_datetime', 'raw_dt' => $this->whyRawDatetime($row, $cfg)];
                    }
                    continue;
                }
                $fecha = substr($dt, 0, 10);

                // --- tipo/clase tolerante ---
                $typeCol        = $cfg['type']['col'] ?? '';
                $typeRaw        = $typeCol ? (string) ($row[$typeCol] ?? '') : ($norm['type_raw'] ?? '');
                $typeNor        = $this->mapTypeTolerant((string) $typeRaw, $cfg['type']['dict'] ?? []);
                [$tipo, $clase] = $this->mapTipoYClase($typeNor, $typeRaw);

                // Fallback mínimo porque 'tipo' es NOT NULL en tu tabla
                if ($tipo === null) {
                    $needle = $this->normalizeText((string) $typeRaw);
                    if ($needle !== '') {
                        if (str_contains($needle, 'entrada') || str_contains($needle, 'in')) {
                            $tipo = 'in';
                        } elseif (str_contains($needle, 'salida') || str_contains($needle, 'out')) {
                            $tipo = 'out';
                        }
                    }
                    if ($tipo === null) {
                        $tipo = 'in';
                    }

                    if ($clase === null) {
                        $clase = 'work';
                    }

                }

                // --- UPSERT por llave natural (portal+cliente+empleado+check_time) ---
                $uniq = [
                    'id_portal'   => $portalId,
                    'id_cliente'  => $sucursalId,
                    'id_empleado' => $empId,
                    'check_time'  => $dt,
                ];

                try {
                    $existing = DB::connection($CONN)->table('checadas')->where($uniq)->first();

                    if (! $existing) {
                        // INSERT NUEVO
                        DB::connection($CONN)->table('checadas')->insert([
                            'id_portal'   => $uniq['id_portal'],
                            'id_cliente'  => $uniq['id_cliente'],
                            'id_empleado' => $uniq['id_empleado'],
                            'check_time'  => $uniq['check_time'],
                            'fecha'       => $fecha,
                            'tipo'        => $tipo, // NOT NULL
                            'clase'       => $clase ?? 'work',
                            'dispositivo' => $norm['device'] ?: null,
                            'origen'      => $this->originFromFile($file),
                            'observacion' => null,
                            'hash'        => sha1($portalId . '|' . $sucursalId . '|' . $empId . '|' . $dt),
                            'creado_en'   => now(),
                        ]);
                        $insertados++;
                    } else {
                        // UPDATE EXISTENTE (no pisar con nulls; no usamos 'actualizado_en' pues no existe en la tabla)
                        $update = [
                            'fecha'  => $fecha,
                            'origen' => $this->originFromFile($file),
                        ];
                        if (! empty($norm['device'])) {
                            $update['dispositivo'] = $norm['device'];
                        }

                        if ($tipo !== null) {
                            $update['tipo'] = $tipo;
                        }

                        if ($clase !== null) {
                            $update['clase'] = $clase;
                        }

                        DB::connection($CONN)->table('checadas')->where($uniq)->update($update);
                        $actualizados++;
                    }
                } catch (\Throwable $e) {
                    $errores++;
                    Log::error('[ChecadorImport] error en UPSERT', [
                        'i'    => $idx, 'error' => $e->getMessage(),
                        'uniq' => $uniq, 'dt'   => $dt, 'tipo' => $tipo, 'clase' => $clase,
                    ]);
                    if ($debug && count($why) < $WHY_MAX) {
                        $why[] = ['i' => $idx, 'reason' => 'db_error', 'msg' => $e->getMessage(), 'uniq' => $uniq];
                    }
                }
            }

            Log::info('[ChecadorImport] fin', [
                'insertados'   => $insertados,
                'actualizados' => $actualizados,
                'errores'      => $errores,
            ]);
            if ($debug && $why) {
                Log::debug('[ChecadorImport] detalles de descarte (muestra)', ['why' => array_slice($why, 0, 30)]);
            }

            // ===== Respuesta =====
            if ($insertados === 0 && $actualizados === 0) {
                return response()->json([
                    'ok'    => false,
                    'msg'   => 'Nada importado. Revisa ID/nombre de empleado, fecha/hora y tipo.',
                    'stats' => compact('insertados', 'actualizados', 'errores'),
                    'why'   => $debug ? array_slice($why, 0, 30) : null,
                ], 422);
            }

            return response()->json([
                'ok'    => true,
                'msg'   => $errores ? 'Importado con observaciones' : 'Importación completa',
                'stats' => compact('insertados', 'actualizados', 'errores'),
                'why'   => $debug ? array_slice($why, 0, 30) : null,
            ], $errores ? 207 : 200);
        }
    */
    public function import(Request $req)
    {
        $debug = filter_var($req->input('debug', $req->query('debug', false)), FILTER_VALIDATE_BOOLEAN);
        $CONN  = 'portal_main';

        // ===== Validación =====
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

        // ===== Config =====
        $cfg = $this->parseConfigFromRequest($req);
        if (! is_array($cfg) || empty($cfg)) {
            return response()->json(['ok' => false, 'msg' => 'config_json vacío o inválido'], 422);
        }
        $cfg['datetime']                  = $cfg['datetime'] ?? [];
        $cfg['datetime']['mode']          = $cfg['datetime']['mode'] ?? 'single';
        $cfg['datetime']['excelBase']     = $cfg['datetime']['excelBase'] ?? '1900';
        $cfg['datetime']['offsetMinutes'] = (int) ($cfg['datetime']['offsetMinutes'] ?? 0);
        $cfg['datetime']['format']        = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
        $cfg['datetime']['dateFmt']       = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
        $cfg['datetime']['timeFmt']       = $cfg['datetime']['timeFmt'] ?? 'H:i:s';

        Log::info('[ChecadorImport] cfg columnas', [
            'emp_col'   => $cfg['employee_key']['col'] ?? '',
            'name_col'  => $cfg['employee_name']['col'] ?? '',
            'dt_mode'   => $cfg['datetime']['mode'] ?? '',
            'dt_col'    => $cfg['datetime']['col'] ?? '',
            'dateCol'   => $cfg['datetime']['dateCol'] ?? '',
            'timeCol'   => $cfg['datetime']['timeCol'] ?? '',
            'serialCol' => $cfg['datetime']['serialCol'] ?? '',
            'excelBase' => $cfg['datetime']['excelBase'] ?? '1900',
            'type_col'  => $cfg['type']['col'] ?? '',
        ]);

        // ===== Archivo → filas =====
        $rows    = $this->readFileToRows($file);
        $headers = array_keys($rows[0] ?? []);
        Log::info('[ChecadorImport] archivo leído', ['rows' => count($rows), 'headers' => $headers]);
        if (! count($rows)) {
            return response()->json(['ok' => false, 'msg' => 'Archivo vacío o sin encabezados reconocibles'], 422);
        }

        // ===== Validación previa (muestra) — si existe =====
        if (is_callable([$this, 'validatePreview'])) {
            $sample                   = array_slice($rows, 0, 50);
            [$okPreview, $errPreview] = $this->validatePreview($sample, $cfg);
            if (! $okPreview) {
                Log::warning('[ChecadorImport] validatePreview FALLÓ', ['reason' => $errPreview]);
                return response()->json(['ok' => false, 'msg' => $errPreview], 422);
            }
        } else {
            Log::warning('[ChecadorImport] validatePreview no existe; se omite.');
        }

        // ===== Índices de empleados (por id_empleado "usuario" y por nombre normalizado) =====
        // Deben mapear al PK REAL empleados.id
        [$empByKey, $empByName] = $this->buildEmployeeIndexes($CONN, $portalId, $sucursalId);

        // ===== Bucle principal (UPSERT) =====
        $insertados   = 0;
        $actualizados = 0;
        $errores      = 0;
        $WHY_MAX      = 120;
        $why          = [];

        // Grupos tocados (empId => [fecha => true]) y fechas del archivo
        $touched     = [];
        $datesInFile = []; // set de 'Y-m-d'

        foreach ($rows as $idx => $row) {
            $norm = $this->normalizeRow($row, $cfg);

            // --- Resolver empleado ---
            $ek    = trim((string) ($norm['employee_key'] ?? ''));
            $empId = null;

            if ($ek !== '' && isset($empByKey[$ek])) {
                $empId = (int) $empByKey[$ek]; // PK real: empleados.id
            }
            if (! $empId && ! empty($cfg['employee_name']['col'])) {
                $nombreRaw = (string) ($row[$cfg['employee_name']['col']] ?? '');
                if ($nombreRaw !== '') {
                    $nomKey = $this->normalizeName($nombreRaw);
                    $empId  = isset($empByName[$nomKey]) ? (int) $empByName[$nomKey] : null;
                }
            }
            if (! $empId) {
                $errores++;
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = ['i' => $idx, 'reason' => 'empleado_no_encontrado', 'ek' => $ek, 'name' => ($row[$cfg['employee_name']['col'] ?? ''] ?? null)];
                }
                continue;
            }

            // --- datetime ---
            $dt = trim((string) ($norm['datetime'] ?? ''));
            if ($dt === '') {
                $errores++;
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = ['i' => $idx, 'reason' => 'sin_datetime', 'raw_dt' => $this->whyRawDatetime($row, $cfg)];
                }
                continue;
            }
            $fecha               = substr($dt, 0, 10);
            $datesInFile[$fecha] = true; // set

            // --- tipo/clase tolerante ---
            $typeCol        = $cfg['type']['col'] ?? '';
            $typeRaw        = $typeCol ? (string) ($row[$typeCol] ?? '') : ($norm['type_raw'] ?? '');
            $typeNor        = $this->mapTypeTolerant((string) $typeRaw, $cfg['type']['dict'] ?? []);
            [$tipo, $clase] = $this->mapTipoYClase($typeNor, $typeRaw);

            if ($tipo === null) {
                $needle = $this->normalizeText((string) $typeRaw);
                if ($needle !== '') {
                    if (str_contains($needle, 'entrada') || str_contains($needle, 'in')) {
                        $tipo = 'in';
                    } elseif (str_contains($needle, 'salida') || str_contains($needle, 'out')) {
                        $tipo = 'out';
                    }
                }
                if ($tipo === null) {
                    $tipo = 'in';
                }

                if ($clase === null) {
                    $clase = 'work';
                }

            }

            // --- UPSERT por llave natural (portal+cliente+empleado+check_time) ---
            $uniq = [
                'id_portal'   => $portalId,
                'id_cliente'  => $sucursalId,
                'id_empleado' => $empId, // PK real
                'check_time'  => $dt,
            ];

            try {
                $existing = DB::connection($CONN)->table('checadas')->where($uniq)->first();

                if (! $existing) {
                    DB::connection($CONN)->table('checadas')->insert([
                        'id_portal'   => $uniq['id_portal'],
                        'id_cliente'  => $uniq['id_cliente'],
                        'id_empleado' => $uniq['id_empleado'],
                        'check_time'  => $uniq['check_time'],
                        'fecha'       => $fecha,
                        'tipo'        => $tipo,
                        'clase'       => $clase ?? 'work',
                        'dispositivo' => $norm['device'] ?: null,
                        'origen'      => $this->originFromFile($file),
                        'observacion' => null,
                        'hash'        => sha1($portalId . '|' . $sucursalId . '|' . $empId . '|' . $dt),
                        'creado_en'   => now(),
                    ]);
                    $insertados++;
                } else {
                    $update = [
                        'fecha'  => $fecha,
                        'origen' => $this->originFromFile($file),
                    ];
                    if (! empty($norm['device'])) {
                        $update['dispositivo'] = $norm['device'];
                    }
                    if ($tipo !== null) {
                        $update['tipo'] = $tipo;
                    }

                    if ($clase !== null) {
                        $update['clase'] = $clase;
                    }

                    DB::connection($CONN)->table('checadas')->where($uniq)->update($update);
                    $actualizados++;
                }

                // Marcar grupo tocado para evaluación
                $touched[$empId][$fecha] = true;

            } catch (\Throwable $e) {
                $errores++;
                Log::error('[ChecadorImport] error en UPSERT', [
                    'i'    => $idx, 'error' => $e->getMessage(),
                    'uniq' => $uniq, 'dt'   => $dt, 'tipo' => $tipo, 'clase' => $clase,
                ]);
                if ($debug && count($why) < $WHY_MAX) {
                    $why[] = ['i' => $idx, 'reason' => 'db_error', 'msg' => $e->getMessage(), 'uniq' => $uniq];
                }
            }
        }

                                           // ===== Post-proceso: evaluar TODOS los empleados activos en TODAS las fechas del archivo =====
        $dates = array_keys($datesInFile); // fechas presentes en el archivo
        if (! empty($dates)) {
            // 1) Empleados activos (status=1) de la sucursal
            $activos = DB::connection($CONN)->table('empleados')
                ->where('id_portal', $portalId)
                ->where('id_cliente', $sucursalId)
                ->where('status', 1)
                ->pluck('id') // PK real
                ->all();

                               // 2) Construir grupos únicos emp-fecha
            $groupsAssoc = []; // "empId|fecha" => true
            $groups      = [];

            // a) ya tocados por el archivo
            foreach ($touched as $empId => $fechas) {
                foreach (array_keys($fechas) as $f) {
                    $key = $empId . '|' . $f;
                    if (! isset($groupsAssoc[$key])) {
                        $groupsAssoc[$key] = true;
                        $groups[]          = [
                            'portalId'   => $portalId,
                            'clienteId'  => $sucursalId,
                            'empleadoId' => (int) $empId,
                            'fecha'      => $f,
                        ];
                    }
                }
            }

            // b) todos los activos en todas las fechas del archivo
            foreach ($activos as $empId) {
                $empId = (int) $empId;
                foreach ($dates as $f) {
                    $key = $empId . '|' . $f;
                    if (! isset($groupsAssoc[$key])) {
                        $groupsAssoc[$key] = true;
                        $groups[]          = [
                            'portalId'   => $portalId,
                            'clienteId'  => $sucursalId,
                            'empleadoId' => $empId,
                            'fecha'      => $f,
                        ];
                    }
                }
            }

            // 3) Evaluar en lote con la MISMA conexión
            if (! empty($groups)) {
                try {
                    /** @var \App\Services\Asistencia\AsistenciaServicio $svc */
                    $svc     = app(\App\Services\Asistencia\AsistenciaServicio::class)->withConnection($CONN);
                    $results = $svc->evaluateBatch($groups);
                    Log::info('[ChecadorImport] evaluación aplicada', [
                        'empleados_activos' => count($activos),
                        'fechas'            => count($dates),
                        'grupos'            => count($groups),
                        'results'           => $results,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[ChecadorImport] fallo evaluando asistencia', ['error' => $e->getMessage()]);
                }
            }
        }

        Log::info('[ChecadorImport] fin', [
            'insertados'   => $insertados,
            'actualizados' => $actualizados,
            'errores'      => $errores,
        ]);
        if ($debug && $why) {
            Log::debug('[ChecadorImport] detalles de descarte (muestra)', ['why' => array_slice($why, 0, 30)]);
        }

        // ===== Respuesta =====
        if ($insertados === 0 && $actualizados === 0) {
            return response()->json([
                'ok'    => false,
                'msg'   => 'Nada importado. Revisa ID/nombre de empleado, fecha/hora y tipo.',
                'stats' => compact('insertados', 'actualizados', 'errores'),
                'why'   => $debug ? array_slice($why, 0, 30) : null,
            ], 422);
        }

        return response()->json([
            'ok'    => true,
            'msg'   => $errores ? 'Importado con observaciones' : 'Importación completa',
            'stats' => compact('insertados', 'actualizados', 'errores'),
            'why'   => $debug ? array_slice($why, 0, 30) : null,
        ], $errores ? 207 : 200);
    }

    /** ========================= HELPERS ========================= */

    /** Lee config_json soportando string, array o archivo (Blob) */
    private function parseConfigFromRequest(Request $req): array
    {
        $rawInput = $req->input('config_json');
        if (! $rawInput && $req->hasFile('config_json')) {
            try { $rawInput = file_get_contents($req->file('config_json')->getRealPath());} catch (\Throwable $e) {$rawInput = null;}
        }
        if (is_array($rawInput)) {
            $raw = $rawInput;
        } elseif (is_string($rawInput)) {
            $raw = json_decode($rawInput, true);
        } else {
            $raw = null;
        }
        if (is_array($raw) && array_key_exists('map', $raw) && is_array($raw['map'])) {
            return $raw['map']; // venía { map: {...} }
        }
        return is_array($raw) ? $raw : [];
    }

    /** Normalización de NOMBRE para indexar/buscar: minúsculas, sin acentos y espacios colapsados */
    private function normalizeName(string $s): string
    {
        $s = trim(mb_strtolower($s));
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    /** Normaliza clave de tipo (quita acentos, espacios, signos) */
    private function normalizeTypeKey(string $s): string
    {
        $s = $this->normalizeName($s);
        $s = preg_replace('/[^a-z0-9]+/u', '', $s);
        return $s;
    }

    /** Map de tipo tolerante (match exacto, case-insensitive, y normalizado) */
    private function mapTypeTolerant(?string $raw, array $dict): ?string
    {
        $raw = (string) ($raw ?? '');
        if ($raw === '') {
            return null;
        }

        // match exacto
        if (array_key_exists($raw, $dict)) {
            return $dict[$raw];
        }

        // normalizado
        $needle = $this->n($raw);
        foreach ($dict as $k => $v) {
            if ($this->n((string) $k) === $needle) {
                return $v;
            }

        }

        // heurística simple: "entrada"/"salida"
        if (str_contains($needle, 'entrada')) {
            return 'entrada';
        }

        if (str_contains($needle, 'salida')) {
            return 'salida';
        }

        return null;
    }

    /** Construye índices de empleados: por id_empleado (TRIM) y por nombre completo */
/** Precarga empleados de la sucursal para resolver rápido por id_empleado o por nombre completo. */
// Normaliza texto (minúsculas, sin acentos, espacios colapsados)
    private function n(string $s): string
    {
        $s = trim(mb_strtolower($s));
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s2 = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($s2 !== false) {
                $s = $s2;
            }

        }
        return preg_replace('/\s+/u', ' ', $s);
    }

// Carga una sola vez los empleados de la sucursal y arma 2 mapas:
//  byKey[ id_empleado_trim ] = empleado
//  byName[ nombre_normalizado ] = empleado
    private function buildEmployeeIndexes(string $conn, int $portalId, int $sucursalId): array
    {
        $rows = DB::connection($conn)->table('empleados')
            ->select('id', 'id_empleado', 'nombre', 'paterno', 'materno')
            ->where('id_portal', $portalId)
            ->where('id_cliente', $sucursalId)
            ->get();

        $byKey  = []; // id_empleado -> id
        $byName = []; // nombre normalizado -> id

        foreach ($rows as $e) {
            $id  = (int) $e->id;
            $key = trim((string) ($e->id_empleado ?? ''));
            if ($key !== '') {
                $byKey[$key] = $id;
            }

            $nombre  = trim((string) ($e->nombre ?? ''));
            $paterno = trim((string) ($e->paterno ?? ''));
            $materno = trim((string) ($e->materno ?? ''));

            // 👇 ignora placeholders
            if ($materno === '.' || $materno === '-' || $materno === '_') {
                $materno = '';
            }

            // guarda dos variantes: con y sin materno
            $full = trim("$nombre $paterno $materno");
            $two  = trim("$nombre $paterno");

            if ($full !== '') {
                $byName[$this->normalizeName($full)] = $id;
            }

            if ($two !== '') {
                $byName[$this->normalizeName($two)] = $id;
            }

        }

        return [$byKey, $byName];
    }

    private function normalizeText(string $s): string
    {
        $s = trim(mb_strtolower($s));
        if (class_exists('\Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        }
        return preg_replace('/\s+/u', ' ', $s);
    }

    /** Hint compacto para logs */
    private function whyHint(array $row, array $cfg)
    {
        return [
            'emp_col'  => $cfg['employee_key']['col'] ?? null,
            'emp_val'  => ($cfg['employee_key']['col'] ?? null) ? ($row[$cfg['employee_key']['col']] ?? null) : null,
            'name_col' => $cfg['employee_name']['col'] ?? null,
            'name_val' => ($cfg['employee_name']['col'] ?? null) ? ($row[$cfg['employee_name']['col']] ?? null) : null,
            'dt_mode'  => $cfg['datetime']['mode'] ?? null,
            'dt_val'   => $this->whyRawDatetime($row, $cfg),
            'type_col' => $cfg['type']['col'] ?? null,
            'type_val' => ($cfg['type']['col'] ?? null) ? ($row[$cfg['type']['col']] ?? null) : null,
        ];
    }

    /** Validación de muestra: acepta EK o Nombre + fecha/hora */
    private function validatePreviewAcceptingName(array $sample, array $cfg, array $empByName): array
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

            $hasEmp = (trim((string) ($n['employee_key'] ?? '')) !== '');
            if (! $hasEmp) {
                $nameCol = $cfg['employee_name']['col'] ?? null;
                $nameVal = $nameCol ? trim((string) ($row[$nameCol] ?? '')) : '';
                if ($nameVal !== '') {
                    $hasEmp = isset($empByName[$this->normalizeName($nameVal)]);
                }
            }

            if ($hasEmp && ($n['datetime'] ?? '') !== '') {
                $ok++;
            } elseif (count($fails) < 10) {
                $fails[] = [
                    'name'     => ($cfg['employee_name']['col'] ?? null) ? ($row[$cfg['employee_name']['col']] ?? null) : null,
                    'fechaRaw' => $this->whyRawDatetime($row, $cfg),
                ];
            }
        }

        $rate = $ok / max(1, $total);
        if ($rate < 0.7) {
            $lines = array_map(function ($f) {
                $name = $f['name'] ?: '(sin nombre)';
                $val  = is_scalar($f['fechaRaw']) ? (string) $f['fechaRaw'] : json_encode($f['fechaRaw']);
                return "• {$name} | fecha=\"{$val}\"";
            }, $fails);
            $msg = "Sin vista previa\nAjusta columnas, formatos o sucursal.\n\nDiagnóstico:\n\n"
            . "Filas OK: {$ok}/{$total}\nEjemplos (máx " . count($lines) . "):\n\n"
            . implode("\n", $lines);
            return [false, $msg];
        }
        return [true, null];
    }

    /** ---------- Normalización fila (back en espejo del front) ---------- */
    private function normalizeRow(array $row, array $cfg): array
    {
        // ID empleado
        $ekCol       = $cfg['employee_key']['col'] ?? null;
        $employeeKey = $ekCol ? trim((string) ($row[$ekCol] ?? '')) : '';

        // Fecha/hora
        $mode      = $cfg['datetime']['mode'] ?? 'single';
        $offset    = (int) ($cfg['datetime']['offsetMinutes'] ?? 0);
        $excelBase = $cfg['datetime']['excelBase'] ?? '1900';
        $dtStr     = '';

        if ($mode === 'single') {
            $col = $cfg['datetime']['col'] ?? null;
            $fmt = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
            $raw = (string) ($row[$col] ?? '');

            // Si es numérico → serial Excel
            if ($raw !== '' && is_numeric($raw)) {
                $dtStr = $this->fromExcelSerialToYmdHms($raw, $excelBase);
            }
            if ($dtStr === '') {
                $dtStr = $this->parseDateTimeTolerant($raw, $fmt, $excelBase);
            }
        } elseif ($mode === 'split') {
            $dcol = $cfg['datetime']['dateCol'] ?? null;
            $tcol = $cfg['datetime']['timeCol'] ?? null;
            $dfmt = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
            $tfmt = $cfg['datetime']['timeFmt'] ?? 'H:i:s';
            $d    = $this->parseDate($row[$dcol] ?? '', $dfmt);
            $t    = $this->parseTime($row[$tcol] ?? '', $tfmt);
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

        // Tipo (tolerante)
        $type    = null;
        $typeRaw = null;
        if (! empty($cfg['type']['col'])) {
            $typeRaw = trim((string) ($row[$cfg['type']['col']] ?? ''));
            $dict    = $cfg['type']['dict'] ?? [];
            $type    = $this->mapTypeTolerant($typeRaw, $dict);
        }

        // Dispositivo (opcional)
        $device = ! empty($cfg['device']['col']) ? trim((string) ($row[$cfg['device']['col']] ?? '')) : '';

        return [
            'employee_key' => $employeeKey,
            'datetime'     => $dtStr,
            'type'         => $type ?: null,
            'type_raw'     => $typeRaw ?: null,
            'device'       => $device ?: null,
            'raw'          => $row,
        ];
    }

    /** ---------- Parser tolerante (AM/PM español + serial si aplica) ---------- */
    private function normalizeAmPm(string $s): string
    {
        // "02:31:56 p. m." -> "02:31:56 PM"
        $s = preg_replace('/\s*a\.?\s*m\.?/iu', ' AM', $s);
        $s = preg_replace('/\s*p\.?\s*m\.?/iu', ' PM', $s);
        return trim($s);
    }

    private function parseDateTimeTolerant(string $val, string $fmt, string $excelBase = '1900'): string
    {
        $val = trim($val);
        if ($val === '') {
            return '';
        }

        if (is_numeric($val)) {
            return $this->fromExcelSerialToYmdHms((float) $val, $excelBase);
        }

        $v  = $this->normalizeAmPm($val);
        $ts = \DateTime::createFromFormat($fmt, $v);
        if ($ts instanceof \DateTime) {
            return $ts->format('Y-m-d H:i:s');
        }

        $fallbacks = [
            'Y-m-d H:i:s', 'Y-m-d H:i',
            'd/m/Y H:i:s', 'd/m/Y H:i',
            'm/d/Y H:i:s', 'm/d/Y H:i',
            'd/m/Y h:i:s A', 'd/m/Y h:i A',
            'm/d/Y h:i:s A', 'm/d/Y h:i A',
            'Y-m-d\TH:i:s', 'Y-m-d\TH:i',
        ];
        foreach ($fallbacks as $f) {
            $ts = \DateTime::createFromFormat($f, $v);
            if ($ts instanceof \DateTime) {
                return $ts->format('Y-m-d H:i:s');
            }

        }
        $t = strtotime($v);
        return $t !== false ? date('Y-m-d H:i:s', $t) : '';
    }

    private function parseDate($val, string $fmt): string
    {
        $val = is_scalar($val) ? (string) $val : '';
        $ts  = \DateTime::createFromFormat($fmt, $val);
        if ($ts instanceof \DateTime) {
            return $ts->format('Y-m-d');
        }

        $t = strtotime($this->normalizeAmPm($val));
        return $t !== false ? date('Y-m-d', $t) : '';
    }

    private function parseTime($val, string $fmt): string
    {
        $val = is_scalar($val) ? (string) $val : '';
        $ts  = \DateTime::createFromFormat($fmt, $this->normalizeAmPm($val));
        if ($ts instanceof \DateTime) {
            return $ts->format('H:i:s');
        }

        $t = strtotime($this->normalizeAmPm($val));
        return $t !== false ? date('H:i:s', $t) : '';
    }

    private function phpFormat(string $fmt): string
    {return $fmt;}

    /** Lectura CSV/XLSX → filas asociativas */
    private function readFileToRows($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx', 'xls'])) {
            $spreadsheet = IOFactory::load($file->getPathname());
            $ws          = $spreadsheet->getSheet(0);
            return $this->sheetToAssoc($ws);
        }

        $rows = [];
        if (($h = fopen($file->getPathname(), 'r')) !== false) {
            $firstLine = fgets($h);
            rewind($h);
            $delim   = $this->guessDelimiter((string) $firstLine);
            $headers = null;
            while (($data = fgetcsv($h, 0, $delim)) !== false) {
                if ($headers === null) {$headers = $this->cleanHeaders($data);
                    continue;}
                $rows[] = @array_combine($headers, $data) ?: [];
            }
            fclose($h);
        }
        return $rows;
    }

    private function sheetToAssoc(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
    {
        $rows = $ws->toArray(null, true, true, true);
        if (count($rows) < 2) {
            return [];
        }

        $headers = $this->cleanHeaders(array_values($rows[1]));
        $out     = [];
        for ($r = 2; $r <= count($rows); $r++) {
            $row = $rows[$r];if (! $row) {
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
    /** Devuelve "excel" o "csv" según la extensión del archivo subido. */
    private function originFromFile($file): string
    {
        try { $ext = strtolower($file->getClientOriginalExtension() ?: '');} catch (\Throwable $e) {$ext = '';}
        return in_array($ext, ['xlsx', 'xls'], true) ? 'excel' : 'csv';
    }

    private function mapTipoYClase(?string $normType, ?string $rawType): array
    {
        $norm = (string) ($normType ?? '');
        $raw  = $this->n((string) $rawType);

        $looksMeal = str_contains($raw, 'lunch') || str_contains($raw, 'comida') || str_contains($raw, 'meal') || str_contains($raw, 'almuerzo');

        switch ($norm) {
            case 'entrada':return ['in', 'work'];
            case 'salida':return ['out', 'work'];
            case 'descanso_entrada':return ['out', $looksMeal ? 'meal' : 'break'];
            case 'descanso_salida':return ['in', $looksMeal ? 'meal' : 'break'];
            default: return [null, null];
        }
    }

    private function fromExcelSerialToYmdHms($num, string $base = '1900'): string
    {
        $n = (float) $num;if ($n <= 0) {
            return '';
        }

        $epochStr = ($base === '1904') ? '1904-01-01 00:00:00' : '1899-12-30 00:00:00';
        $dt       = \DateTime::createFromFormat('Y-m-d H:i:s', $epochStr, new \DateTimeZone('UTC'));
        $seconds  = (int) floor($n * 86400);
        $dt->modify('+' . $seconds . ' seconds');
        return $dt->format('Y-m-d H:i:s');
    }

    private function cleanHeaders(array $headers): array
    {
        return array_map(function ($h) {
            $h = (string) $h;
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // BOM
            return trim($h);
        }, $headers);
    }
    // === Añade esto dentro de ChecadorController ===

/** Valida una muestra antes de importar.
 * Acepta que falte employee_key si viene nombre; exige fecha/hora parseada. */
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

            $hasEmpKey = ! empty($n['employee_key']);
            $nameCol   = $cfg['employee_name']['col'] ?? '';
            $hasName   = $nameCol !== '' && ! empty($row[$nameCol]);
            $hasDT     = ! empty($n['datetime']);

            if (($hasEmpKey || $hasName) && $hasDT) {
                $ok++;
            } elseif (count($fails) < 10) {
                $fails[] = [
                    'emp_key'  => $hasEmpKey ? $n['employee_key'] : null,
                    'name'     => $hasName ? (string) $row[$nameCol] : null,
                    'fechaRaw' => $this->whyRawDatetime($row, $cfg),
                ];
            }
        }

        $rate = $ok / max(1, $total);
        if ($rate < 0.5) {
            // Mensaje compacto para ver rápido por qué falla
            return [false, 'Formato de columnas/fechas no reconocido. OK: ' . $ok . '/' . $total . '. Ejemplos: ' . json_encode($fails)];
        }
        return [true, null];
    }

/** Devuelve el valor “crudo” de fecha/hora según el modo declarado, para diagnóstico. */
    private function whyRawDatetime(array $row, array $cfg)
    {
        $mode = $cfg['datetime']['mode'] ?? 'single';
        if ($mode === 'single') {
            $c = $cfg['datetime']['col'] ?? '';
            return $c !== '' ? ($row[$c] ?? null) : null;
        }
        if ($mode === 'excel_serial') {
            $c = $cfg['datetime']['serialCol'] ?? '';
            return $c !== '' ? ($row[$c] ?? null) : null;
        }
        // split
        $dc = $cfg['datetime']['dateCol'] ?? '';
        $tc = $cfg['datetime']['timeCol'] ?? '';
        return trim(($dc !== '' ? ($row[$dc] ?? '') : '') . ' ' . ($tc !== '' ? ($row[$tc] ?? '') : ''));
    }

}
