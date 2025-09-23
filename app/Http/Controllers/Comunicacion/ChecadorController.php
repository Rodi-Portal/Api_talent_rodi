<?php

namespace App\Http\Controllers\Comunicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Si vas a aceptar XLSX instala PhpSpreadsheet:
// composer require phpoffice/phpspreadsheet
use PhpOffice\PhpSpreadsheet\IOFactory;

class ChecadorController extends Controller
{
    public function indexMappings(Request $req)
    {
        $portalId   = (int) $req->query('portal_id');
        $sucursalId = $req->query('sucursal_id');

        $q = DB::table('checador_mappings')->where('portal_id', $portalId);
        if ($sucursalId !== null && $sucursalId !== '') {
            $q->where('sucursal_id', $sucursalId);
        }
        return response()->json($q->orderBy('activo','desc')->orderBy('id','desc')->get());
    }

    public function storeMapping(Request $req)
    {
        $data = $req->validate([
            'portal_id'           => 'required|integer',
            'sucursal_id'         => 'nullable|integer',
            'nombre'              => 'required|string|max:120',
            'headers_fingerprint' => 'nullable|string|max:255',
            'config_json'         => 'required', // objeto JSON
        ]);

        $id = DB::table('checador_mappings')->insertGetId([
            'portal_id'           => $data['portal_id'],
            'sucursal_id'         => $data['sucursal_id'] ?? null,
            'nombre'              => $data['nombre'],
            'headers_fingerprint' => $data['headers_fingerprint'] ?? null,
            'config_json'         => json_encode($data['config_json']),
            'activo'              => 1,
            'creado_en'           => now(),
            'actualizado_en'      => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function import(Request $req)
    {
        // 1) Validación básica
        $req->validate([
            'portal_id'   => 'required|integer',
            'sucursal_id' => 'nullable|integer',
            'file'        => 'required|file|mimes:csv,txt,xlsx,xls',
            'config_json' => 'required', // el objeto del mapper del front
        ]);

        $portalId   = (int) $req->input('portal_id');
        $sucursalId = $req->input('sucursal_id') ? (int) $req->input('sucursal_id') : null;
        $config     = json_decode($req->input('config_json'), true);
        $file       = $req->file('file');

        // 2) Leer archivo → arreglo de filas asociativas
        $rows = $this->readFileToRows($file);

        // 3) Normalizar y validar muestra
        $sample = array_slice($rows, 0, 20);
        [$okPreview, $errPreview] = $this->validatePreview($sample, $config);
        if (!$okPreview) {
            return response()->json(['ok' => false, 'msg' => $errPreview], 422);
        }

        // 4) Proceso de inserción
        $insertados = 0; $duplicados = 0; $errores = 0;

        foreach ($rows as $i => $row) {
            $norm = $this->normalizeRow($row, $config); // employee_key, datetime, type, device, branch_key

            if (!$norm['employee_key'] || !$norm['datetime']) { $errores++; continue; }

            // Resolver empleado por alias (scope portal)
            $aliasQ = DB::table('empleado_aliases')
                ->where('portal_id', $portalId)
                ->where('alias_value', $norm['employee_key']);
            // si quisieras usar device:
            // if ($norm['device']) $aliasQ->where(function($q) use ($norm) {
            //     $q->whereNull('device_id')->orWhere('device_id', $norm['device']);
            // });
            $alias = $aliasQ->first();

            if (!$alias) { $errores++; continue; } // si quieres permitir “pendiente de mapeo”, aquí lo decides

            $idEmpleado = (int) $alias->empleado_id;

            // Partes de fecha/hora
            $dt = $norm['datetime']; // "YYYY-MM-DD HH:mm:ss"
            $fecha = substr($dt, 0, 10);

            // Hash idempotente por portal
            $base = $idEmpleado.'|'.$dt.'|'.($norm['type'] ?: 'NULL');
            $hash = sha1($base);

            try {
                DB::table('checadas')->insert([
                    'portal_id'   => $portalId,
                    'sucursal_id' => $sucursalId ?? null,
                    'id_empleado' => $idEmpleado,
                    'fecha'       => $fecha,
                    'check_time'  => $dt,
                    'tipo'        => $norm['type'] ?: null,
                    'dispositivo' => $norm['device'] ?: null,
                    'origen'      => $this->originFromFile($file),
                    'observacion' => null,
                    'hash'        => $hash,
                    'creado_en'   => now(),
                ]);
                $insertados++;
            } catch (\Throwable $e) {
                // Duplicado (unique portal_id+hash) u otro error
                if (Str::contains(strtolower($e->getMessage()), ['duplicate', 'unique'])) {
                    $duplicados++;
                } else {
                    $errores++;
                }
            }
        }

        return response()->json([
            'ok' => true,
            'msg' => "Importación completa",
            'stats' => compact('insertados','duplicados','errores')
        ]);
    }

    /* ------------ Helpers ------------- */

    private function originFromFile($file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        return in_array($ext, ['xlsx','xls']) ? 'excel' : 'csv';
    }

    private function readFileToRows($file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (in_array($ext, ['xlsx','xls'])) {
            $spreadsheet = IOFactory::load($file->getPathname());
            $ws = $spreadsheet->getSheet(0);
            return $this->sheetToAssoc($ws);
        }
        // CSV / TXT
        $rows = [];
        if (($h = fopen($file->getPathname(), 'r')) !== false) {
            $headers = null;
            while (($data = fgetcsv($h, 0, ',')) !== false) {
                if ($headers === null) { $headers = $data; continue; }
                $rows[] = array_combine($headers, $data);
            }
            fclose($h);
        }
        return $rows;
    }

    private function sheetToAssoc(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
    {
        $rows = $ws->toArray(null, true, true, true); // con indices de columna
        if (count($rows) < 2) return [];
        // primera fila = headers
        $headers = array_map('trim', array_values($rows[1]));
        $out = [];
        for ($r = 2; $r <= count($rows); $r++) {
            $row = $rows[$r];
            if (!$row) continue;
            $assoc = [];
            $i = 0;
            foreach ($row as $col => $val) {
                $hdr = $headers[$i] ?? ('Col'.$i);
                $assoc[$hdr] = $val;
                $i++;
            }
            $out[] = $assoc;
        }
        return $out;
    }

    private function validatePreview(array $sample, array $cfg): array
    {
        if (!$sample) return [false, 'Archivo vacío'];
        $ok = 0; $total = 0;
        foreach ($sample as $row) {
            $n = $this->normalizeRow($row, $cfg);
            $total++;
            if ($n['employee_key'] && $n['datetime']) $ok++;
        }
        $rate = $ok / max(1,$total);
        if ($rate < 0.7) return [false, 'Formato de fecha/hora o columnas no reconocido'];
        return [true, null];
    }

    private function normalizeRow(array $row, array $cfg): array
    {
        // employee_key
        $ekCol = $cfg['employee_key']['col'] ?? null;
        $employeeKey = $ekCol ? trim((string)($row[$ekCol] ?? '')) : '';

        // datetime
        $dtStr = '';
        $mode  = $cfg['datetime']['mode'] ?? 'single';
        if ($mode === 'single') {
            $col = $cfg['datetime']['col'] ?? null;
            $fmt = $cfg['datetime']['format'] ?? 'Y-m-d H:i:s';
            $dtStr = $this->parseDateTime((string)($row[$col] ?? ''), $fmt);
        } elseif ($mode === 'split') {
            $dcol = $cfg['datetime']['dateCol'] ?? null;
            $tcol = $cfg['datetime']['timeCol'] ?? null;
            $dfmt = $cfg['datetime']['dateFmt'] ?? 'Y-m-d';
            $tfmt = $cfg['datetime']['timeFmt'] ?? 'H:i:s';
            $d = $this->parseDate((string)($row[$dcol] ?? ''), $dfmt);
            $t = $this->parseTime((string)($row[$tcol] ?? ''), $tfmt);
            if ($d && $t) $dtStr = $d.' '.$t;
        } elseif ($mode === 'excel_serial') {
            $scol = $cfg['datetime']['serialCol'] ?? null;
            $dtStr = $this->fromExcelSerialToYmdHms($row[$scol] ?? null);
        }

        // type
        $type = '';
        if (!empty($cfg['type']['col'])) {
            $raw = trim((string)($row[$cfg['type']['col']] ?? ''));
            $dict = $cfg['type']['dict'] ?? [];
            $type = $dict[$raw] ?? '';
        }

        // device & branch_key
        $device = !empty($cfg['device']['col']) ? trim((string)($row[$cfg['device']['col']] ?? '')) : '';
        $branch = !empty($cfg['branch_key']['col']) ? trim((string)($row[$cfg['branch_key']['col']] ?? '')) : '';

        return [
            'employee_key' => $employeeKey,
            'datetime'     => $dtStr, // "YYYY-MM-DD HH:mm:ss"
            'type'         => $type ?: null,
            'device'       => $device ?: null,
            'branch_key'   => $branch ?: null,
            'raw'          => $row,
        ];
    }

    private function parseDateTime(string $val, string $fmt): string
    {
        // soporta formatos comunes; si falla, intenta strtotime/DateTime
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val) ?: (strtotime($val) ? new \DateTime($val) : null);
        return $ts ? $ts->format('Y-m-d H:i:s') : '';
    }
    private function parseDate(string $val, string $fmt): string
    {
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val) ?: (strtotime($val) ? new \DateTime($val) : null);
        return $ts ? $ts->format('Y-m-d') : '';
    }
    private function parseTime(string $val, string $fmt): string
    {
        $ts = \DateTime::createFromFormat($this->phpFormat($fmt), $val) ?: (strtotime($val) ? new \DateTime($val) : null);
        return $ts ? $ts->format('H:i:s') : '';
    }
    private function phpFormat(string $fmt): string
    {
        // Y-m-d H:i:s, d/m/Y, H:i, etc. (ya está en formato PHP, por si vienes del front)
        return $fmt;
    }
    private function fromExcelSerialToYmdHms($num): string
    {
        $n = (float) $num;
        if ($n <= 0) return '';
        // Excel epoch 1899-12-30
        $base = \DateTime::createFromFormat('Y-m-d H:i:s', '1899-12-30 00:00:00', new \DateTimeZone('UTC'));
        $secs = (int) round($n * 86400);
        $base->modify("+{$secs} seconds");
        return $base->format('Y-m-d H:i:s');
    }
}
